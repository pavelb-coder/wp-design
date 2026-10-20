<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Вывод блоков: шорткоды, оформление страниц через мета-поле, подключение стилей.
 */
class WPD_Renderer
{
    const META_KEY = '_wpd_enabled';

    /** Шорткоды плагина: по ним определяем, нужен ли CSS на странице. */
    const SHORTCODES = ['wpd_page', 'wpd_hero', 'wpd_stats', 'wpd_docs', 'wpd_faq', 'wpd_countdown'];

    /** Минимум вопросов, при котором появляется поиск по FAQ. */
    const FAQ_SEARCH_MIN = 4;

    /** Варианты главного блока. */
    const HERO_STYLES = ['dark', 'gradient', 'light'];

    public static function init(): void
    {
        add_shortcode('wpd_page', [__CLASS__, 'sc_page']);
        add_shortcode('wpd_hero', [__CLASS__, 'sc_hero']);
        add_shortcode('wpd_stats', [__CLASS__, 'sc_stats']);
        add_shortcode('wpd_docs', [__CLASS__, 'sc_docs']);
        add_shortcode('wpd_faq', [__CLASS__, 'sc_faq']);
        add_shortcode('wpd_countdown', [__CLASS__, 'sc_countdown']);

        add_action('init', [__CLASS__, 'register_assets']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue']);
        add_filter('the_content', [__CLASS__, 'filter_content'], 20);
        add_filter('body_class', [__CLASS__, 'body_class']);

        add_action('add_meta_boxes', [__CLASS__, 'add_meta_box']);
        add_action('save_post', [__CLASS__, 'save_meta'], 10, 2);
    }

    /* ---------------------------------------------------------------
     * Шорткоды
     * ------------------------------------------------------------- */

    public static function sc_page(): string
    {
        $o = WPD_Settings::get();
        return self::wrap(self::hero_html($o) . self::countdown_html($o) . self::stats_html($o)
            . self::docs_html($o) . self::faq_html($o));
    }

    public static function sc_countdown(): string
    {
        $html = self::countdown_html(WPD_Settings::get());
        return $html === '' ? '' : self::wrap($html);
    }

    public static function sc_hero(): string
    {
        return self::wrap(self::hero_html(WPD_Settings::get()));
    }

    public static function sc_stats(): string
    {
        $html = self::stats_html(WPD_Settings::get());
        return $html === '' ? '' : self::wrap($html);
    }

    public static function sc_docs(): string
    {
        $html = self::docs_html(WPD_Settings::get());
        return $html === '' ? '' : self::wrap($html);
    }

    public static function sc_faq(): string
    {
        $html = self::faq_html(WPD_Settings::get());
        return $html === '' ? '' : self::wrap($html);
    }

    /* ---------------------------------------------------------------
     * Разметка блоков
     * ------------------------------------------------------------- */

    /**
     * Корневой контейнер со CSS-переменными из настроек.
     */
    public static function wrap(string $inner, string $extra_class = ''): string
    {
        // Страховка: если CSS не подключён заранее (шорткод в виджете, шаблоне), подключаем здесь.
        self::enqueue_assets();

        $o = WPD_Settings::get();
        $vars = sprintf(
            '--wpd-primary:%s;--wpd-accent:%s;--wpd-dark:%s;--wpd-text:%s;--wpd-bg:%s;--wpd-radius:%dpx;--wpd-font:%s;',
            $o['primary'],
            $o['accent'],
            $o['dark'],
            $o['text'],
            $o['bg'],
            (int) $o['radius'],
            $o['font']
        );

        $class = trim('wpd-page ' . $extra_class);
        return '<div class="' . esc_attr($class) . '" style="' . esc_attr($vars) . '">' . $inner . '</div>';
    }

    /**
     * Приводит список элементов к виду [['ключ' => 'строка', ...], ...]: лишнее отбрасывает, недостающее заполняет пустой строкой.
     */
    public static function clean_items(array $items, array $keys): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $row = [];
            foreach ($keys as $key) {
                $row[$key] = isset($item[$key]) && is_scalar($item[$key]) ? trim((string) $item[$key]) : '';
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Главный блок. Значения по умолчанию берутся из общих настроек,
     * в $args можно передать свои (страница, блок редактора).
     *
     * @param array $args title, lead, image, badge, button_text, button_url, style
     */
    public static function hero_html(array $o, array $args = []): string
    {
        $a = array_merge([
            'title'       => $o['hero_title'],
            'lead'        => $o['hero_text'],
            'image'       => $o['hero_image_url'],
            'badge'       => $o['badge'],
            'button_text' => $o['hero_button_text'],
            'button_url'  => $o['hero_button_url'],
            'style'       => $o['hero_style'],
        ], array_filter($args, function ($v) {
            return $v !== null;
        }));
        $a = array_map(function ($v) {
            return is_scalar($v) ? (string) $v : '';
        }, $a);

        $style = in_array($a['style'], self::HERO_STYLES, true) ? $a['style'] : 'dark';
        $html  = '<section class="wpd-hero wpd-hero--' . $style . '"><div class="wpd-hero__inner"><div class="wpd-hero__text">';

        if ($a['badge'] !== '') {
            $html .= '<span class="wpd-badge">' . esc_html($a['badge']) . '</span>';
        }

        $html .= '<h1 class="wpd-hero__title">' . esc_html($a['title']) . '</h1>';

        if ($a['lead'] !== '') {
            $html .= '<p class="wpd-hero__lead">' . nl2br(esc_html($a['lead'])) . '</p>';
        }

        $button_url = esc_url($a['button_url']);
        if ($a['button_text'] !== '' && $button_url !== '') {
            $html .= '<a class="wpd-btn" href="' . $button_url . '">' . esc_html($a['button_text']) . ' &rarr;</a>';
        }

        $html .= '</div>';

        $image_url = esc_url($a['image']);
        if ($image_url !== '') {
            $html .= '<div class="wpd-hero__media"><img src="' . $image_url . '" alt="" loading="lazy"></div>';
        }

        $html .= '</div></section>';

        return $html;
    }

    /**
     * Обратный отсчёт до даты события. Без JavaScript показывается сама дата.
     */
    public static function countdown_html(array $o, array $args = []): string
    {
        $enabled = array_key_exists('enabled', $args) ? (bool) $args['enabled'] : !empty($o['countdown_enabled']);
        $date    = isset($args['date']) ? WPD_Settings::normalize_datetime((string) $args['date']) : (string) $o['countdown_date'];
        $label   = isset($args['label']) ? (string) $args['label'] : (string) $o['countdown_label'];
        $done    = isset($args['done']) ? (string) $args['done'] : (string) $o['countdown_done'];

        if (!$enabled || $date === '') {
            return '';
        }

        $tz = wp_timezone();
        $dt = DateTime::createFromFormat('Y-m-d H:i|', $date, $tz);
        if (!$dt) {
            return '';
        }

        $shown = wp_date(get_option('date_format') . ' H:i', $dt->getTimestamp(), $tz);

        $html = '<div class="wpd-countdown" data-target="' . esc_attr($dt->format(DATE_ATOM)) . '"'
            . ' data-done="' . esc_attr($done) . '">';
        if ($label !== '') {
            $html .= '<p class="wpd-countdown__label">' . esc_html($label) . '</p>';
        }
        return $html . '<p class="wpd-countdown__value">' . esc_html($shown) . '</p></div>';
    }

    /**
     * Атрибуты для анимации: только значения вида «300», «300+» или «50%».
     */
    private static function count_attrs(string $value): string
    {
        if (!preg_match('/^(\d{1,7})([+%]?)$/', $value, $m)) {
            return '';
        }
        return ' data-count="' . (int) $m[1] . '" data-suffix="' . esc_attr($m[2]) . '"';
    }

    /**
     * @param array|null $items [['num' => ..., 'label' => ...]]; null — из общих настроек
     */
    public static function stats_html(array $o, ?array $items = null): string
    {
        $items = $items === null ? WPD_Settings::stats_items($o['stats']) : self::clean_items($items, ['num', 'label']);
        $html  = '';

        foreach ($items as $item) {
            if ($item['num'] === '' && $item['label'] === '') {
                continue;
            }
            $attrs = $o['stats_animate'] ? self::count_attrs($item['num']) : '';
            $html .= '<li class="wpd-stat">'
                . '<span class="wpd-stat__num"' . $attrs . '>' . esc_html($item['num']) . '</span>'
                . '<span class="wpd-stat__label">' . esc_html($item['label']) . '</span>'
                . '</li>';
        }

        if ($html === '') {
            return '';
        }
        return '<section class="wpd-stats" aria-label="Ключевые цифры"><ul class="wpd-stats__grid">' . $html . '</ul></section>';
    }

    /**
     * @param array|null  $items [['title' => ..., 'url' => ...]]; null — из общих настроек
     * @param string|null $title заголовок блока; null или пусто — из общих настроек
     */
    public static function docs_html(array $o, ?array $items = null, ?string $title = null): string
    {
        $items = $items === null ? WPD_Settings::docs_items($o['docs']) : self::clean_items($items, ['title', 'url']);
        $title = ($title === null || $title === '') ? $o['docs_title'] : $title;
        $list  = '';

        foreach ($items as $item) {
            $url = esc_url($item['url']);
            if ($url === '') {
                continue;
            }
            $name   = $item['title'] !== '' ? $item['title'] : $item['url'];
            $is_pdf = (bool) preg_match('/\.pdf(\?|#|$)/i', $item['url']);
            $list .= '<li><a class="wpd-doc" href="' . $url . '">'
                . '<span class="wpd-doc__icon" aria-hidden="true">' . ($is_pdf ? 'PDF' : 'WEB') . '</span>'
                . '<span class="wpd-doc__name">' . esc_html($name) . '</span>'
                . '</a></li>';
        }

        if ($list === '') {
            return '';
        }

        return '<section class="wpd-docs"><h2 class="wpd-h2">' . esc_html($title) . '</h2>'
            . '<ul class="wpd-docs__list">' . $list . '</ul></section>';
    }

    /**
     * @param array|null  $items [['q' => ..., 'a' => ...]]; null — из общих настроек
     * @param string|null $title заголовок блока; null или пусто — из общих настроек
     */
    public static function faq_html(array $o, ?array $items = null, ?string $title = null): string
    {
        $items = $items === null ? WPD_Settings::parse_faq($o['faq']) : self::clean_items($items, ['q', 'a']);
        $items = array_values(array_filter($items, function ($item) {
            return $item['q'] !== '';
        }));
        $title = ($title === null || $title === '') ? $o['faq_title'] : $title;
        if (!$items) {
            return '';
        }

        $searchable = !empty($o['faq_search']) && count($items) >= self::FAQ_SEARCH_MIN;

        $html = '<section class="wpd-faq"' . ($searchable ? ' data-wpd-search' : '') . '>'
            . '<h2 class="wpd-h2">' . esc_html($title) . '</h2>';

        if ($searchable) {
            // hidden по умолчанию: без JavaScript поиск не показывается
            $html .= '<input type="search" class="wpd-faq__search" placeholder="Поиск по вопросам"'
                . ' aria-label="Поиск по вопросам" hidden>'
                . '<p class="wpd-faq__empty" hidden>Ничего не найдено. Попробуйте другие слова.</p>';
        }

        $html .= '<div class="wpd-faq__list">';

        foreach ($items as $item) {
            $html .= '<details class="wpd-faq__item">'
                . '<summary class="wpd-faq__q">' . esc_html($item['q']) . '</summary>'
                . '<div class="wpd-faq__a">' . wp_kses(nl2br($item['a']), WPD_Settings::allowed_html()) . '</div>'
                . '</details>';
        }

        return $html . '</div></section>';
    }

    /* ---------------------------------------------------------------
     * Оформление страницы целиком
     * ------------------------------------------------------------- */

    public static function filter_content($content)
    {
        if (!is_singular() || !in_the_loop() || !is_main_query()) {
            return $content;
        }

        $post_id = get_the_ID();
        if (!$post_id || !self::is_design_enabled($post_id)) {
            return $content;
        }

        return self::page_html((int) $post_id, (string) $content);
    }

    /**
     * Оформленная страница: блоки в порядке и с содержимым из панели «Блоки олимпиады».
     * Пустые поля панели берутся из общих настроек.
     */
    public static function page_html(int $post_id, string $content): string
    {
        $o = WPD_Settings::get();
        $p = WPD_Page_Editor::get($post_id);

        $inner = '';
        foreach ($p['order'] as $key) {
            if (in_array($key, $p['hidden'], true)) {
                continue;
            }
            switch ($key) {
                case 'hero':
                    $inner .= self::hero_html($o, self::page_hero_args($post_id, $p['hero'], $o));
                    break;

                case 'countdown':
                    $inner .= self::countdown_html($o);
                    break;

                case 'content':
                    if (trim($content) !== '') {
                        $inner .= '<div class="wpd-content">' . $content . '</div>';
                    }
                    break;

                case 'stats':
                    $inner .= self::stats_html($o, $p['stats_mode'] === 'custom' ? $p['stats'] : null);
                    break;

                case 'docs':
                    $inner .= self::docs_html($o, $p['docs_mode'] === 'custom' ? $p['docs'] : null, $p['docs_title']);
                    break;

                case 'faq':
                    $inner .= self::faq_html($o, $p['faq_mode'] === 'custom' ? $p['faq'] : null, $p['faq_title']);
                    break;
            }
        }

        return self::wrap($inner);
    }

    /**
     * Аргументы главного блока для страницы.
     * Приоритет: поле панели → данные записи (заголовок, цитата, изображение записи) → общие настройки.
     */
    private static function page_hero_args(int $post_id, array $h, array $o): array
    {
        $image = '';
        if (!empty($h['image_id'])) {
            $image = (string) (wp_get_attachment_image_url((int) $h['image_id'], 'large') ?: wp_get_attachment_url((int) $h['image_id']));
        }
        if ($image === '') {
            // Если размера large нет (маленький оригинал или вложение без метаданных), берём оригинал.
            $image = (string) (get_the_post_thumbnail_url($post_id, 'large') ?: get_the_post_thumbnail_url($post_id, 'full'));
        }

        if ($h['lead'] !== '') {
            $lead = $h['lead'];
        } else {
            $lead = has_excerpt($post_id) ? wp_strip_all_tags((string) get_the_excerpt($post_id)) : '';
        }

        return [
            'title'       => $h['title'] !== '' ? $h['title'] : get_the_title($post_id),
            'lead'        => $lead,
            'image'       => $image !== '' ? $image : $o['hero_image_url'],
            'badge'       => $h['badge'] !== '' ? $h['badge'] : null,
            'button_text' => $h['button_text'] !== '' ? $h['button_text'] : null,
            'button_url'  => $h['button_url'] !== '' ? $h['button_url'] : null,
            'style'       => $h['style'] !== '' ? $h['style'] : null,
        ];
    }

    public static function body_class(array $classes): array
    {
        // Сначала дешёвая проверка мета-поля, настройки читаем только для оформленных страниц.
        if (!is_singular() || !self::is_design_enabled(get_queried_object_id())) {
            return $classes;
        }
        if (WPD_Settings::get()['hide_title']) {
            $classes[] = 'wpd-has-design';
        }
        return $classes;
    }

    public static function is_design_enabled(int $post_id): bool
    {
        return (bool) get_post_meta($post_id, self::META_KEY, true);
    }

    /* ---------------------------------------------------------------
     * Мета-бокс в редакторе
     * ------------------------------------------------------------- */

    public static function add_meta_box(): void
    {
        foreach (self::post_types() as $type) {
            add_meta_box(
                'wpd_design',
                'Оформление в стиле олимпиады',
                [__CLASS__, 'meta_box_html'],
                $type,
                'side',
                'high'
            );
        }
    }

    /**
     * Типы записей, для которых доступно оформление. Фильтр wpd_post_types позволяет добавить свои.
     */
    public static function post_types(): array
    {
        return (array) apply_filters('wpd_post_types', ['page', 'post']);
    }

    public static function meta_box_html(WP_Post $post): void
    {
        wp_nonce_field('wpd_save_meta', 'wpd_nonce');
        $checked = checked(self::is_design_enabled($post->ID), true, false);
        $settings_url = admin_url('options-general.php?page=' . WPD_Settings::PAGE);

        printf(
            '<label><input type="checkbox" name="wpd_enabled" value="1" %s> Включить оформление</label>'
            . '<p class="description">Заголовок станет главным блоком, ниже будут статистика, документы и FAQ. '
            . 'Порядок и содержимое блоков для этой страницы меняются в панели «Блоки олимпиады» под редактором, '
            . 'общие значения — в <a href="%s">настройках</a>.</p>',
            $checked,
            esc_url($settings_url)
        );
    }

    public static function save_meta(int $post_id, WP_Post $post): void
    {
        if (!isset($_POST['wpd_nonce'])) {
            return;
        }
        $nonce = sanitize_text_field(wp_unslash($_POST['wpd_nonce']));
        if (!wp_verify_nonce($nonce, 'wpd_save_meta')) {
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

        update_post_meta($post_id, self::META_KEY, isset($_POST['wpd_enabled']) ? 1 : 0);
    }

    /* ---------------------------------------------------------------
     * Ассеты
     * ------------------------------------------------------------- */

    /**
     * Регистрация стилей и скрипта: ими пользуются и сайт, и редактор блоков.
     */
    public static function register_assets(): void
    {
        wp_register_style('wpd-design', WPD_URL . 'assets/css/wpd.css', [], WPD_VERSION);
        wp_register_script('wpd-design', WPD_URL . 'assets/js/wpd.js', [], WPD_VERSION, true);
    }

    public static function enqueue(): void
    {
        if (self::page_needs_assets()) {
            self::enqueue_assets();
        }
    }

    /**
     * Нужен ли CSS на текущей странице: оформленная запись или запись с шорткодом плагина.
     * Фильтр wpd_always_enqueue_assets позволяет подключать CSS на всех страницах.
     */
    private static function page_needs_assets(): bool
    {
        if (apply_filters('wpd_always_enqueue_assets', false)) {
            return true;
        }
        if (!is_singular()) {
            return false;
        }
        $post = get_queried_object();
        if (!$post instanceof WP_Post) {
            return false;
        }
        if (self::is_design_enabled((int) $post->ID)) {
            return true;
        }
        foreach (self::SHORTCODES as $tag) {
            if (has_shortcode($post->post_content, $tag)) {
                return true;
            }
        }
        // Блоки редактора «Олимпиада: …»
        return strpos($post->post_content, '<!-- wp:' . WPD_Blocks::NS . '/') !== false;
    }

    public static function enqueue_assets(): void
    {
        if (!wp_style_is('wpd-design', 'registered')) {
            self::register_assets();
        }
        if (!wp_style_is('wpd-design', 'enqueued')) {
            wp_enqueue_style('wpd-design');
        }
        if (!wp_script_is('wpd-design', 'enqueued')) {
            wp_enqueue_script('wpd-design');
        }
    }
}
