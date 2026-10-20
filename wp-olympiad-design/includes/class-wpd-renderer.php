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
    const SHORTCODES = ['wpd_page', 'wpd_hero', 'wpd_stats', 'wpd_docs', 'wpd_faq', 'wpd_countdown',
        'wpd_slider', 'wpd_buttons', 'wpd_cards', 'wpd_notice', 'wpd_news', 'wpd_contacts'];

    /** Компоненты (1.5.0), которым нужны иконки Dashicons на сайте. */
    const ICON_PARTS = ['buttons', 'contacts'];

    /** Варианты объявления и ленты новостей. */
    const NOTICE_LAYOUTS = ['image_text', 'text', 'image'];
    const NEWS_LAYOUTS   = ['carousel', 'grid'];

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
        foreach (['slider', 'buttons', 'cards', 'notice', 'news', 'contacts'] as $part) {
            add_shortcode('wpd_' . $part, function () use ($part) {
                return self::part_shortcode($part);
            });
        }

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

    /**
     * Шорткоды компонентов 1.5.0: содержимое из общих настроек, пустой компонент ничего не выводит.
     */
    public static function part_shortcode(string $part): string
    {
        $html = self::part_html($part, WPD_Settings::get());
        return $html === '' ? '' : self::wrap($html);
    }

    /**
     * Разметка компонента с содержимым из общих настроек.
     */
    public static function part_html(string $part, array $o): string
    {
        switch ($part) {
            case 'slider':
                return self::slider_html($o);
            case 'buttons':
                return self::buttons_html($o);
            case 'cards':
                return self::cards_html($o);
            case 'notice':
                return self::notice_html($o);
            case 'news':
                return self::news_html($o);
            case 'contacts':
                return self::contacts_html($o);
        }
        return '';
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
     * Обратный отсчёт: до начала, затем (если задана дата окончания) до окончания, затем текст «прошло».
     * Кнопка-ссылка видна только между началом и окончанием (без даты окончания — после начала).
     * Этап определяется на сервере (страница без JavaScript сразу верная), скрипт переключает этапы на лету.
     *
     * @param array $args enabled, date, label, end, end_label, done, link_text, link_url, link_new_tab
     */
    public static function countdown_html(array $o, array $args = []): string
    {
        $enabled = array_key_exists('enabled', $args) ? (bool) $args['enabled'] : !empty($o['countdown_enabled']);
        $date    = isset($args['date']) ? WPD_Settings::normalize_datetime((string) $args['date']) : (string) $o['countdown_date'];
        $label   = isset($args['label']) ? (string) $args['label'] : (string) $o['countdown_label'];
        $end     = isset($args['end']) ? WPD_Settings::normalize_datetime((string) $args['end']) : (string) $o['countdown_end'];
        $end_lbl = isset($args['end_label']) ? (string) $args['end_label'] : (string) $o['countdown_end_label'];
        $done    = isset($args['done']) ? (string) $args['done'] : (string) $o['countdown_done'];
        $l_text  = isset($args['link_text']) ? (string) $args['link_text'] : (string) $o['countdown_link_text'];
        $l_url   = isset($args['link_url']) ? (string) $args['link_url'] : (string) $o['countdown_link_url'];
        $l_new   = array_key_exists('link_new_tab', $args) && $args['link_new_tab'] !== null ? (bool) $args['link_new_tab'] : !empty($o['countdown_link_new_tab']);

        if (!$enabled || $date === '') {
            return '';
        }

        $tz = WPD_Settings::countdown_timezone($o);
        $dt = DateTime::createFromFormat('Y-m-d H:i|', $date, $tz);
        if (!$dt) {
            return '';
        }
        $end_dt = $end !== '' ? DateTime::createFromFormat('Y-m-d H:i|', $end, $tz) : false;
        if ($end_dt && $end_dt->getTimestamp() <= $dt->getTimestamp()) {
            $end_dt = false; // окончание не позже начала — второго таймера нет
        }

        $now   = time();
        $start = $dt->getTimestamp();
        if ($now < $start) {
            $phase = 'before';
        } elseif ($end_dt && $now < $end_dt->getTimestamp()) {
            $phase = 'active';
        } else {
            $phase = 'done';
        }
        $link_url  = esc_url($l_url);
        $has_link  = $link_url !== '' && $l_text !== '';
        $link_open = $now >= $start && (!$end_dt || $now < $end_dt->getTimestamp());

        $format = get_option('date_format') . ' H:i';
        $tz_abbr = WPD_Settings::countdown_tz_label($o);
        $suffix  = $tz_abbr !== '' ? ' ' . $tz_abbr : '';
        switch ($phase) {
            case 'before':
                $shown_label = $label;
                $shown_value = wp_date($format, $start, $tz) . $suffix;
                break;
            case 'active':
                $shown_label = $end_lbl;
                $shown_value = wp_date($format, $end_dt->getTimestamp(), $tz) . $suffix;
                break;
            default:
                $shown_label = '';
                $shown_value = $done;
        }

        $html = '<div class="wpd-countdown" data-target="' . esc_attr($dt->format(DATE_ATOM)) . '"'
            . ($end_dt ? ' data-end="' . esc_attr($end_dt->format(DATE_ATOM)) . '"' : '')
            . ' data-label="' . esc_attr($label) . '"'
            . ($end_dt ? ' data-end-label="' . esc_attr($end_lbl) . '"' : '')
            . ' data-done="' . esc_attr($done) . '" data-phase="' . $phase . '">';
        $html .= '<div class="wpd-countdown__main">';
        $html .= '<p class="wpd-countdown__label"' . ($shown_label === '' ? ' hidden' : '') . '>' . esc_html($shown_label) . '</p>';
        $html .= '<p class="wpd-countdown__value">' . esc_html($shown_value) . '</p>';
        $html .= '</div>';
        if ($has_link) {
            $html .= '<a class="wpd-btn wpd-countdown__link" href="' . $link_url . '"'
                . ($l_new ? ' target="_blank" rel="noopener noreferrer"' : '')
                . ($link_open ? '' : ' hidden') . '>' . esc_html($l_text) . ' &rarr;</a>';
        }
        return $html . '</div>';
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
     * Компоненты 1.5.0: слайдер, кнопки-ссылки, карточки, объявление, новости, контакты
     * ------------------------------------------------------------- */

    /**
     * Атрибуты ссылки: адрес и, при необходимости, открытие в новой вкладке.
     */
    private static function link_attrs(string $url, $new_tab): string
    {
        $attrs = ' href="' . esc_url($url) . '"';
        if ($new_tab) {
            $attrs .= ' target="_blank" rel="noopener noreferrer"';
        }
        return $attrs;
    }

    /**
     * Иконки Dashicons на сайте. Подключаются только там, где выводятся кнопки или контакты.
     */
    public static function enqueue_icons(): void
    {
        if (!wp_style_is('dashicons', 'enqueued')) {
            wp_enqueue_style('dashicons');
        }
    }

    /**
     * Значение из $args, если оно передано (не null), иначе из общих настроек.
     */
    private static function arg(array $args, string $key, $fallback)
    {
        return array_key_exists($key, $args) && $args[$key] !== null ? $args[$key] : $fallback;
    }

    /**
     * Слайдер. Без JavaScript слайды листаются прокруткой (scroll-snap), скрипт добавляет стрелки, точки и автопрокрутку.
     *
     * @param array $args items, height, autoplay, arrows, dots, mobile, mobile_headings, mobile_texts
     */
    public static function slider_html(array $o, array $args = []): string
    {
        $items = WPD_Settings::sanitize_rows(self::arg($args, 'items', $o['slides']), 'slides');
        if (!$items) {
            return '';
        }

        $height  = min(900, max(200, (int) self::arg($args, 'height', $o['slider_height'])));
        $classes = ['wpd-slider'];
        if (!self::arg($args, 'mobile', $o['slider_mobile'])) {
            $classes[] = 'wpd-slider--hide-mobile';
        }
        if (!self::arg($args, 'mobile_headings', $o['slider_mobile_headings'])) {
            $classes[] = 'wpd-slider--no-mobile-headings';
        }
        if (!self::arg($args, 'mobile_texts', $o['slider_mobile_texts'])) {
            $classes[] = 'wpd-slider--no-mobile-texts';
        }

        $count = count($items);
        $html  = sprintf(
            '<section class="%s" style="--wpd-slider-h:%dpx" aria-roledescription="carousel" aria-label="Слайдер"'
            . ' data-wpd-carousel="slider"%s%s%s>',
            esc_attr(implode(' ', $classes)),
            $height,
            self::arg($args, 'autoplay', $o['slider_autoplay']) ? ' data-autoplay="6000"' : '',
            self::arg($args, 'arrows', $o['slider_arrows']) ? ' data-arrows' : '',
            self::arg($args, 'dots', $o['slider_dots']) ? ' data-dots' : ''
        );
        $html .= '<div class="wpd-slider__track" data-wpd-track tabindex="0">';

        foreach ($items as $i => $item) {
            $inner = '';
            if ($item['image'] !== '') {
                $inner .= '<img class="wpd-slide__img" src="' . esc_url($item['image']) . '" alt=""'
                    . ($i === 0 ? '' : ' loading="lazy"') . '>';
            }
            if ($item['heading'] !== '' || $item['text'] !== '') {
                $inner .= '<div class="wpd-slide__caption">';
                if ($item['heading'] !== '') {
                    $inner .= '<h2 class="wpd-slide__title">' . esc_html($item['heading']) . '</h2>';
                }
                if ($item['text'] !== '') {
                    $inner .= '<p class="wpd-slide__text">' . nl2br(esc_html($item['text'])) . '</p>';
                }
                $inner .= '</div>';
            }
            if ($item['url'] !== '') {
                $inner = '<a class="wpd-slide__link"' . self::link_attrs($item['url'], $item['new_tab']) . '>' . $inner . '</a>';
            }
            $html .= sprintf(
                '<div class="wpd-slide%s" role="group" aria-roledescription="slide" aria-label="%d из %d">%s</div>',
                $item['image'] === '' ? ' wpd-slide--noimg' : '',
                $i + 1,
                $count,
                $inner
            );
        }
        $html .= '</div>';

        // Управление показывает скрипт: без него оно не работает, поэтому скрыто
        if ($count > 1) {
            $html .= '<button type="button" class="wpd-slider__arrow wpd-slider__arrow--prev" data-wpd-prev hidden aria-label="Предыдущий слайд">&#8249;</button>'
                . '<button type="button" class="wpd-slider__arrow wpd-slider__arrow--next" data-wpd-next hidden aria-label="Следующий слайд">&#8250;</button>'
                . '<div class="wpd-slider__dots" data-wpd-dots hidden></div>';
        }
        return $html . '</section>';
    }

    /**
     * Кнопки-ссылки с иконками. Кнопка без ссылки выводится как подпись.
     *
     * @param array|null $items null — из общих настроек
     */
    public static function buttons_html(array $o, ?array $items = null): string
    {
        $items = WPD_Settings::sanitize_rows($items === null ? $o['buttons'] : $items, 'buttons');
        $items = array_values(array_filter($items, function ($item) {
            return $item['text'] !== '';
        }));
        if (!$items) {
            return '';
        }
        self::enqueue_icons();

        $html = '<nav class="wpd-buttons" aria-label="Быстрые ссылки"><ul class="wpd-buttons__grid">';
        foreach ($items as $item) {
            $class = 'wpd-qbtn' . ($item['small'] ? ' wpd-qbtn--small' : '') . ($item['url'] === '' ? ' wpd-qbtn--nolink' : '');
            $inner = '<span class="wpd-qbtn__icon dashicons dashicons-' . esc_attr($item['icon']) . '" aria-hidden="true"></span>'
                . '<span class="wpd-qbtn__text">' . esc_html($item['text']) . '</span>';
            $html .= '<li>' . ($item['url'] !== ''
                ? '<a class="' . $class . '"' . self::link_attrs($item['url'], $item['new_tab']) . '>' . $inner . '</a>'
                : '<span class="' . $class . '">' . $inner . '</span>') . '</li>';
        }
        return $html . '</ul></nav>';
    }

    /**
     * Цветные карточки-разделы.
     *
     * @param array|null  $items null — из общих настроек
     * @param string|null $title null — из общих настроек; пустая строка — без заголовка
     */
    public static function cards_html(array $o, ?array $items = null, ?string $title = null): string
    {
        $items = WPD_Settings::sanitize_rows($items === null ? $o['cards'] : $items, 'cards');
        if (!$items) {
            return '';
        }
        $title = $title === null ? (string) $o['cards_title'] : $title;

        $html = '<section class="wpd-cards">';
        if ($title !== '') {
            $html .= '<h2 class="wpd-h2">' . esc_html($title) . '</h2>';
        }
        $html .= '<ul class="wpd-cards__grid">';
        foreach ($items as $item) {
            $inner = '<span class="wpd-card__title">' . esc_html($item['title']) . '</span>';
            if ($item['image'] !== '') {
                $inner .= '<img class="wpd-card__img" src="' . esc_url($item['image']) . '" alt="" loading="lazy">';
            }
            if ($item['text'] !== '') {
                $inner .= '<span class="wpd-card__text">' . nl2br(esc_html($item['text'])) . '</span>';
            }
            if ($item['url'] !== '') {
                $inner .= '<span class="wpd-card__more" aria-hidden="true">&rarr;</span>';
                $inner  = '<a class="wpd-card__link"' . self::link_attrs($item['url'], $item['new_tab']) . '>' . $inner . '</a>';
            } else {
                $inner = '<div class="wpd-card__link">' . $inner . '</div>';
            }
            $style = $item['color'] !== '' ? ' style="--wpd-card:' . esc_attr($item['color']) . '"' : '';
            $html .= '<li class="wpd-card' . ($item['color'] !== '' ? ' has-color' : '') . '"' . $style . '>' . $inner . '</li>';
        }
        return $html . '</ul></section>';
    }

    /**
     * Объявление: изображение и/или текст, необязательная ссылка.
     *
     * @param array $args layout, image, color, url, new_tab, pre_title, title, content
     */
    public static function notice_html(array $o, array $args = []): string
    {
        $layout  = (string) self::arg($args, 'layout', $o['notice_layout']);
        $layout  = in_array($layout, self::NOTICE_LAYOUTS, true) ? $layout : 'image_text';
        $image   = esc_url((string) self::arg($args, 'image', $o['notice_image']));
        $color   = (string) sanitize_hex_color((string) self::arg($args, 'color', $o['notice_color']));
        $url     = (string) self::arg($args, 'url', $o['notice_url']);
        $new_tab = (bool) self::arg($args, 'new_tab', $o['notice_new_tab']);
        $pre     = (string) self::arg($args, 'pre_title', $o['notice_pre_title']);
        $title   = (string) self::arg($args, 'title', $o['notice_title']);
        $content = trim(wp_kses_post((string) self::arg($args, 'content', $o['notice_content'])));

        $has_text = $pre !== '' || $title !== '' || $content !== '';
        if ($layout === 'image_text' && $image === '') {
            $layout = 'text';
        }
        if (($layout === 'image' && $image === '') || ($layout === 'text' && !$has_text)) {
            return '';
        }
        if ($layout === 'image_text' && !$has_text) {
            $layout = 'image';
        }

        $open  = $url !== '' ? '<a' . self::link_attrs($url, $new_tab) . '>' : '';
        $close = $url !== '' ? '</a>' : '';
        $style = $color !== '' ? ' style="--wpd-notice-bg:' . esc_attr($color) . '"' : '';

        $html = '<section class="wpd-notice wpd-notice--' . str_replace('_', '-', $layout) . '"' . $style . '>';
        if ($layout !== 'text') {
            $html .= '<div class="wpd-notice__media">' . $open . '<img src="' . $image . '" alt="' . esc_attr($title) . '" loading="lazy">' . $close . '</div>';
        }
        if ($layout !== 'image') {
            $html .= '<div class="wpd-notice__body">';
            if ($pre !== '') {
                $html .= '<p class="wpd-notice__pre">' . esc_html($pre) . '</p>';
            }
            if ($title !== '') {
                $html .= '<h2 class="wpd-notice__title">' . $open . esc_html($title) . $close . '</h2>';
            }
            if ($content !== '') {
                $html .= '<div class="wpd-notice__content">' . wpautop($content) . '</div>';
            }
            $html .= '</div>';
        }
        return $html . '</section>';
    }

    /**
     * Последние записи. Текст анонса строится без фильтра the_content, чтобы блок новостей внутри записи
     * не вызывал сам себя.
     *
     * @param array $args title, category, count, layout, more
     */
    public static function news_html(array $o, array $args = []): string
    {
        $title    = (string) self::arg($args, 'title', $o['news_title']);
        $category = absint(self::arg($args, 'category', $o['news_category']));
        $count    = min(24, max(1, (int) self::arg($args, 'count', $o['news_count'])));
        $layout   = (string) self::arg($args, 'layout', $o['news_layout']);
        $layout   = in_array($layout, self::NEWS_LAYOUTS, true) ? $layout : 'carousel';
        $more     = (string) self::arg($args, 'more', $o['news_more']);

        $query = [
            'post_type'           => 'post',
            'post_status'         => 'publish',
            'posts_per_page'      => $count,
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
        ];
        if ($category) {
            $query['cat'] = $category;
        }
        $current = is_singular() ? get_queried_object_id() : 0;
        if ($current) {
            $query['post__not_in'] = [$current];
        }
        $posts = get_posts((array) apply_filters('wpd_news_query', $query));
        if (!$posts) {
            return '';
        }

        $more_url = '';
        if ($more !== '') {
            $more_url = $category ? get_category_link($category) : get_post_type_archive_link('post');
            $more_url = is_string($more_url) ? $more_url : '';
        }

        $html = '<section class="wpd-news wpd-news--' . $layout . '"' . ($layout === 'carousel' ? ' data-wpd-carousel="news" data-arrows' : '') . '>';
        $html .= '<div class="wpd-news__head">';
        if ($title !== '') {
            $html .= '<h2 class="wpd-h2">' . esc_html($title) . '</h2>';
        }
        if ($more_url !== '') {
            $html .= '<a class="wpd-news__more" href="' . esc_url($more_url) . '">' . esc_html($more) . ' &rarr;</a>';
        }
        $html .= '</div><div class="wpd-news__track" data-wpd-track>';

        foreach ($posts as $post) {
            $link  = get_permalink($post);
            $thumb = get_the_post_thumbnail_url($post, 'medium_large') ?: get_the_post_thumbnail_url($post, 'full');
            if (has_excerpt($post)) {
                $excerpt = wp_strip_all_tags($post->post_excerpt);
            } else {
                $text    = excerpt_remove_blocks(strip_shortcodes($post->post_content));
                $excerpt = wp_trim_words(wp_strip_all_tags($text), 20, '…');
            }

            $html .= '<article class="wpd-news__item">'
                . '<a class="wpd-news__media" href="' . esc_url($link) . '" tabindex="-1" aria-hidden="true">'
                . ($thumb ? '<img src="' . esc_url($thumb) . '" alt="" loading="lazy">' : '<span class="wpd-news__ph"></span>')
                . '</a><div class="wpd-news__body">'
                . '<time class="wpd-news__date" datetime="' . esc_attr(get_the_date(DATE_ATOM, $post)) . '">' . esc_html(get_the_date('', $post)) . '</time>'
                . '<h3 class="wpd-news__title"><a href="' . esc_url($link) . '">' . esc_html(get_the_title($post)) . '</a></h3>'
                . ($excerpt !== '' ? '<p class="wpd-news__excerpt">' . esc_html($excerpt) . '</p>' : '')
                . '</div></article>';
        }
        $html .= '</div>';
        if ($layout === 'carousel' && count($posts) > 1) {
            $html .= '<button type="button" class="wpd-slider__arrow wpd-slider__arrow--prev" data-wpd-prev hidden aria-label="Предыдущие новости">&#8249;</button>'
                . '<button type="button" class="wpd-slider__arrow wpd-slider__arrow--next" data-wpd-next hidden aria-label="Следующие новости">&#8250;</button>';
        }
        return $html . '</section>';
    }

    /**
     * Контакты. Выводятся, если заполнен хотя бы адрес, телефон или e-mail.
     *
     * @param array $args title, address, city, phone, email, map, page, code
     */
    public static function contacts_html(array $o, array $args = []): string
    {
        $v = [];
        foreach (['title' => 'contacts_title', 'address' => 'contact_address', 'city' => 'contact_city', 'phone' => 'contact_phone',
                  'email' => 'contact_email', 'map' => 'contact_map', 'page' => 'contact_page', 'code' => 'contact_code'] as $k => $opt) {
            $v[$k] = trim((string) self::arg($args, $k, $o[$opt]));
        }
        $v['email'] = (string) sanitize_email($v['email']);
        if ($v['address'] === '' && $v['city'] === '' && $v['phone'] === '' && $v['email'] === '') {
            return '';
        }
        self::enqueue_icons();

        $icon = function (string $name): string {
            return '<span class="wpd-contacts__icon dashicons dashicons-' . $name . '" aria-hidden="true"></span>';
        };

        $list = '';
        if ($v['address'] !== '' || $v['city'] !== '') {
            $addr = esc_html($v['address']) . ($v['address'] !== '' && $v['city'] !== '' ? '<br>' : '') . esc_html($v['city']);
            if ($v['map'] !== '' && esc_url($v['map']) !== '') {
                $addr = '<a' . self::link_attrs($v['map'], true) . '>' . $addr . '</a>';
            }
            $list .= '<li class="wpd-contacts__address">' . $icon('location-alt') . '<span>' . $addr . '</span></li>';
        }
        if ($v['code'] !== '') {
            $list .= '<li class="wpd-contacts__code">' . $icon('id') . '<span>' . esc_html($v['code']) . '</span></li>';
        }
        if ($v['email'] !== '') {
            $list .= '<li class="wpd-contacts__email">' . $icon('email-alt') . '<a href="' . esc_url('mailto:' . $v['email']) . '">' . esc_html($v['email']) . '</a></li>';
        }
        if ($v['phone'] !== '') {
            $tel   = preg_replace('/[^\d+]/', '', $v['phone']);
            $phone = $tel !== '' ? '<a href="' . esc_url('tel:' . $tel, ['tel']) . '">' . esc_html($v['phone']) . '</a>' : esc_html($v['phone']);
            $list .= '<li class="wpd-contacts__phone">' . $icon('phone') . $phone . '</li>';
        }

        $button = '';
        $target = $v['page'] !== '' ? $v['page'] : ($v['email'] !== '' ? 'mailto:' . $v['email'] : '');
        if ($target !== '' && esc_url($target) !== '') {
            $button = '<a class="wpd-btn wpd-contacts__btn" href="' . esc_url($target) . '">Связаться &rarr;</a>';
        }

        return '<section class="wpd-contacts">'
            . ($v['title'] !== '' ? '<h2 class="wpd-h2">' . esc_html($v['title']) . '</h2>' : '')
            . '<div class="wpd-contacts__box"><ul class="wpd-contacts__list">' . $list . '</ul>' . $button . '</div></section>';
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

                default:
                    // Компоненты 1.5.0: содержимое из общих настроек
                    $inner .= self::part_html($key, $o);
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
        $settings_url = WPD_Settings::url();

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
            if (self::page_needs_icons()) {
                self::enqueue_icons();
            }
        }
    }

    /**
     * Нужны ли иконки в <head>: на странице есть кнопки-ссылки или контакты (шорткод, блок или панель страницы).
     * Если не угадали, компонент подключит иконки сам при выводе (в подвале).
     */
    private static function page_needs_icons(): bool
    {
        $post = get_queried_object();
        if (!$post instanceof WP_Post) {
            return false;
        }
        foreach (self::ICON_PARTS as $part) {
            if (has_shortcode($post->post_content, 'wpd_' . $part)
                || strpos($post->post_content, '<!-- wp:' . WPD_Blocks::NS . '/' . $part) !== false) {
                return true;
            }
        }
        if (self::is_design_enabled((int) $post->ID)) {
            $p = WPD_Page_Editor::get((int) $post->ID);
            return (bool) array_diff(self::ICON_PARTS, $p['hidden']);
        }
        return false;
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
