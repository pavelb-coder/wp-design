<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * «Олимпиада → Участники» (2.0.0): список заявок, статусы, массовые действия, карточка заявки, экспорт в CSV.
 */
class WPD_Participants
{
    const CPT = WPD_Registration::CPT;

    public static function init(): void
    {
        add_filter('manage_' . self::CPT . '_posts_columns', [__CLASS__, 'columns']);
        add_action('manage_' . self::CPT . '_posts_custom_column', [__CLASS__, 'column'], 10, 2);
        add_filter('manage_edit-' . self::CPT . '_sortable_columns', [__CLASS__, 'sortable']);
        add_filter('views_edit-' . self::CPT, [__CLASS__, 'views']);
        add_action('pre_get_posts', [__CLASS__, 'filter_query']);
        add_filter('bulk_actions-edit-' . self::CPT, [__CLASS__, 'bulk_actions']);
        add_filter('handle_bulk_actions-edit-' . self::CPT, [__CLASS__, 'handle_bulk'], 10, 3);
        add_filter('post_row_actions', [__CLASS__, 'row_actions'], 10, 2);
        add_action('manage_posts_extra_tablenav', [__CLASS__, 'export_button']);
        add_action('admin_notices', [__CLASS__, 'notices']);
        add_action('admin_post_wpd_reg_export', [__CLASS__, 'export']);
        add_action('admin_post_wpd_reg_status', [__CLASS__, 'quick_status']);
        add_action('add_meta_boxes_' . self::CPT, [__CLASS__, 'meta_boxes']);
        add_action('save_post_' . self::CPT, [__CLASS__, 'save'], 10, 2);
        add_filter('enter_title_here', [__CLASS__, 'title_placeholder'], 10, 2);
        add_filter('post_updated_messages', [__CLASS__, 'messages']);
        add_action('admin_menu', [__CLASS__, 'menu_badge'], 99);
        add_action('admin_head', [__CLASS__, 'admin_css']);
    }

    private static function is_screen(): bool
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        return $screen && $screen->post_type === self::CPT;
    }

    /** Поля конструктора, отмеченные «В списке участников». */
    private static function list_fields(): array
    {
        return array_values(array_filter(WPD_Registration::form_fields(), function ($f) {
            return empty($f['system']) && !empty($f['in_list']);
        }));
    }

    public static function columns(array $cols): array
    {
        $out = ['cb' => $cols['cb'] ?? '<input type="checkbox">', 'wpd_number' => '№', 'title' => 'ФИО', 'wpd_email' => 'E-mail'];
        foreach (self::list_fields() as $f) {
            $out['wpd_f_' . $f['id']] = esc_html($f['label']);
        }
        $out['wpd_status'] = 'Статус';
        $out['wpd_account'] = 'Аккаунт';
        $out['date'] = 'Подана';
        return $out;
    }

    public static function column(string $col, int $id): void
    {
        $app = get_post($id);
        if (!$app) {
            return;
        }
        if ($col === 'wpd_number') {
            echo '<strong>' . esc_html((string) get_post_meta($id, '_wpd_number', true)) . '</strong>';
        } elseif ($col === 'wpd_email') {
            $email = (string) get_post_meta($id, '_wpd_email', true);
            echo $email ? '<a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>' : '—';
        } elseif ($col === 'wpd_status') {
            $s = WPD_Registration::app_status($app);
            echo '<span class="wpd-status wpd-status--' . esc_attr($s) . '">' . esc_html(WPD_Registration::STATUSES[$s]) . '</span>';
        } elseif ($col === 'wpd_account') {
            $uid = (int) get_post_meta($id, '_wpd_user', true);
            $user = $uid ? get_userdata($uid) : null;
            echo $user ? '<a href="' . esc_url(get_edit_user_link($uid)) . '">' . esc_html($user->user_login) . '</a>' : '—';
        } elseif (strpos($col, 'wpd_f_') === 0) {
            $values = WPD_Registration::app_values($app);
            $v = (string) ($values[substr($col, 6)] ?? '');
            echo $v !== '' ? esc_html($v) : '—';
        }
    }

    public static function sortable(array $cols): array
    {
        $cols['wpd_number'] = 'wpd_number';
        $cols['title'] = 'title';
        return $cols;
    }

    public static function views(array $views): array
    {
        // phpcs:ignore WordPress.Security.NonceVerification
        $current = isset($_GET['wpd_status']) ? sanitize_key(wp_unslash($_GET['wpd_status'])) : '';
        $base = admin_url('edit.php?post_type=' . self::CPT);
        $out = [];
        $all = WPD_Registration::count_applications();
        $out['all'] = sprintf('<a href="%s"%s>Все <span class="count">(%d)</span></a>', esc_url($base), $current === '' && !isset($_GET['post_status']) ? ' class="current" aria-current="page"' : '', $all); // phpcs:ignore WordPress.Security.NonceVerification
        $titles = ['new' => 'Новые', 'approved' => 'Подтверждённые', 'rejected' => 'Отклонённые'];
        foreach ($titles as $status => $title) {
            $out['wpd_' . $status] = sprintf('<a href="%s"%s>%s <span class="count">(%d)</span></a>', esc_url(add_query_arg('wpd_status', $status, $base)),
                $current === $status ? ' class="current" aria-current="page"' : '', esc_html($title), WPD_Registration::count_by_status($status));
        }
        if (isset($views['trash'])) {
            $out['trash'] = $views['trash'];
        }
        return $out;
    }

    public static function filter_query(WP_Query $q): void
    {
        if (!is_admin() || !$q->is_main_query() || $q->get('post_type') !== self::CPT) {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification
        $status = isset($_GET['wpd_status']) ? sanitize_key(wp_unslash($_GET['wpd_status'])) : '';
        if (isset(WPD_Registration::STATUSES[$status])) {
            $q->set('meta_query', [['key' => '_wpd_status', 'value' => $status]]);
        }
        if ($q->get('orderby') === 'wpd_number') {
            $q->set('meta_key', '_wpd_number');
            $q->set('orderby', 'meta_value');
        }
        // phpcs:enable
    }

    public static function bulk_actions(array $actions): array
    {
        unset($actions['edit']);
        return ['wpd_approved' => 'Подтвердить', 'wpd_rejected' => 'Отклонить', 'wpd_new' => 'Вернуть в «Новые»'] + $actions;
    }

    public static function handle_bulk(string $redirect, string $action, array $ids): string
    {
        if (strpos($action, 'wpd_') !== 0 || !current_user_can('manage_options')) {
            return $redirect;
        }
        $status = substr($action, 4);
        $n = 0;
        foreach ($ids as $id) {
            $n += WPD_Registration::set_status((int) $id, $status) ? 1 : 0;
        }
        return add_query_arg('wpd_changed', $n, remove_query_arg('wpd_changed', $redirect));
    }

    public static function row_actions(array $actions, $post): array
    {
        if (!$post instanceof WP_Post || $post->post_type !== self::CPT) {
            return $actions;
        }
        unset($actions['inline hide-if-no-js']);
        $current = WPD_Registration::app_status($post);
        $labels = ['approved' => 'Подтвердить', 'rejected' => 'Отклонить', 'new' => 'В «Новые»'];
        $out = [];
        foreach ($labels as $status => $label) {
            if ($status !== $current && $post->post_status === 'publish') {
                $url = wp_nonce_url(admin_url('admin-post.php?action=wpd_reg_status&post=' . $post->ID . '&status=' . $status), 'wpd_reg_status_' . $post->ID);
                $out['wpd_' . $status] = '<a href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
            }
        }
        return $out + $actions;
    }

    public static function quick_status(): void
    {
        $id = isset($_GET['post']) ? absint($_GET['post']) : 0;
        if (!current_user_can('manage_options') || !$id) {
            wp_die('Недостаточно прав.', 403);
        }
        check_admin_referer('wpd_reg_status_' . $id);
        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
        $n = WPD_Registration::set_status($id, $status) ? 1 : 0;
        $back = wp_get_referer() ?: admin_url('edit.php?post_type=' . self::CPT);
        wp_safe_redirect(add_query_arg('wpd_changed', $n, remove_query_arg('wpd_changed', $back)));
        exit;
    }

    public static function notices(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification
        if (!self::is_screen() || !isset($_GET['wpd_changed'])) {
            return;
        }
        $n = absint($_GET['wpd_changed']); // phpcs:ignore WordPress.Security.NonceVerification
        $text = $n ? 'Статус изменён: ' . $n . ' ' . ($n % 10 === 1 && $n % 100 !== 11 ? 'заявка' : ($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 10 || $n % 100 >= 20) ? 'заявки' : 'заявок')) . '.'
            : 'Статус не изменился.';
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($text) . '</p></div>';
    }

    public static function export_button(string $which): void
    {
        if ($which !== 'top' || !self::is_screen()) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification
        $status = isset($_GET['wpd_status']) ? sanitize_key(wp_unslash($_GET['wpd_status'])) : '';
        $url = wp_nonce_url(admin_url('admin-post.php?action=wpd_reg_export' . ($status ? '&wpd_status=' . $status : '')), 'wpd_reg_export');
        echo '<div class="alignleft actions"><a class="button" href="' . esc_url($url) . '">Экспорт в CSV' . ($status ? ' (' . esc_html(WPD_Registration::STATUSES[$status] ?? '') . ')' : '') . '</a></div>';
    }

    /** Значение ячейки CSV без формул: Excel не должен выполнять «=…», «+…», «-…», «@…». */
    public static function csv_cell(string $v): string
    {
        return ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v;
    }

    /** Строки CSV: заголовок и по строке на заявку. */
    public static function csv_rows(string $status = ''): array
    {
        $fields = WPD_Registration::form_fields();
        $head = ['№', 'Подана', 'Статус'];
        foreach ($fields as $f) {
            $head[] = $f['label'];
        }
        $head[] = 'Логин';
        $rows = [$head];
        $args = ['post_type' => self::CPT, 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'date', 'order' => 'ASC', 'suppress_filters' => true];
        if (isset(WPD_Registration::STATUSES[$status])) {
            $args['meta_query'] = [['key' => '_wpd_status', 'value' => $status]];
        }
        foreach (get_posts($args) as $app) {
            $values = WPD_Registration::app_values($app);
            $uid = (int) get_post_meta($app->ID, '_wpd_user', true);
            $user = $uid ? get_userdata($uid) : null;
            $row = [(string) get_post_meta($app->ID, '_wpd_number', true), get_post_time('Y-m-d H:i', false, $app),
                    WPD_Registration::STATUSES[WPD_Registration::app_status($app)]];
            foreach ($fields as $f) {
                $v = (string) ($values[$f['id']] ?? '');
                $row[] = $f['type'] === 'checkbox' ? ($v === '1' ? 'да' : 'нет') : $v;
            }
            $row[] = $user ? $user->user_login : '';
            $rows[] = array_map([__CLASS__, 'csv_cell'], $row);
        }
        return $rows;
    }

    public static function export(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав.', 403);
        }
        check_admin_referer('wpd_reg_export');
        $status = isset($_GET['wpd_status']) ? sanitize_key(wp_unslash($_GET['wpd_status'])) : '';
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="participants-' . gmdate('Y-m-d') . ($status ? '-' . $status : '') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM: Excel открывает кириллицу без перекодировки
        foreach (self::csv_rows($status) as $row) {
            fputcsv($out, $row, ';');
        }
        fclose($out);
        exit;
    }

    /* ---------------------------------------------------------------
     * Карточка заявки
     * ------------------------------------------------------------- */

    public static function meta_boxes(WP_Post $post): void
    {
        remove_meta_box('slugdiv', self::CPT, 'normal');
        add_meta_box('wpd_app_data', 'Данные заявки', [__CLASS__, 'box_data'], self::CPT, 'normal', 'high');
        add_meta_box('wpd_app_status', 'Статус заявки', [__CLASS__, 'box_status'], self::CPT, 'side', 'high');
    }

    public static function box_status(WP_Post $post): void
    {
        wp_nonce_field('wpd_app_save', 'wpd_app_nonce');
        $current = WPD_Registration::app_status($post);
        echo '<p><strong>' . esc_html((string) get_post_meta($post->ID, '_wpd_number', true)) . '</strong></p><p><label for="wpd_app_status_sel">Статус</label><br><select id="wpd_app_status_sel" name="wpd_app_status">';
        foreach (WPD_Registration::STATUSES as $k => $label) {
            printf('<option value="%s"%s>%s</option>', esc_attr($k), selected($current, $k, false), esc_html($label));
        }
        echo '</select></p>';
        if (WPD_Registration::get()['mail_status']) {
            echo '<p><label><input type="checkbox" name="wpd_app_notify" value="1" checked> Сообщить участнику о смене статуса</label></p>';
        }
        $uid = (int) get_post_meta($post->ID, '_wpd_user', true);
        $user = $uid ? get_userdata($uid) : null;
        echo '<p>Аккаунт: ' . ($user ? '<a href="' . esc_url(get_edit_user_link($uid)) . '">' . esc_html($user->user_login) . '</a>' : 'нет') . '</p>';
    }

    public static function box_data(WP_Post $post): void
    {
        $values = WPD_Registration::app_values($post);
        echo '<div class="wpd-page wpd-app-box"><div class="wpd-reg__grid">';
        foreach (WPD_Registration::form_fields() as $f) {
            if ($f['id'] === 'name') {
                continue; // ФИО — заголовок записи
            }
            $f['required'] = 0; // организатор может оставить поле пустым
            echo WPD_Registration::field_html($f, (string) ($values[$f['id']] ?? ''), ''); // phpcs:ignore WordPress.Security.EscapeOutput
        }
        echo '</div>';
        // Значения полей, которых уже нет в конструкторе
        $known = array_column(WPD_Registration::form_fields(), 'id');
        $orphans = array_diff_key($values, array_flip($known));
        if ($orphans) {
            echo '<h4>Поля, удалённые из формы</h4><ul>';
            foreach ($orphans as $k => $v) {
                echo '<li><code>' . esc_html((string) $k) . '</code>: ' . esc_html(is_scalar($v) ? (string) $v : '') . '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }

    public static function save(int $id, WP_Post $post): void
    {
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !isset($_POST['wpd_app_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wpd_app_nonce'])), 'wpd_app_save') || !current_user_can('manage_options')) {
            return;
        }
        remove_action('save_post_' . self::CPT, [__CLASS__, 'save'], 10);
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- очищается в validate()
        $input = isset($_POST['wpd_reg']) && is_array($_POST['wpd_reg']) ? wp_unslash($_POST['wpd_reg']) : [];
        $input['name'] = $post->post_title;
        $fields = array_map(function ($f) {
            $f['required'] = 0;
            return $f;
        }, WPD_Registration::form_fields());
        [$values, $errors] = WPD_Registration::validate($input, $fields);
        // Поля с ошибкой (неверный e-mail, вариант удалён из списка и т. п.) не портят заявку — остаётся прежнее значение
        $old = WPD_Registration::app_values($post);
        foreach (array_keys($errors) as $k) {
            if (isset($old[$k])) {
                $values[$k] = $old[$k];
            }
        }
        if ($values['email'] === '') { // e-mail заявки не стираем
            $values['email'] = $old['email'];
        }
        WPD_Registration::update_application($id, $values, true);
        $status = isset($_POST['wpd_app_status']) ? sanitize_key(wp_unslash($_POST['wpd_app_status'])) : '';
        WPD_Registration::set_status($id, $status, !empty($_POST['wpd_app_notify']));
        add_action('save_post_' . self::CPT, [__CLASS__, 'save'], 10, 2);
    }

    public static function title_placeholder($text, $post)
    {
        return ($post instanceof WP_Post && $post->post_type === self::CPT) ? 'ФИО участника' : $text;
    }

    public static function messages(array $messages): array
    {
        $messages[self::CPT] = array_fill(0, 11, 'Заявка сохранена.');
        $messages[self::CPT][0] = '';
        return $messages;
    }

    /** Число новых заявок рядом с пунктом меню «Участники». */
    public static function menu_badge(): void
    {
        global $submenu;
        if (empty($submenu[WPD_Settings::PAGE]) || !current_user_can('manage_options')) {
            return;
        }
        $n = WPD_Registration::count_by_status('new');
        if (!$n) {
            return;
        }
        foreach ($submenu[WPD_Settings::PAGE] as $i => $item) {
            if (($item[2] ?? '') === 'edit.php?post_type=' . self::CPT) {
                $submenu[WPD_Settings::PAGE][$i][0] .= ' <span class="awaiting-mod count-' . $n . '"><span class="pending-count">' . $n . '</span></span>'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
            }
        }
    }

    public static function admin_css(): void
    {
        if (!self::is_screen()) {
            return;
        }
        echo '<style>.wpd-status{display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:600;line-height:20px}'
            . '.wpd-status--new{background:#fef3c7;color:#92400e}.wpd-status--approved{background:#dcfce7;color:#166534}.wpd-status--rejected{background:#fee2e2;color:#991b1b}'
            . '.column-wpd_number{width:90px}.column-wpd_status{width:130px}'
            . '.wpd-app-box .wpd-reg__grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px 20px}'
            . '.wpd-app-box .wpd-reg__field label,.wpd-app-box legend{display:block;font-weight:600;margin-bottom:4px}'
            . '.wpd-app-box input[type=text],.wpd-app-box input[type=email],.wpd-app-box input[type=tel],.wpd-app-box input[type=number],.wpd-app-box input[type=date],.wpd-app-box select,.wpd-app-box textarea{width:100%}'
            . '.wpd-app-box .wpd-reg__field--wide{grid-column:1/-1}.wpd-app-box .wpd-reg__req{display:none}.wpd-app-box fieldset{border:0;padding:0;margin:0}</style>';
    }
}
