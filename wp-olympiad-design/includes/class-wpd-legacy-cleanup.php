<?php
/**
 * 2.2.0: регистрация участников и личный кабинет (2.0.0–2.1.0) убраны из плагина.
 * Этот класс один раз удаляет их данные: заявки (wpd_application), аккаунты участников (роль wpd_participant)
 * вместе с ролью, настройки регистрации, счётчик номеров, временные данные и мета-поля.
 *
 * Удаление идёт порциями по BATCH записей за запрос (на init), чтобы большой список заявок не упёрся в лимит времени.
 * Когда всё удалено, ставится отметка DONE_OPTION и класс больше ничего не делает.
 * Страницы, где остались шорткоды или блоки формы и кабинета, сайт не правит сам — администратор видит их список.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class WPD_Legacy_Cleanup
{
    const DONE_OPTION  = 'wpd_reg_removed';
    const PAGES_OPTION = 'wpd_reg_removed_pages';
    const LOCK         = 'wpd_cleanup_lock'; // не wpd_reg_*: такие временные данные очистка удаляет
    const BATCH        = 200;
    const CPT          = 'wpd_application';
    const ROLE         = 'wpd_participant';
    const MARKERS      = ['[wpd_registration', '[wpd_cabinet', '<!-- wp:wpd/registration', '<!-- wp:wpd/cabinet'];

    public static function init(): void
    {
        add_action('init', [__CLASS__, 'maybe_run'], 20);
        add_action('admin_notices', [__CLASS__, 'notice']);
        add_action('admin_post_wpd_reg_pages_dismiss', [__CLASS__, 'dismiss']);
    }

    public static function is_done(): bool
    {
        return (bool) get_option(self::DONE_OPTION);
    }

    /** На каждом запросе, пока очистка не завершена: одна порция под блокировкой. */
    public static function maybe_run(): void
    {
        if (self::is_done() || get_transient(self::LOCK)) {
            return;
        }
        set_transient(self::LOCK, 1, 2 * MINUTE_IN_SECONDS);
        $done = self::run(self::BATCH);
        delete_transient(self::LOCK);
        if ($done) {
            update_option(self::DONE_OPTION, WPD_VERSION);
        }
    }

    /**
     * Удаляет не больше $limit заявок и $limit аккаунтов. Возвращает true, когда удалять больше нечего
     * и удалены настройки, роль и временные данные. $limit = 0 — всё за один раз (при удалении плагина).
     */
    public static function run(int $limit = self::BATCH): bool
    {
        $number = $limit > 0 ? $limit : -1;

        // Заявки (тип записи уже не зарегистрирован — запрос по имени типа работает и так)
        $apps = get_posts([
            'post_type'        => self::CPT,
            'post_status'      => ['publish', 'private', 'draft', 'pending', 'future', 'trash', 'auto-draft', 'inherit'],
            'numberposts'      => $number,
            'fields'           => 'ids',
            'suppress_filters' => true,
            'no_found_rows'    => true,
        ]);
        foreach ($apps as $id) {
            wp_delete_post((int) $id, true);
        }

        // Аккаунты участников
        $users = get_users(['role' => self::ROLE, 'number' => $number, 'fields' => 'ID', 'count_total' => false]);
        if ($users) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            $reassign = self::reassign_to();
            foreach ($users as $uid) {
                self::remove_participant((int) $uid, $reassign);
            }
        }
        if ($limit > 0 && (count($apps) >= $limit || count($users) >= $limit)) {
            return false; // остальное — следующими запросами
        }

        delete_option('wpd_registration');
        delete_option('wpd_reg_counter');
        self::delete_transients();
        foreach (['wpd_own_password', 'manageedit-' . self::CPT . 'columnshidden', 'edit_' . self::CPT . '_per_page'] as $key) {
            delete_metadata('user', 0, $key, '', true);
        }
        remove_role(self::ROLE);
        self::find_pages();
        return true;
    }

    /**
     * Участника удаляем; если у пользователя есть и другие роли (его повысили вручную) или он может
     * редактировать записи, удаляем только роль участника — аккаунт остаётся.
     */
    private static function remove_participant(int $uid, int $reassign): void
    {
        $user = get_userdata($uid);
        if (!$user) {
            return;
        }
        $other_roles = array_diff((array) $user->roles, [self::ROLE]);
        if ($other_roles || $uid === get_current_user_id() || user_can($user, 'edit_posts')) {
            $user->remove_role(self::ROLE);
            if (!$user->roles) {
                $user->set_role(get_option('default_role', 'subscriber'));
            }
            return;
        }
        // Записей у участников нет, но если есть — они переходят администратору, а не удаляются
        wp_delete_user($uid, $reassign ?: null);
    }

    private static function reassign_to(): int
    {
        $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID', 'order' => 'ASC']);
        return $admins ? (int) $admins[0] : 0;
    }

    /**
     * Лимит заявок с адреса (wpd_reg_rl_*) и ссылки «заявка принята» (wpd_reg_done_*).
     * Имена ищутся в базе, а удаляются через delete_transient(), чтобы очистился и кеш объектов.
     */
    private static function delete_transients(): void
    {
        global $wpdb;
        $prefix = '_transient_wpd_reg_';
        $names = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like($prefix) . '%')); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        foreach ($names as $name) {
            delete_transient(substr((string) $name, strlen('_transient_')));
        }
        // Метки срока без значения (если значение уже удалено)
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('_transient_timeout_wpd_reg_') . '%')); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }

    /** Страницы и записи, где остались шорткоды или блоки формы регистрации и кабинета. */
    public static function find_pages(): array
    {
        global $wpdb;
        $where = [];
        $args = [];
        foreach (self::MARKERS as $m) {
            $where[] = 'post_content LIKE %s';
            $args[] = '%' . $wpdb->esc_like($m) . '%';
        }
        $sql = "SELECT ID FROM {$wpdb->posts} WHERE post_status NOT IN ('trash', 'auto-draft', 'inherit') AND post_type NOT IN ('revision', '" . self::CPT . "') AND (" . implode(' OR ', $where) . ') ORDER BY ID';
        $ids = array_map('intval', $wpdb->get_col($wpdb->prepare($sql, $args))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        if ($ids) {
            update_option(self::PAGES_OPTION, $ids, false);
        } else {
            delete_option(self::PAGES_OPTION);
        }
        return $ids;
    }

    private static function still_has_markers(int $id): bool
    {
        $post = get_post($id);
        if (!$post || in_array($post->post_status, ['trash', 'auto-draft'], true)) {
            return false;
        }
        foreach (self::MARKERS as $m) {
            if (strpos((string) $post->post_content, $m) !== false) {
                return true;
            }
        }
        return false;
    }

    /** Уведомление администратору: после очистки на этих страницах остались форма или кабинет. */
    public static function notice(): void
    {
        if (!current_user_can('manage_options') || !self::is_done()) {
            return;
        }
        $ids = (array) get_option(self::PAGES_OPTION, []);
        if (!$ids) {
            return;
        }
        $left = array_values(array_filter(array_map('intval', $ids), [__CLASS__, 'still_has_markers']));
        if (count($left) !== count($ids)) {
            $left ? update_option(self::PAGES_OPTION, $left, false) : delete_option(self::PAGES_OPTION);
        }
        if (!$left) {
            return;
        }
        $links = [];
        foreach ($left as $id) {
            $title = (string) get_post_field('post_title', $id); // без приставки «Личное:»
            $url = get_edit_post_link($id);
            $label = esc_html($title !== '' ? $title : '#' . $id);
            $links[] = $url ? '<a href="' . esc_url($url) . '">' . $label . '</a>' : $label;
        }
        $dismiss = wp_nonce_url(admin_url('admin-post.php?action=wpd_reg_pages_dismiss'), 'wpd_reg_pages_dismiss');
        echo '<div class="notice notice-warning wpd-reg-removed-notice"><p><strong>WP Olympiad Design 2.2.0:</strong> '
            . 'регистрация участников и личный кабинет убраны из плагина, заявки и аккаунты участников удалены. '
            . 'На этих страницах остались шорткоды или блоки формы и кабинета — удалите их или сами страницы: '
            . implode(', ', $links) . '. <a href="' . esc_url($dismiss) . '">Скрыть</a></p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    public static function dismiss(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав.', 403);
        }
        check_admin_referer('wpd_reg_pages_dismiss');
        delete_option(self::PAGES_OPTION);
        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }
}
