<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Панель «Блоки олимпиады» в редакторе записи: порядок и видимость блоков,
 * собственное содержимое главного блока, статистики, документов и FAQ для конкретной страницы.
 * Работает и в классическом, и в блочном редакторе (как обычный мета-бокс).
 */
class WPD_Page_Editor
{
    const META   = '_wpd_page';
    const NONCE  = 'wpd_page_nonce';
    const ACTION = 'wpd_save_page';

    /** Списки, которые можно задать для страницы, и ключи их строк. */
    const LISTS = [
        'stats' => ['num', 'label'],
        'docs'  => ['title', 'url'],
        'faq'   => ['q', 'a'],
    ];

    public static function init(): void
    {
        add_action('add_meta_boxes', [__CLASS__, 'add_meta_box']);
        add_action('save_post', [__CLASS__, 'save'], 10, 2);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);
    }

    /**
     * Блоки оформленной страницы в порядке по умолчанию.
     */
    public static function blocks(): array
    {
        return [
            'slider'    => 'Слайдер',
            'hero'      => 'Главный блок',
            'buttons'   => 'Кнопки-ссылки',
            'countdown' => 'Обратный отсчёт',
            'notice'    => 'Объявление',
            'content'   => 'Текст страницы',
            'cards'     => 'Карточки',
            'stats'     => 'Статистика',
            'news'      => 'Новости',
            'docs'      => 'Документы',
            'faq'       => 'Частые вопросы',
            'contacts'  => 'Контакты',
        ];
    }

    /**
     * Компоненты 1.5.0. Пока страница не сохранила их видимость явно, они скрыты:
     * иначе после обновления плагина на уже оформленных страницах внезапно появились бы новые блоки.
     */
    const OPT_IN = ['slider', 'buttons', 'notice', 'cards', 'news', 'contacts'];

    public static function defaults(): array
    {
        return [
            'order'      => array_keys(self::blocks()),
            'hidden'     => self::OPT_IN,
            'hero'       => [
                'title'       => '',
                'badge'       => '',
                'lead'        => '',
                'button_text' => '',
                'button_url'  => '',
                'image_id'    => 0,
                'style'       => '',
            ],
            'stats_mode' => 'global',
            'stats'      => [],
            'docs_mode'  => 'global',
            'docs_title' => '',
            'docs'       => [],
            'faq_mode'   => 'global',
            'faq_title'  => '',
            'faq'        => [],
        ];
    }

    /**
     * Настройки страницы с подстановкой значений по умолчанию.
     */
    public static function get(int $post_id): array
    {
        $raw = $post_id ? get_post_meta($post_id, self::META, true) : [];
        return self::normalize(is_array($raw) ? $raw : []);
    }

    /**
     * Приводит сохранённые данные к полной структуре. Неизвестные ключи и блоки отбрасываются,
     * новые блоки (если появятся в будущих версиях) добавляются в конец.
     */
    public static function normalize(array $raw): array
    {
        $d      = self::defaults();
        $blocks = self::blocks();
        $out    = $d;

        $order = [];
        foreach ((array) ($raw['order'] ?? []) as $key) {
            $key = is_scalar($key) ? (string) $key : '';
            if (isset($blocks[$key]) && !in_array($key, $order, true)) {
                $order[] = $key;
            }
        }
        // Блоки, которых нет в сохранённом порядке, встают на место по умолчанию — после ближайшего предыдущего
        // известного блока. Компоненты из OPT_IN при этом скрыты.
        $missing = [];
        $keys    = array_keys($blocks);
        foreach ($keys as $pos => $key) {
            if (in_array($key, $order, true)) {
                continue;
            }
            $missing[] = $key;
            $after = -1;
            for ($k = $pos - 1; $k >= 0; $k--) {
                $found = array_search($keys[$k], $order, true);
                if ($found !== false) {
                    $after = $found;
                    break;
                }
            }
            array_splice($order, $after + 1, 0, [$key]);
        }
        $out['order'] = $order;

        $hidden = array_map('strval', array_filter((array) ($raw['hidden'] ?? []), 'is_scalar'));
        $hidden = array_merge($hidden, array_intersect($missing, self::OPT_IN));
        $out['hidden'] = array_values(array_intersect($order, $hidden));

        $hero = is_array($raw['hero'] ?? null) ? $raw['hero'] : [];
        foreach ($d['hero'] as $key => $default) {
            if ($key === 'image_id') {
                $out['hero'][$key] = absint($hero[$key] ?? 0);
            } else {
                $out['hero'][$key] = isset($hero[$key]) && is_scalar($hero[$key]) ? (string) $hero[$key] : '';
            }
        }
        if (!in_array($out['hero']['style'], array_merge([''], WPD_Renderer::HERO_STYLES), true)) {
            $out['hero']['style'] = '';
        }

        foreach (self::LISTS as $list => $keys) {
            $out[$list . '_mode'] = ($raw[$list . '_mode'] ?? '') === 'custom' ? 'custom' : 'global';
            $out[$list]           = WPD_Renderer::clean_items(is_array($raw[$list] ?? null) ? $raw[$list] : [], $keys);
        }
        foreach (['docs_title', 'faq_title'] as $key) {
            $out[$key] = isset($raw[$key]) && is_scalar($raw[$key]) ? (string) $raw[$key] : '';
        }

        return $out;
    }

    /**
     * Санитизация данных формы панели (уже без слешей).
     * Формат: order[] — порядок, show[ключ] — видимость, hero[...], списки строк stats/docs/faq.
     */
    public static function sanitize($input): array
    {
        $input = is_array($input) ? $input : [];
        $out   = self::defaults();
        $norm  = self::normalize($input);

        $out['order'] = $norm['order'];
        if (isset($input['order'])) {
            $show          = is_array($input['show'] ?? null) ? $input['show'] : [];
            $out['hidden'] = array_values(array_filter($out['order'], function ($key) use ($show) {
                return empty($show[$key]);
            }));
        }

        $h = $norm['hero'];
        $out['hero'] = [
            'title'       => sanitize_text_field($h['title']),
            'badge'       => sanitize_text_field($h['badge']),
            'lead'        => sanitize_textarea_field($h['lead']),
            'button_text' => sanitize_text_field($h['button_text']),
            'button_url'  => esc_url_raw(trim($h['button_url'])),
            'image_id'    => (int) $h['image_id'],
            'style'       => $h['style'],
        ];

        $out['stats_mode'] = $norm['stats_mode'];
        $out['docs_mode']  = $norm['docs_mode'];
        $out['faq_mode']   = $norm['faq_mode'];
        $out['docs_title'] = sanitize_text_field($norm['docs_title']);
        $out['faq_title']  = sanitize_text_field($norm['faq_title']);

        foreach ($norm['stats'] as $row) {
            $row = ['num' => sanitize_text_field($row['num']), 'label' => sanitize_text_field($row['label'])];
            if ($row['num'] !== '' || $row['label'] !== '') {
                $out['stats'][] = $row;
            }
        }
        foreach ($norm['docs'] as $row) {
            $row = ['title' => sanitize_text_field($row['title']), 'url' => esc_url_raw($row['url'])];
            if ($row['title'] !== '' || $row['url'] !== '') {
                $out['docs'][] = $row;
            }
        }
        foreach ($norm['faq'] as $row) {
            $row = [
                'q' => sanitize_text_field($row['q']),
                'a' => trim(wp_kses($row['a'], WPD_Settings::allowed_html())),
            ];
            if ($row['q'] !== '' || $row['a'] !== '') {
                $out['faq'][] = $row;
            }
        }

        return $out;
    }

    /* ---------------------------------------------------------------
     * Сохранение
     * ------------------------------------------------------------- */

    public static function save(int $post_id, WP_Post $post): void
    {
        if (!isset($_POST[self::NONCE])) {
            return;
        }
        $nonce = sanitize_text_field(wp_unslash($_POST[self::NONCE]));
        if (!wp_verify_nonce($nonce, self::ACTION)) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- санитизация в self::sanitize()
        $raw   = isset($_POST['wpd_page']) ? wp_unslash($_POST['wpd_page']) : [];
        $clean = self::sanitize($raw);

        if ($clean === self::defaults()) {
            delete_post_meta($post_id, self::META);
        } else {
            // update_post_meta снимает слеши, поэтому данные передаются экранированными.
            update_post_meta($post_id, self::META, wp_slash($clean));
        }
    }

    /* ---------------------------------------------------------------
     * Мета-бокс
     * ------------------------------------------------------------- */

    public static function add_meta_box(): void
    {
        foreach (WPD_Renderer::post_types() as $type) {
            add_meta_box(
                'wpd_page_blocks',
                'Блоки олимпиады',
                [__CLASS__, 'meta_box_html'],
                $type,
                'normal',
                'high'
            );
        }
    }

    public static function enqueue(string $hook): void
    {
        if ($hook !== 'post.php' && $hook !== 'post-new.php') {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->post_type, WPD_Renderer::post_types(), true)) {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_style('wpd-admin', WPD_URL . 'assets/css/wpd-admin.css', [], WPD_VERSION);
        wp_enqueue_script('wpd-admin', WPD_URL . 'assets/js/wpd-admin.js', ['jquery', 'jquery-ui-sortable'], WPD_VERSION, true);

        $o = WPD_Settings::get();
        wp_localize_script('wpd-admin', 'wpdPageEditor', [
            'global' => [
                'stats' => WPD_Settings::stats_items($o['stats']),
                'docs'  => WPD_Settings::docs_items($o['docs']),
                'faq'   => WPD_Settings::parse_faq($o['faq']),
            ],
            'i18n'   => [
                'replace'     => 'Заменить текущие строки значениями из общих настроек?',
                'emptyGlobal' => 'В общих настройках этот список пуст.',
                'chooseImage' => 'Изображение главного блока',
                'chooseFile'  => 'Файл документа',
                'use'         => 'Выбрать',
                'remove'      => 'Удалить строку?',
            ],
        ]);
    }

    public static function meta_box_html(WP_Post $post): void
    {
        wp_nonce_field(self::ACTION, self::NONCE);

        $p        = self::get($post->ID);
        $o        = WPD_Settings::get();
        $blocks   = self::blocks();
        $enabled  = WPD_Renderer::is_design_enabled($post->ID);
        $settings = WPD_Settings::url();
        $has_blocks = strpos((string) $post->post_content, '<!-- wp:' . WPD_Blocks::NS . '/') !== false;

        $notes = [
            'hero'      => 'заголовок, текст, кнопка, изображение',
            'countdown' => empty($o['countdown_enabled']) || $o['countdown_date'] === ''
                ? 'выключен в общих настройках'
                : ($o['countdown_end'] !== '' ? 'начало и окончание из общих настроек' : 'дата из общих настроек'),
            'content'   => 'то, что написано в редакторе',
            'stats'     => '',
            'docs'      => '',
            'faq'       => '',
            'slider'    => count($o['slides']) ? count($o['slides']) . ' сл. из общих настроек' : 'нет слайдов в общих настройках',
            'buttons'   => 'из общих настроек',
            'cards'     => 'из общих настроек',
            'notice'    => $o['notice_title'] !== '' || $o['notice_image'] !== '' || $o['notice_content'] !== ''
                ? 'из общих настроек' : 'не заполнено в общих настройках',
            'news'      => 'последние записи',
            'contacts'  => $o['contact_org'] !== '' || $o['contact_address'] !== '' || $o['contact_phone'] !== '' || $o['contact_email'] !== ''
                ? 'из общих настроек' : 'не заполнены в общих настройках',
        ];


        echo '<div class="wpd-pe" data-wpd-pe>';

        printf(
            '<div class="wpd-pe__notice" data-wpd-off%s><p>Оформление для этой записи выключено. '
            . 'Включите «Оформление в стиле олимпиады» в боковой панели, чтобы блоки появились на странице.</p></div>',
            $enabled ? ' hidden' : ''
        );
        if ($has_blocks) {
            echo '<div class="wpd-pe__notice wpd-pe__notice--info" data-wpd-blocks-note' . ($enabled ? '' : ' hidden') . '>'
                . '<p>В тексте записи уже есть блоки «Олимпиада: …». Если включить ещё и оформление страницы, '
                . 'главный блок и списки могут повториться. Обычно хватает одного способа.</p></div>';
        }

        // Порядок и видимость
        echo '<h3 class="wpd-pe__h">Блоки и порядок</h3>'
            . '<p class="description">Перетащите блоки или используйте стрелки. Снятая галочка — блок не показывается на этой странице.</p>'
            . '<ul class="wpd-pe__order" data-wpd-sortable>';
        foreach ($p['order'] as $key) {
            $shown = !in_array($key, $p['hidden'], true);
            printf(
                '<li class="wpd-pe__block%5$s" data-key="%1$s">'
                . '<span class="wpd-pe__handle dashicons dashicons-menu" aria-hidden="true" title="Перетащить"></span>'
                . '<input type="hidden" name="wpd_page[order][]" value="%1$s">'
                . '<label><input type="checkbox" name="wpd_page[show][%1$s]" value="1"%2$s> <strong>%3$s</strong></label>'
                . '<span class="wpd-pe__note">%4$s</span>'
                . '<span class="wpd-pe__move">'
                . '<button type="button" class="button-link" data-wpd-move="-1" aria-label="Выше: %3$s"><span class="dashicons dashicons-arrow-up-alt2"></span></button>'
                . '<button type="button" class="button-link" data-wpd-move="1" aria-label="Ниже: %3$s"><span class="dashicons dashicons-arrow-down-alt2"></span></button>'
                . '</span></li>',
                esc_attr($key),
                checked($shown, true, false),
                esc_html($blocks[$key]),
                esc_html($notes[$key]),
                $shown ? '' : ' is-hidden'
            );
        }
        echo '</ul>';

        self::hero_panel($post, $p['hero'], $o);
        // Ссылка «Изменить в настройках» ведёт сразу на нужную вкладку
        self::list_panel('stats', 'Статистика', $p, $o, WPD_Settings::url('stats'));
        self::list_panel('docs', 'Документы', $p, $o, WPD_Settings::url('docs'));
        self::list_panel('faq', 'Частые вопросы', $p, $o, WPD_Settings::url('faq'));

        // Шаблоны новых строк для JavaScript
        foreach (array_keys(self::LISTS) as $list) {
            echo '<template data-wpd-tpl="' . esc_attr($list) . '">' . self::row_html($list, '__i__', []) . '</template>';
        }

        printf(
            '<p class="wpd-pe__foot description">Пустые поля берутся из <a href="%s">общих настроек</a>. Изменения применяются после нажатия «Обновить» / «Сохранить».</p>',
            esc_url($settings)
        );
        echo '</div>';
    }

    private static function hero_panel(WP_Post $post, array $h, array $o): void
    {
        $image_url = $h['image_id'] ? (string) wp_get_attachment_image_url($h['image_id'], 'medium') : '';
        $styles    = ['' => 'Как в общих настройках', 'dark' => 'Тёмный', 'gradient' => 'Градиент основного цвета', 'light' => 'Светлый'];
        $global_style = $styles[$o['hero_style']] ?? 'Тёмный';
        $styles[''] .= ': ' . $global_style;

        echo '<details class="wpd-pe__panel" open><summary>Главный блок</summary><div class="wpd-pe__body">';
        echo '<table class="form-table wpd-pe__table" role="presentation"><tbody>';

        self::text_row('hero][title', 'Заголовок', $h['title'], 'Пусто — заголовок записи');
        self::text_row('hero][badge', 'Метка над заголовком', $h['badge'], $o['badge'] !== '' ? 'Пусто — «' . $o['badge'] . '»' : 'Пусто — без метки');

        printf(
            '<tr><th scope="row"><label for="wpd_pe_lead">Текст под заголовком</label></th><td>'
            . '<textarea id="wpd_pe_lead" name="wpd_page[hero][lead]" rows="3" class="large-text" placeholder="%s">%s</textarea></td></tr>',
            esc_attr('Пусто — цитата записи (если заполнена)'),
            esc_textarea($h['lead'])
        );

        self::text_row('hero][button_text', 'Текст кнопки', $h['button_text'], $o['hero_button_text'] !== '' ? 'Пусто — «' . $o['hero_button_text'] . '»' : '');
        self::text_row('hero][button_url', 'Ссылка кнопки', $h['button_url'], $o['hero_button_url'] !== '' ? 'Пусто — ' . $o['hero_button_url'] : 'https://…', 'url');

        printf(
            '<tr><th scope="row">Изображение</th><td><div class="wpd-pe__media" data-wpd-media>'
            . '<input type="hidden" name="wpd_page[hero][image_id]" value="%1$s" data-wpd-media-id>'
            . '<img src="%2$s" alt="" data-wpd-media-preview%3$s>'
            . '<button type="button" class="button" data-wpd-media-pick>Выбрать изображение</button> '
            . '<button type="button" class="button-link button-link-delete" data-wpd-media-clear%3$s>Убрать</button>'
            . '</div><p class="description">Пусто — изображение записи, иначе из общих настроек.</p></td></tr>',
            esc_attr((string) ($h['image_id'] ?: '')),
            esc_url($image_url),
            $image_url === '' ? ' hidden' : ''
        );

        echo '<tr><th scope="row"><label for="wpd_pe_style">Стиль</label></th><td><select id="wpd_pe_style" name="wpd_page[hero][style]">';
        foreach ($styles as $value => $label) {
            printf('<option value="%s"%s>%s</option>', esc_attr($value), selected($h['style'], $value, false), esc_html($label));
        }
        echo '</select></td></tr>';

        echo '</tbody></table></div></details>';
    }

    private static function text_row(string $name, string $label, string $value, string $placeholder, string $type = 'text'): void
    {
        $id = 'wpd_pe_' . preg_replace('/[^a-z_]/', '', str_replace('][', '_', $name));
        printf(
            '<tr><th scope="row"><label for="%1$s">%2$s</label></th><td>'
            . '<input type="%6$s" id="%1$s" name="wpd_page[%3$s]" value="%4$s" class="regular-text" placeholder="%5$s"></td></tr>',
            esc_attr($id),
            esc_html($label),
            esc_attr($name),
            esc_attr($value),
            esc_attr($placeholder),
            esc_attr($type)
        );
    }

    private static function list_panel(string $list, string $title, array $p, array $o, string $settings_url): void
    {
        $custom = $p[$list . '_mode'] === 'custom';

        switch ($list) {
            case 'stats':
                $global_count = count(WPD_Settings::stats_items($o['stats']));
                $unit         = 'карточек';
                $add          = 'Добавить карточку';
                break;
            case 'docs':
                $global_count = count(WPD_Settings::docs_items($o['docs']));
                $unit         = 'документов';
                $add          = 'Добавить документ';
                break;
            default:
                $global_count = count(WPD_Settings::parse_faq($o['faq']));
                $unit         = 'вопросов';
                $add          = 'Добавить вопрос';
        }

        printf('<details class="wpd-pe__panel"%s><summary>%s</summary><div class="wpd-pe__body">', $custom ? ' open' : '', esc_html($title));

        if ($list !== 'stats') {
            $global_title = $list === 'docs' ? $o['docs_title'] : $o['faq_title'];
            printf(
                '<p><label>Заголовок блока<br><input type="text" name="wpd_page[%1$s_title]" value="%2$s" class="regular-text" placeholder="%3$s"></label></p>',
                esc_attr($list),
                esc_attr($p[$list . '_title']),
                esc_attr('Пусто — «' . $global_title . '»')
            );
        }

        printf(
            '<fieldset class="wpd-pe__mode"><legend class="screen-reader-text">Источник</legend>'
            . '<label><input type="radio" name="wpd_page[%1$s_mode]" value="global"%2$s data-wpd-mode="%1$s"> Из общих настроек</label>'
            . '<label><input type="radio" name="wpd_page[%1$s_mode]" value="custom"%3$s data-wpd-mode="%1$s"> Свои для этой страницы</label>'
            . '</fieldset>',
            esc_attr($list),
            checked(!$custom, true, false),
            checked($custom, true, false)
        );

        printf(
            '<p class="description" data-wpd-global="%1$s"%2$s>Сейчас используются общие значения: %3$s. <a href="%4$s">Изменить в настройках</a>.</p>',
            esc_attr($list),
            $custom ? ' hidden' : '',
            esc_html($global_count ? $global_count . ' ' . $unit : 'список пуст, блок не выводится'),
            esc_url($settings_url)
        );

        printf('<div class="wpd-pe__custom" data-wpd-custom="%s"%s>', esc_attr($list), $custom ? '' : ' hidden');
        echo '<div class="wpd-pe__list wpd-pe__list--' . esc_attr($list) . '" data-wpd-list="' . esc_attr($list) . '">';
        foreach ($p[$list] as $i => $row) {
            echo self::row_html($list, (string) $i, $row); // phpcs:ignore WordPress.Security.EscapeOutput -- экранировано внутри
        }
        echo '</div>';
        printf(
            '<p class="wpd-pe__actions"><button type="button" class="button" data-wpd-add="%1$s">+ %2$s</button> '
            . '<button type="button" class="button-link" data-wpd-copy="%1$s">Скопировать из общих настроек</button></p>',
            esc_attr($list),
            esc_html($add)
        );
        if ($list === 'stats') {
            echo '<p class="description">Числа вида 300, 300+ или 50% анимируются, если анимация включена в общих настройках. Пустой список — блок не выводится.</p>';
        } elseif ($list === 'faq') {
            echo '<p class="description">В ответе разрешены теги a, strong, em, br. Пустой список — блок не выводится.</p>';
        } else {
            echo '<p class="description">Ссылка — PDF или страница. Файл можно выбрать из медиатеки. Строки без ссылки не выводятся.</p>';
        }
        echo '</div></div></details>';
    }

    /**
     * Строка списка. $i — индекс или «__i__» в шаблоне.
     */
    public static function row_html(string $list, string $i, array $row): string
    {
        $name = function (string $key) use ($list, $i) {
            return esc_attr('wpd_page[' . $list . '][' . $i . '][' . $key . ']');
        };
        $val = function (string $key) use ($row) {
            return isset($row[$key]) ? (string) $row[$key] : '';
        };

        $tools = '<span class="wpd-pe__row-tools">'
            . '<span class="wpd-pe__handle dashicons dashicons-menu" aria-hidden="true" title="Перетащить"></span>'
            . '<button type="button" class="button-link" data-wpd-row-move="-1" aria-label="Выше"><span class="dashicons dashicons-arrow-up-alt2"></span></button>'
            . '<button type="button" class="button-link" data-wpd-row-move="1" aria-label="Ниже"><span class="dashicons dashicons-arrow-down-alt2"></span></button>'
            . '<button type="button" class="button-link button-link-delete" data-wpd-row-remove aria-label="Удалить"><span class="dashicons dashicons-trash"></span></button>'
            . '</span>';

        switch ($list) {
            case 'stats':
                $fields = '<input type="text" name="' . $name('num') . '" value="' . esc_attr($val('num')) . '" placeholder="Число, например 300+" class="wpd-pe__num" data-wpd-field="num">'
                    . '<input type="text" name="' . $name('label') . '" value="' . esc_attr($val('label')) . '" placeholder="Подпись" class="wpd-pe__wide" data-wpd-field="label">';
                break;

            case 'docs':
                $fields = '<input type="text" name="' . $name('title') . '" value="' . esc_attr($val('title')) . '" placeholder="Название" class="wpd-pe__wide" data-wpd-field="title">'
                    . '<input type="url" name="' . $name('url') . '" value="' . esc_attr($val('url')) . '" placeholder="https://…" class="wpd-pe__wide" data-wpd-field="url">'
                    . '<button type="button" class="button" data-wpd-file>Файл…</button>';
                break;

            default:
                $fields = '<span class="wpd-pe__stack">'
                    . '<input type="text" name="' . $name('q') . '" value="' . esc_attr($val('q')) . '" placeholder="Вопрос" class="wpd-pe__wide" data-wpd-field="q">'
                    . '<textarea name="' . $name('a') . '" rows="3" placeholder="Ответ" class="wpd-pe__wide" data-wpd-field="a">' . esc_textarea($val('a')) . '</textarea>'
                    . '</span>';
        }

        return '<div class="wpd-pe__row">' . $fields . $tools . '</div>';
    }
}
