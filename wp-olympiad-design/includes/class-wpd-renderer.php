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

    public static function init(): void
    {
        add_shortcode('wpd_page', [__CLASS__, 'sc_page']);
        add_shortcode('wpd_hero', [__CLASS__, 'sc_hero']);
        add_shortcode('wpd_stats', [__CLASS__, 'sc_stats']);
        add_shortcode('wpd_docs', [__CLASS__, 'sc_docs']);
        add_shortcode('wpd_faq', [__CLASS__, 'sc_faq']);
        add_shortcode('wpd_countdown', [__CLASS__, 'sc_countdown']);

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
    private static function wrap(string $inner): string
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

        return '<div class="wpd-page" style="' . esc_attr($vars) . '">' . $inner . '</div>';
    }

    /**
     * Главный блок. В режиме страницы заголовок и текст берутся из записи.
     *
     * @param array $args title, lead, image
     */
    private static function hero_html(array $o, array $args = []): string
    {
        $title = isset($args['title']) ? $args['title'] : $o['hero_title'];
        $lead  = array_key_exists('lead', $args) ? $args['lead'] : $o['hero_text'];
        $image = isset($args['image']) ? $args['image'] : $o['hero_image_url'];

        $style = in_array($o['hero_style'], ['dark', 'gradient', 'light'], true) ? $o['hero_style'] : 'dark';
        $html  = '<section class="wpd-hero wpd-hero--' . $style . '"><div class="wpd-hero__inner"><div class="wpd-hero__text">';

        if ($o['badge'] !== '') {
            $html .= '<span class="wpd-badge">' . esc_html($o['badge']) . '</span>';
        }

        $html .= '<h1 class="wpd-hero__title">' . esc_html($title) . '</h1>';

        if ($lead !== '') {
            $html .= '<p class="wpd-hero__lead">' . nl2br(esc_html($lead)) . '</p>';
        }

        $button_url = esc_url($o['hero_button_url']);
        if ($o['hero_button_text'] !== '' && $button_url !== '') {
            $html .= '<a class="wpd-btn" href="' . $button_url . '">' . esc_html($o['hero_button_text']) . ' &rarr;</a>';
        }

        $html .= '</div>';

        $image_url = esc_url($image);
        if ($image_url !== '') {
            $html .= '<div class="wpd-hero__media"><img src="' . $image_url . '" alt="" loading="lazy"></div>';
        }

        $html .= '</div></section>';

        return $html;
    }

    /**
     * Обратный отсчёт до даты события. Без JavaScript показывается сама дата.
     */
    private static function countdown_html(array $o): string
    {
        if (empty($o['countdown_enabled']) || $o['countdown_date'] === '') {
            return '';
        }

        $tz = wp_timezone();
        $dt = DateTime::createFromFormat('Y-m-d H:i|', $o['countdown_date'], $tz);
        if (!$dt) {
            return '';
        }

        $shown = wp_date(get_option('date_format') . ' H:i', $dt->getTimestamp(), $tz);

        $html = '<div class="wpd-countdown" data-target="' . esc_attr($dt->format(DATE_ATOM)) . '"'
            . ' data-done="' . esc_attr($o['countdown_done']) . '">';
        if ($o['countdown_label'] !== '') {
            $html .= '<p class="wpd-countdown__label">' . esc_html($o['countdown_label']) . '</p>';
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

    private static function stats_html(array $o): string
    {
        $items = WPD_Settings::parse_pairs($o['stats']);
        if (!$items) {
            return '';
        }

        $html = '<section class="wpd-stats" aria-label="Ключевые цифры"><ul class="wpd-stats__grid">';
        foreach ($items as $item) {
            $attrs = $o['stats_animate'] ? self::count_attrs($item['left']) : '';
            $html .= '<li class="wpd-stat">'
                . '<span class="wpd-stat__num"' . $attrs . '>' . esc_html($item['left']) . '</span>'
                . '<span class="wpd-stat__label">' . esc_html($item['right']) . '</span>'
                . '</li>';
        }
        return $html . '</ul></section>';
    }

    private static function docs_html(array $o): string
    {
        $items = WPD_Settings::parse_pairs($o['docs']);
        $list  = '';

        foreach ($items as $item) {
            $url = esc_url($item['right']);
            if ($url === '') {
                continue;
            }
            $is_pdf = (bool) preg_match('/\.pdf(\?|#|$)/i', $item['right']);
            $list .= '<li><a class="wpd-doc" href="' . $url . '">'
                . '<span class="wpd-doc__icon" aria-hidden="true">' . ($is_pdf ? 'PDF' : 'WEB') . '</span>'
                . '<span class="wpd-doc__name">' . esc_html($item['left']) . '</span>'
                . '</a></li>';
        }

        if ($list === '') {
            return '';
        }

        return '<section class="wpd-docs"><h2 class="wpd-h2">' . esc_html($o['docs_title']) . '</h2>'
            . '<ul class="wpd-docs__list">' . $list . '</ul></section>';
    }

    private static function faq_html(array $o): string
    {
        $items = WPD_Settings::parse_faq($o['faq']);
        if (!$items) {
            return '';
        }

        $searchable = !empty($o['faq_search']) && count($items) >= self::FAQ_SEARCH_MIN;

        $html = '<section class="wpd-faq"' . ($searchable ? ' data-wpd-search' : '') . '>'
            . '<h2 class="wpd-h2">' . esc_html($o['faq_title']) . '</h2>';

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

        $o = WPD_Settings::get();

        // Если размера large нет (маленький оригинал или вложение без метаданных), берём оригинал.
        $image = get_the_post_thumbnail_url($post_id, 'large') ?: get_the_post_thumbnail_url($post_id, 'full');
        $lead  = has_excerpt($post_id) ? get_the_excerpt($post_id) : '';

        $inner = self::hero_html($o, [
            'title' => get_the_title($post_id),
            'lead'  => wp_strip_all_tags((string) $lead),
            'image' => $image ? $image : $o['hero_image_url'],
        ])
        . self::countdown_html($o)
        . '<div class="wpd-content">' . $content . '</div>'
        . self::stats_html($o)
        . self::docs_html($o)
        . self::faq_html($o);

        return self::wrap($inner);
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

    private static function is_design_enabled(int $post_id): bool
    {
        return (bool) get_post_meta($post_id, self::META_KEY, true);
    }

    /* ---------------------------------------------------------------
     * Мета-бокс в редакторе
     * ------------------------------------------------------------- */

    public static function add_meta_box(): void
    {
        foreach (['page', 'post'] as $type) {
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

    public static function meta_box_html(WP_Post $post): void
    {
        wp_nonce_field('wpd_save_meta', 'wpd_nonce');
        $checked = checked(self::is_design_enabled($post->ID), true, false);
        $settings_url = admin_url('options-general.php?page=' . WPD_Settings::PAGE);

        printf(
            '<label><input type="checkbox" name="wpd_enabled" value="1" %s> Включить оформление</label>'
            . '<p class="description">Заголовок станет главным блоком, ниже будут статистика, документы и FAQ. '
            . 'Содержимое блоков задаётся в <a href="%s">настройках</a>.</p>',
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
        return false;
    }

    private static function enqueue_assets(): void
    {
        if (!wp_style_is('wpd-design', 'enqueued')) {
            wp_enqueue_style('wpd-design', WPD_URL . 'assets/css/wpd.css', [], WPD_VERSION);
        }
        if (!wp_script_is('wpd-design', 'enqueued')) {
            wp_enqueue_script('wpd-design', WPD_URL . 'assets/js/wpd.js', [], WPD_VERSION, true);
        }
    }
}
