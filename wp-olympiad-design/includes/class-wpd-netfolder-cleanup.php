<?php
/**
 * 2.10.0: импорт документов из сетевой папки (SMB, 2.8.0) убран из плагина.
 * Этот класс один раз удаляет всё, что с ним связано:
 *  - файлы, скопированные из папки в медиатеку (вложения с отметкой _wpd_nf_src), — вместе с файлами на диске;
 *  - строки документов из папки (с путём src) — в общем списке, в своих списках страниц (панель «Блоки олимпиады»,
 *    в том числе группа «Документы» своего оформления страницы) и в блоках «Олимпиада: документы» внутри записей;
 *    у оставшихся строк снимаются служебные поля src и nf, у блоков — атрибуты nfFolder и nfAuto,
 *    у страниц — подпапка docs_nf и автообновление docs_nf_auto;
 *  - настройки подключения (с зашифрованным паролем), индекс файлов, журнал, блокировку, задание WP-Cron
 *    и кеш файлов режима «только ссылки» (uploads/wpd-nf-cache).
 *
 * Работает порциями по BATCH объектов за запрос (на init), пока не закончит; затем ставит отметку DONE_OPTION
 * с итогом, который администратор видит в уведомлении. Класс не зависит от остальных классов плагина —
 * его вызывает и uninstall.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class WPD_Netfolder_Cleanup
{
    const DONE_OPTION  = 'wpd_nf_removed';        // итог: версия, удалено строк, файлов, обновлено записей и страниц
    const STATE_OPTION = 'wpd_nf_removed_state';  // ход очистки: курсоры и счётчики
    const NOTICE       = 'wpd_nf_removed_notice'; // 1 — показать уведомление администратору
    const LOCK         = 'wpd_nf_cleanup_lock';
    const BATCH        = 50;

    /** Данные бывшей сетевой папки. */
    const OPTIONS   = ['wpd_netfolder', 'wpd_nf_index', 'wpd_nf_log'];
    const HOOK      = 'wpd_nf_sync';
    const OLD_LOCK  = 'wpd_nf_lock';
    const META_SRC  = '_wpd_nf_src';
    const META_SIG  = '_wpd_nf_sig';
    const CACHE_DIR = 'wpd-nf-cache';
    const MARKER    = '<!-- wp:wpd/docs';

    public static function init(): void
    {
        add_action('init', [__CLASS__, 'maybe_run'], 20);
        add_action('admin_notices', [__CLASS__, 'notice']);
        add_action('admin_post_wpd_nf_removed_dismiss', [__CLASS__, 'dismiss']);
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
        self::run(self::BATCH);
        delete_transient(self::LOCK);
    }

    /**
     * Одна порция очистки: не больше $limit вложений, $limit записей с блоками документов и $limit страниц с панелью.
     * Возвращает true, когда всё удалено (и ставит отметку DONE_OPTION). $limit = 0 — всё за один раз.
     */
    public static function run(int $limit = self::BATCH): bool
    {
        $state = get_option(self::STATE_OPTION, []);
        $state = (is_array($state) ? $state : []) + ['posts' => 0, 'metas' => 0, 'files' => 0, 'rows' => 0, 'updated' => 0, 'pages' => 0, 'had' => 0];
        $more  = false;

        // 1. Файлы, скопированные из папки в медиатеку
        $ids = get_posts([
            'post_type'        => 'attachment',
            'post_status'      => 'any',
            'meta_key'         => self::META_SRC, // phpcs:ignore WordPress.DB.SlowDBQuery
            'numberposts'      => $limit > 0 ? $limit : -1,
            'fields'           => 'ids',
            'suppress_filters' => true,
            'no_found_rows'    => true,
        ]);
        foreach ($ids as $id) {
            if (wp_delete_attachment((int) $id, true)) {
                $state['files']++;
            } else {
                delete_post_meta((int) $id, self::META_SRC); // не удалилось — хотя бы не зациклиться
            }
        }
        if ($limit > 0 && count($ids) >= $limit) {
            $more = true;
        }

        // 2. Блоки «Олимпиада: документы» в записях (по возрастанию ID, с курсором)
        if (!$more) {
            $more = self::posts_batch($state, $limit);
        }

        // 3. Свои списки страниц (мета-поле панели)
        if (!$more) {
            $more = self::metas_batch($state, $limit);
        }

        if ($more) {
            update_option(self::STATE_OPTION, $state, false);
            return false;
        }

        // 4. Общий список документов, настройки, индекс, журнал, расписание, кеш
        $state['rows'] += self::clean_settings();
        foreach (self::OPTIONS as $name) {
            if (get_option($name, null) !== null) {
                $state['had'] = 1;
            }
            delete_option($name);
        }
        delete_transient(self::OLD_LOCK);
        wp_clear_scheduled_hook(self::HOOK);
        delete_post_meta_by_key(self::META_SRC);
        delete_post_meta_by_key(self::META_SIG);
        self::remove_cache_dir();

        $report = [
            'version' => defined('WPD_VERSION') ? WPD_VERSION : '',
            'files'   => (int) $state['files'],
            'rows'    => (int) $state['rows'],
            'posts'   => (int) $state['updated'],
            'pages'   => (int) $state['pages'],
        ];
        update_option(self::DONE_OPTION, $report);
        delete_option(self::STATE_OPTION);
        if ($state['had'] || $report['files'] || $report['rows']) {
            update_option(self::NOTICE, 1, false);
        }
        return true;
    }

    /** Порция записей с блоками документов. true — есть ещё. */
    private static function posts_batch(array &$state, int $limit): bool
    {
        global $wpdb;
        $sql = "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type <> 'revision' AND post_content LIKE %s ORDER BY ID ASC"
            . ($limit > 0 ? ' LIMIT ' . (int) $limit : '');
        $ids = array_map('intval', $wpdb->get_col($wpdb->prepare($sql, (int) $state['posts'], '%' . $wpdb->esc_like(self::MARKER) . '%'))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        foreach ($ids as $id) {
            $state['posts'] = $id;
            $content = (string) get_post_field('post_content', $id, 'raw');
            $removed = 0;
            $new     = self::clean_content($content, $removed);
            if ($new !== null) {
                // Напрямую в таблицу: без ревизии, фильтров kses и смены даты изменения — меняются только служебные данные
                $wpdb->update($wpdb->posts, ['post_content' => $new], ['ID' => $id]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                clean_post_cache($id);
                $state['updated']++;
                $state['rows'] += $removed;
            }
        }
        return $limit > 0 && count($ids) >= $limit;
    }

    /** Порция страниц с настройками панели. true — есть ещё. */
    private static function metas_batch(array &$state, int $limit): bool
    {
        global $wpdb;
        $sql = "SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wpd_page' AND meta_id > %d ORDER BY meta_id ASC"
            . ($limit > 0 ? ' LIMIT ' . (int) $limit : '');
        $rows = $wpdb->get_results($wpdb->prepare($sql, (int) $state['metas'])); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        foreach ((array) $rows as $r) {
            $state['metas'] = (int) $r->meta_id;
            $meta = maybe_unserialize($r->meta_value);
            if (!is_array($meta)) {
                continue;
            }
            $removed = 0;
            $clean   = self::clean_page_meta($meta, $removed);
            if ($clean !== $meta) {
                update_metadata_by_mid('post', (int) $r->meta_id, $clean);
                $state['pages']++;
                $state['rows'] += $removed;
            }
        }
        return $limit > 0 && count((array) $rows) >= $limit;
    }

    /**
     * Строки документов: строки из папки (непустой src) удаляются, у остальных снимаются служебные поля.
     * Не список строк — возвращается как есть.
     */
    public static function clean_rows($rows, int &$removed = 0)
    {
        if (!is_array($rows)) {
            return $rows;
        }
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                if (isset($row['src']) && is_scalar($row['src']) && trim((string) $row['src']) !== '') {
                    $removed++;
                    continue;
                }
                unset($row['src'], $row['nf']);
            }
            $out[] = $row;
        }
        return $out;
    }

    /** Мета-поле панели страницы без строк из папки и полей сетевой папки. */
    public static function clean_page_meta(array $meta, int &$removed = 0): array
    {
        if (array_key_exists('docs', $meta)) {
            $meta['docs'] = self::clean_rows($meta['docs'], $removed);
        }
        if (isset($meta['ov']['docs']) && is_array($meta['ov']['docs']) && array_key_exists('docs', $meta['ov']['docs'])) {
            $meta['ov']['docs']['docs'] = self::clean_rows($meta['ov']['docs']['docs'], $removed);
        }
        unset($meta['docs_nf'], $meta['docs_nf_auto']);
        return $meta;
    }

    /**
     * Содержимое записи: в блоках wpd/docs (на любом уровне вложенности) — строки без файлов из папки,
     * без атрибутов nfFolder и nfAuto. null — менять нечего.
     */
    public static function clean_content(string $content, int &$removed = 0): ?string
    {
        if (strpos($content, self::MARKER) === false || !function_exists('parse_blocks')) {
            return null;
        }
        $changed = false;
        $walk = function (array $blocks) use (&$walk, &$changed, &$removed): array {
            foreach ($blocks as $i => $b) {
                if (($b['blockName'] ?? '') === 'wpd/docs' && is_array($b['attrs'] ?? null)) {
                    $attrs = $b['attrs'];
                    if (array_key_exists('items', $attrs)) {
                        $attrs['items'] = self::clean_rows($attrs['items'], $removed);
                    }
                    unset($attrs['nfFolder'], $attrs['nfAuto']);
                    if ($attrs !== $b['attrs']) {
                        $blocks[$i]['attrs'] = $attrs;
                        $changed = true;
                    }
                }
                if (!empty($b['innerBlocks'])) {
                    $blocks[$i]['innerBlocks'] = $walk($b['innerBlocks']);
                }
            }
            return $blocks;
        };
        $blocks = $walk(parse_blocks($content));
        return $changed ? serialize_blocks($blocks) : null;
    }

    /** Общий список документов в настройках. Возвращает число удалённых строк. */
    private static function clean_settings(): int
    {
        $o = get_option('wpd_settings', null);
        if (!is_array($o) || !array_key_exists('docs', $o)) {
            return 0;
        }
        $removed = 0;
        $docs    = self::clean_rows($o['docs'], $removed);
        if ($docs !== $o['docs']) {
            $o['docs'] = $docs;
            // Без фильтра санитизации: значения уже чистые, а вне админки он и не подключён
            global $wp_filter;
            $saved = $wp_filter['sanitize_option_wpd_settings'] ?? null;
            unset($wp_filter['sanitize_option_wpd_settings']);
            update_option('wpd_settings', $o);
            if ($saved !== null) {
                $wp_filter['sanitize_option_wpd_settings'] = $saved;
            }
            if (class_exists('WPD_Settings') && method_exists('WPD_Settings', 'flush_cache')) {
                WPD_Settings::flush_cache();
            }
        }
        return $removed;
    }

    private static function remove_cache_dir(): void
    {
        $up  = wp_upload_dir(null, false);
        $dir = trailingslashit((string) $up['basedir']) . self::CACHE_DIR;
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $file) {
            if (is_string($file) && $file !== '.' && $file !== '..' && is_file($dir . '/' . $file)) {
                @unlink($dir . '/' . $file); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            }
        }
        @rmdir($dir); // phpcs:ignore WordPress.PHP.NoSilencedErrors
    }

    /** Склонение: 1 документ, 2 документа, 5 документов. */
    private static function plural(int $n, string $one, string $few, string $many): string
    {
        $m10  = $n % 10;
        $m100 = $n % 100;
        $word = ($m10 === 1 && $m100 !== 11) ? $one : (($m10 >= 2 && $m10 <= 4 && ($m100 < 12 || $m100 > 14)) ? $few : $many);
        return $n . ' ' . $word;
    }

    /** Текст итога для уведомления. */
    public static function report_text(array $r): string
    {
        $parts = [];
        if (!empty($r['rows'])) {
            $parts[] = 'удалено ' . self::plural((int) $r['rows'], 'строка', 'строки', 'строк') . ' документов из папки';
        }
        if (!empty($r['files'])) {
            $parts[] = self::plural((int) $r['files'], 'файл', 'файла', 'файлов') . ' медиатеки';
        }
        $where = (int) ($r['posts'] ?? 0) + (int) ($r['pages'] ?? 0);
        if ($where) {
            $parts[] = 'изменено ' . self::plural($where, 'запись или страница', 'записи или страницы', 'записей или страниц');
        }
        return 'Импорт документов из сетевой папки убран из плагина. Настройки подключения, пароль, журнал и расписание удалены'
            . ($parts ? '; ' . implode(', ', $parts) : '') . '.';
    }

    /** Уведомление администратору: что удалено. */
    public static function notice(): void
    {
        if (!current_user_can('manage_options') || !self::is_done() || !get_option(self::NOTICE)) {
            return;
        }
        $r = get_option(self::DONE_OPTION, []);
        $dismiss = wp_nonce_url(admin_url('admin-post.php?action=wpd_nf_removed_dismiss'), 'wpd_nf_removed_dismiss');
        echo '<div class="notice notice-info wpd-nf-removed-notice"><p><strong>WP Olympiad Design '
            . esc_html((string) ($r['version'] ?? '')) . ':</strong> ' . esc_html(self::report_text(is_array($r) ? $r : []))
            . ' <a href="' . esc_url($dismiss) . '">Скрыть</a></p></div>';
    }

    public static function dismiss(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав.', 403);
        }
        check_admin_referer('wpd_nf_removed_dismiss');
        delete_option(self::NOTICE);
        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }
}
