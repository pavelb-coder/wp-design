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

    public static function init(): void
    {
        add_shortcode('wpd_page', [__CLASS__, 'sc_page']);
        add_shortcode('wpd_hero', [__CLASS__, 'sc_hero']);
        add_shortcode('wpd_stats', [__CLASS__, 'sc_stats']);
        add_shortcode('wpd_docs', [__CLASS__, 'sc_docs']);
        add_shortcode('wpd_faq', [__CLASS__, 'sc_faq']);

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
        return self::wrap(self::hero_html($o) . self::stats_html($o) . self::docs_html($o) . self::faq_html($o));
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

        $html  = '<section class="wpd-hero"><div class="wpd-hero__inner"><div class="wpd-hero__text">';

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

    private static function stats_html(array $o): string
    {
        $items = WPD_Settings::parse_pairs($o['stats']);
        if (!$items) {
            return '';
        }

        $html = '<section class="wpd-stats" aria-label="Ключевые цифры"><ul class="wpd-stats__grid">';
        foreach ($items as $item) {
            $html .= '<li class="wpd-stat">'
                . '<span class="wpd-stat__num">' . esc_html($item['left']) . '</span>'
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

        $html = '<section class="wpd-faq"><h2 class="wpd-h2">' . esc_html($o['faq_title']) . '</h2>'
            . '<div class="wpd-faq__list">';

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

        $image = get_the_post_thumbnail_url($post_id, 'large');
        $lead  = has_excerpt($post_id) ? get_the_excerpt($post_id) : '';

        $inner = self::hero_html($o, [
            'title' => get_the_title($post_id),
            'lead'  => wp_strip_all_tags((string) $lead),
            'image' => $image ? $image : $o['hero_image_url'],
        ])
        . '<div class="wpd-content">' . $content . '</div>'
        . self::stats_html($o)
        . self::docs_html($o)
        . self::faq_html($o);

        return self::wrap($inner);
    }

    public static function body_class(array $classes): array
    {
        if (is_singular() && WPD_Settings::get()['hide_title'] && self::is_design_enabled(get_queried_object_id())) {
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
        wp_enqueue_style('wpd-design', WPD_URL . 'assets/css/wpd.css', [], WPD_VERSION);
    }
}
