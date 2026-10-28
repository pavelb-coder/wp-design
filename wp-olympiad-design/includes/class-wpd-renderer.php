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
    const HERO_STYLES = ['dark', 'gradient', 'light', 'soft'];

    /** Встроенные изображения стиля «Мягкий градиент» (2.3.0), пути от папки плагина. */
    const ORB_IMAGE  = 'assets/img/cities-orb.webp';
    const LOGO_IMAGE = 'assets/img/kvantorium-logo.webp';

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
        // 2.4.0: на странице записи действуют её переопределения из панели «Блоки олимпиады»
        add_action('wp', [__CLASS__, 'set_page_context']);
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
        return self::wrap(self::hero_html($o) . (empty($o['hero_countdown']) ? self::countdown_html($o) : '') . self::stats_html($o)
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
    public static function wrap(string $inner, string $extra_class = '', ?array $o = null): string
    {
        // Страховка: если CSS не подключён заранее (шорткод в виджете, шаблоне), подключаем здесь.
        self::enqueue_assets();

        $o = $o ?? WPD_Settings::get();
        $vars = WPD_Settings::style_attr($o);

        // Модификаторы вида (1.8.0): форма и вид кнопок, тени, черта под заголовками, подъём карточек
        $class = trim(implode(' ', array_merge(['wpd-page'], WPD_Settings::page_classes($o))) . ' ' . $extra_class);
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
     * в $args можно передать свои (страница, блок редактора). null — значение из настроек.
     *
     * @param array $args title, lead, image, image_alt, image_position, overlay, badge, button_text, button_url, button_new_tab,
     *                    button2_text, button2_url, button2_new_tab, style, bg, height, align, title_size
     */
    public static function hero_html(array $o, array $args = []): string
    {
        $a = array_merge([
            'title'           => $o['hero_title'],
            'lead'            => $o['hero_text'],
            'image'           => $o['hero_image_url'],
            'image_alt'       => $o['hero_image_alt'],
            'image_position'  => $o['hero_image_position'],
            'overlay'         => $o['hero_overlay'],
            'badge'           => $o['badge'],
            'button_text'     => $o['hero_button_text'],
            'button_url'      => $o['hero_button_url'],
            'button_new_tab'  => $o['hero_button_new_tab'],
            'button2_text'    => $o['hero_button2_text'],
            'button2_url'     => $o['hero_button2_url'],
            'button2_new_tab' => $o['hero_button2_new_tab'],
            'style'           => $o['hero_style'],
            'bg'              => $o['hero_bg'],
            'height'          => $o['hero_height'],
            'align'           => $o['hero_align'],
            'title_size'      => $o['hero_title_size'],
            // 1.9.0
            'button_icon'     => $o['hero_button_icon'],
            'button2_icon'    => $o['hero_button2_icon'],
            'countdown'       => $o['hero_countdown'],
            'image_fit'       => $o['hero_image_fit'],
            'image_style'     => $o['hero_image_style'],
            'image_mobile'    => $o['hero_image_mobile'],
            'overlay_color'   => $o['hero_overlay_color'],
            'badge_color'     => $o['hero_badge_color'],
            'title_color'     => $o['hero_title_color'],
            'text_color'      => $o['hero_text_color'],
            'min_height'      => $o['hero_min_height'],
            'padding'         => $o['hero_padding'],
            'valign'          => $o['hero_valign'],
            'text_width'      => $o['hero_text_width'],
        ], array_filter($args, function ($v) {
            return $v !== null;
        }));
        $a = array_map(function ($v) {
            return is_scalar($v) ? (string) $v : '';
        }, $a);

        $pick = function (string $value, array $allowed, string $default): string {
            return in_array($value, $allowed, true) ? $value : $default;
        };
        $style    = $pick($a['style'], self::HERO_STYLES, 'dark');
        $position = $pick($a['image_position'], ['right', 'left', 'background'], 'right');
        $height   = $pick($a['height'], ['compact', 'normal', 'tall'], 'normal');
        $align    = $pick($a['align'], ['left', 'center'], 'left');
        $tsize    = $pick($a['title_size'], ['small', 'normal', 'large'], 'normal');
        $overlay  = max(0, min(90, (int) $a['overlay']));
        $bg       = (string) sanitize_hex_color($a['bg']);
        $image    = esc_url($a['image']);
        $fit      = $pick($a['image_fit'], ['cover', 'contain'], 'cover');
        $istyle   = $pick($a['image_style'], ['rounded', 'shadow', 'square', 'circle'], 'rounded');
        $valign   = $pick($a['valign'], ['top', 'center', 'bottom'], 'center');
        $minh     = max(0, min(900, (int) $a['min_height']));
        $pad      = max(0, min(120, (int) $a['padding']));
        $twidth   = max(0, min(1200, (int) $a['text_width']));
        $on       = function (string $v): bool { return $v !== '' && $v !== '0'; };

        // 2.3.0: «Мягкий градиент» без своего изображения — встроенная круглая иллюстрация городов справа (или слева)
        $orb = false;
        if ($image === '' && $style === 'soft' && !empty($o['hero_soft_orb'])) {
            $orb      = true;
            $image    = esc_url(WPD_URL . self::ORB_IMAGE);
            $position = $position === 'left' ? 'left' : 'right';
            $a['image_alt'] = $a['image_alt'] !== '' ? $a['image_alt'] : 'Иллюстрация: Лондон и Красноярск';
        }

        $classes = ['wpd-hero', 'wpd-hero--' . $style];
        if ($orb) {
            $classes[] = 'wpd-hero--orb';
        }
        $vars    = '';
        if ($image !== '') {
            $classes[] = 'wpd-hero--img-' . $position;
        }
        foreach (['height' => [$height, 'normal'], 'align' => [$align, 'left'], 'title' => [$tsize, 'normal']] as $prefix => $pair) {
            if ($pair[0] !== $pair[1]) {
                $classes[] = 'wpd-hero--' . $prefix . '-' . $pair[0];
            }
        }
        if ($bg !== '') {
            // Свой фон: цвет текста — по яркости фона
            $classes[] = 'wpd-hero--custom-bg';
            $classes[] = self::is_light_color($bg) ? 'wpd-hero--fg-dark' : 'wpd-hero--fg-light';
            $vars .= '--wpd-hero-bg:' . $bg . ';';
        }
        if ($image !== '' && $position === 'background') {
            // Кавычки, скобки и пробелы кодируются, чтобы адрес не мог выйти за пределы url("…")
            $safe  = strtr(esc_url_raw($a['image']), ['"' => '%22', "'" => '%27', '(' => '%28', ')' => '%29', '\\' => '%5C', ' ' => '%20']);
            $vars .= '--wpd-hero-img:url("' . $safe . '");--wpd-hero-overlay:' . $overlay . '%;';
            $ocolor = (string) sanitize_hex_color($a['overlay_color']);
            if ($ocolor !== '') {
                $vars .= '--wpd-hero-overlay-color:' . $ocolor . ';';
            }
        }
        // 1.9.0: изображение сбоку — подгонка и рамка; скрытие на телефоне
        if ($image !== '' && $position !== 'background' && !$orb) {
            if ($fit !== 'cover') {
                $classes[] = 'wpd-hero--fit-' . $fit;
            }
            if ($istyle !== 'rounded') {
                $classes[] = 'wpd-hero--imgstyle-' . $istyle;
            }
        }
        if ($image !== '' && !$on($a['image_mobile'])) {
            $classes[] = 'wpd-hero--img-hide-mobile';
        }
        if ($valign !== 'center') {
            $classes[] = 'wpd-hero--valign-' . $valign;
        }
        // Цвета метки, заголовка и текста
        $badge_color = (string) sanitize_hex_color($a['badge_color']);
        if ($badge_color !== '') {
            $classes[] = 'wpd-hero--c-badge';
            $vars .= '--wpd-hero-badge:' . $badge_color . ';--wpd-hero-badge-fg:' . (self::is_light_color($badge_color) ? '#0f172a' : '#fff') . ';';
        }
        foreach (['title_color' => ['title', '--wpd-hero-title'], 'text_color' => ['text', '--wpd-hero-text']] as $key => $pair) {
            $color = (string) sanitize_hex_color($a[$key]);
            if ($color !== '') {
                $classes[] = 'wpd-hero--c-' . $pair[0];
                $vars .= $pair[1] . ':' . $color . ';';
            }
        }
        // Размеры: минимальная высота, внутренний отступ, ширина текста
        if ($minh > 0) {
            $classes[] = 'wpd-hero--minh';
            $vars .= '--wpd-hero-minh:' . $minh . 'px;';
        }
        if ($pad > 0) {
            $classes[] = 'wpd-hero--pad';
            $vars .= '--wpd-hero-pad:' . $pad . 'px;';
        }
        if ($twidth > 0) {
            $classes[] = 'wpd-hero--text-w';
            $vars .= '--wpd-hero-text-w:' . $twidth . 'px;';
        }

        $html = '<section class="' . esc_attr(implode(' ', $classes)) . '"' . ($vars !== '' ? ' style="' . esc_attr($vars) . '"' : '') . '>'
            . '<div class="wpd-hero__inner"><div class="wpd-hero__text">';

        if ($a['badge'] !== '') {
            $html .= '<span class="wpd-badge">' . esc_html($a['badge']) . '</span>';
        }

        $html .= '<h1 class="wpd-hero__title">' . esc_html($a['title']) . '</h1>';

        if ($a['lead'] !== '') {
            $html .= '<p class="wpd-hero__lead">' . nl2br(esc_html($a['lead'])) . '</p>';
        }

        $buttons = '';
        $icons = WPD_Settings::icons();
        foreach ([['button_text', 'button_url', 'button_new_tab', 'wpd-btn', 'button_icon'], ['button2_text', 'button2_url', 'button2_new_tab', 'wpd-btn wpd-btn--ghost', 'button2_icon']] as $b) {
            $url = esc_url($a[$b[1]]);
            if ($a[$b[0]] !== '' && $url !== '') {
                $icon = isset($icons[$a[$b[4]]]) ? $a[$b[4]] : '';
                if ($icon !== '') {
                    self::enqueue_icons();
                }
                $buttons .= '<a class="' . $b[3] . '" href="' . $url . '"'
                    . ($on($a[$b[2]]) ? ' target="_blank" rel="noopener noreferrer"' : '')
                    . '>' . ($icon !== '' ? '<span class="wpd-btn__icon dashicons dashicons-' . esc_attr($icon) . '" aria-hidden="true"></span>' : '')
                    . esc_html($a[$b[0]]) . ($b[3] === 'wpd-btn' ? ' &rarr;' : '') . '</a>';
            }
        }
        if ($buttons !== '') {
            $html .= '<div class="wpd-hero__actions">' . $buttons . '</div>';
        }

        // Таймер внутри главного блока (1.9.0): даты и подписи — с вкладки «Таймер и эффекты»
        if ($on($a['countdown'])) {
            $timer = self::countdown_html($o, ['enabled' => true]);
            if ($timer !== '') {
                $html .= '<div class="wpd-hero__countdown">' . $timer . '</div>';
            }
        }

        $html .= '</div>';

        if ($orb) {
            // Иллюстрация с подсветкой и звёздочками; анимация отключается при «уменьшить движение»
            $html .= '<div class="wpd-hero__media wpd-hero__media--orb">'
                . '<span class="wpd-orb__aura" aria-hidden="true"></span>'
                . '<img class="wpd-orb__img" src="' . $image . '" alt="' . esc_attr($a['image_alt']) . '" width="1024" height="1020">'
                . '<span class="wpd-orb__star wpd-orb__star--1" aria-hidden="true">&#10022;</span>'
                . '<span class="wpd-orb__star wpd-orb__star--2" aria-hidden="true">&#10022;</span>'
                . '</div>';
        } elseif ($image !== '' && $position !== 'background') {
            $html .= '<div class="wpd-hero__media"><img src="' . $image . '" alt="' . esc_attr($a['image_alt']) . '" loading="lazy"></div>';
        }

        $html .= '</div></section>';

        return $html;
    }

    /**
     * Светлый ли цвет (#rrggbb, #rgb): на нём нужен тёмный текст. Считается по относительной яркости WCAG.
     */
    public static function is_light_color(string $hex): bool
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            return false;
        }
        $lin = function (int $c): float {
            $c /= 255;
            return $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
        };
        $l = 0.2126 * $lin(hexdec(substr($hex, 0, 2))) + 0.7152 * $lin(hexdec(substr($hex, 2, 2))) + 0.0722 * $lin(hexdec(substr($hex, 4, 2)));
        // Порог 0,18: при нём контраст с белым и с чёрным текстом примерно равен
        return $l > 0.18;
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

        // 2.7.0: «Вид таймера»
        $look = self::block_look($o, 'countdown');
        $val  = self::px($o, 'countdown_value_size', 14, 64);
        $style = $look['style'] . ($val ? '--wpd-bl-val:' . $val . 'px;' : '');
        $html = '<div class="wpd-countdown' . esc_attr(self::look_class($look) . ($val ? ' wpd-countdown--size' : '')) . '"'
            . ($style !== '' ? ' style="' . esc_attr($style) . '"' : '')
            . ' data-target="' . esc_attr($dt->format(DATE_ATOM)) . '"'
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

    /* ---------------------------------------------------------------
     * 2.7.0: «Раскладка и вид» блоков и общие поля элементов
     * ------------------------------------------------------------- */

    /**
     * Классы и CSS-переменные блока по общим параметрам «Раскладка и вид» (WPD_Settings::LOOK_BLOCKS).
     * Значения берутся из $o (настройки, страница или атрибуты блока, уже подставленные в $o).
     * Без своих параметров class = 'wpd-look' — тогда разметка блока не меняется (см. look_class()).
     *
     * @return array{class:string,style:string}
     */
    public static function block_look(array $o, string $slug): array
    {
        $keys = WPD_Settings::LOOK_BLOCKS[$slug] ?? [];
        $map  = WPD_Settings::field_map();
        $val  = function (string $k) use ($o, $slug): string {
            $key = $slug . '_' . $k;
            return isset($o[$key]) && is_scalar($o[$key]) ? trim((string) $o[$key]) : '';
        };
        $pick = function (string $k) use ($val, $map, $slug): string {
            $v = $val($k);
            return array_key_exists($v, $map[$slug . '_' . $k]['options']) ? (string) $v : '';
        };
        $int = function (string $k) use ($val, $map, $slug): int {
            $v = is_numeric($val($k)) ? (int) $val($k) : 0;
            return $v > 0 ? min((int) $map[$slug . '_' . $k]['max'], $v) : 0;
        };
        $color = function (string $k) use ($val): string {
            return (string) sanitize_hex_color($val($k));
        };

        $class = ['wpd-look'];
        $vars  = [];
        foreach ($keys as $k) {
            switch ($k) {
                case 'columns':
                    if (($n = $pick($k)) !== '') {
                        $class[] = 'wpd-look--cols';
                        $vars['--wpd-bl-cols']   = $n;
                        $vars['--wpd-bl-cols-t'] = (string) min(2, (int) $n);
                    }
                    break;
                case 'columns_mobile':
                    if (($n = $pick($k)) !== '') {
                        $class[] = 'wpd-look--cols-m';
                        $vars['--wpd-bl-cols-m'] = $n;
                    }
                    break;
                case 'gap':
                    if ($n = $int($k)) {
                        $class[] = 'wpd-look--gap';
                        $vars['--wpd-bl-gap'] = $n . 'px';
                    }
                    break;
                case 'align':
                case 'variant':
                    if (($v = $pick($k)) !== '') {
                        $class[] = 'wpd-look--' . ($k === 'align' ? 'align-' : '') . $v;
                    }
                    break;
                case 'radius':
                    if (($v = $pick($k)) !== '') {
                        $class[] = 'wpd-look--radius';
                        $vars['--wpd-radius'] = WPD_Settings::LOOK_RADIUS[$v] . 'px';
                    }
                    break;
                case 'text_size':
                    if ($n = $int($k)) {
                        $class[] = 'wpd-look--fs';
                        $vars['--wpd-bl-fs'] = max(11, $n) . 'px';
                    }
                    break;
                case 'bg':
                    if (($c = $color($k)) !== '') {
                        $class[] = 'wpd-look--bg';
                        $vars['--wpd-bl-bg'] = $c;
                    }
                    break;
                case 'accent':
                    if (($c = $color($k)) !== '') {
                        $class[] = 'wpd-look--accent';
                        $vars['--wpd-primary'] = $c;
                        $vars['--wpd-accent']  = $c;
                    }
                    break;
                case 'text_color':
                    if (($c = $color($k)) !== '') {
                        $class[] = 'wpd-look--color';
                        $vars['--wpd-bl-text'] = $c;
                    }
                    break;
            }
        }

        // Тон: на тёмном фоне и заливке текст светлый, на светлом — тёмный (важно для цветных карточек)
        $bg      = in_array('bg', $keys, true) ? $color('bg') : '';
        $variant = in_array('variant', $keys, true) ? $pick('variant') : '';
        if ($bg !== '') {
            $class[] = self::is_dark($bg) ? 'wpd-look--ondark' : 'wpd-look--onlight';
        } elseif ($variant === 'solid') {
            $class[] = 'wpd-look--ondark';
        } elseif ($variant !== '') {
            $class[] = 'wpd-look--onlight';
        }

        $style = '';
        foreach ($vars as $name => $value) {
            $style .= $name . ':' . $value . ';';
        }
        return ['class' => implode(' ', $class), 'style' => $style];
    }

    /** Классы «Раскладки и вида» для корня блока: пусто, если своих параметров нет. */
    public static function look_class(array $look): string
    {
        return $look['class'] === 'wpd-look' ? '' : ' ' . $look['class'];
    }

    /** Открывающий тег корня блока с классами и переменными «Раскладки и вида». */
    private static function look_open(string $tag, string $base, array $look, string $extra_style = '', string $attrs = ''): string
    {
        $style = $look['style'] . $extra_style;
        return '<' . $tag . ' class="' . esc_attr($base . self::look_class($look)) . '"'
            . ($style !== '' ? ' style="' . esc_attr($style) . '"' : '') . $attrs . '>';
    }

    /** Строки списка, которые показываются сейчас: не скрыты и в пределах дат показа (часовой пояс таймера). */
    public static function visible_items(array $items, array $o): array
    {
        $now = self::buttons_now($o);
        return array_values(array_filter($items, function ($item) use ($now) {
            return is_array($item) && self::button_visible($item, $now);
        }));
    }

    /** Ключи строк списка из описания повторяющегося поля настроек. */
    public static function row_keys(string $key): array
    {
        return array_keys(WPD_Settings::field_map()[$key]['fields'] ?? []);
    }

    /** Метка элемента. */
    private static function badge_html($text, string $mod = ''): string
    {
        $text = trim((string) $text);
        return $text === '' ? '' : '<span class="wpd-it-badge' . ($mod !== '' ? ' wpd-it-badge--' . $mod : '') . '">' . esc_html($text) . '</span>';
    }

    /** Иконка Dashicons элемента; неизвестная или пустая — ничего. */
    private static function item_icon($icon, string $class): string
    {
        $icon = (string) $icon;
        if ($icon === '' || !isset(WPD_Settings::icons()[$icon])) {
            return '';
        }
        self::enqueue_icons();
        return '<span class="' . $class . ' dashicons dashicons-' . esc_attr($icon) . '" aria-hidden="true"></span>';
    }

    /**
     * Свой цвет элемента: класс тона и CSS-переменная.
     *
     * @return array{class:string,style:string}
     */
    private static function item_color($hex, string $var = '--wpd-it-bg'): array
    {
        $c = (string) sanitize_hex_color(trim((string) $hex));
        if ($c === '') {
            return ['class' => '', 'style' => ''];
        }
        return ['class' => ' has-color ' . (self::is_dark($c) ? 'wpd-it--dark' : 'wpd-it--light'), 'style' => $var . ':' . $c . ';'];
    }

    /** Число из настроек в пределах min…max; 0 и неверное значение — 0 (по умолчанию). */
    private static function px(array $o, string $key, int $min, int $max): int
    {
        $v = isset($o[$key]) && is_numeric($o[$key]) ? (int) $o[$key] : 0;
        return $v > 0 ? max($min, min($max, $v)) : 0;
    }

    /**
     * Статистика. 2.7.0: заголовок, иконки и цвета карточек, скрытие и показ по датам, «Раскладка и вид».
     *
     * @param array|null $items [['num' => ..., 'label' => ..., ...]]; null — из общих настроек
     */
    public static function stats_html(array $o, ?array $items = null): string
    {
        $items = $items === null ? WPD_Settings::stats_items($o['stats']) : self::clean_items($items, self::row_keys('stats'));
        $items = self::visible_items($items, $o);
        $html  = '';

        foreach ($items as $item) {
            $num   = (string) ($item['num'] ?? '');
            $label = (string) ($item['label'] ?? '');
            if ($num === '' && $label === '') {
                continue;
            }
            $attrs = !empty($o['stats_animate']) ? self::count_attrs($num) : '';
            $col   = self::item_color($item['color'] ?? '');
            $html .= '<li class="wpd-stat' . $col['class'] . '"' . ($col['style'] !== '' ? ' style="' . esc_attr($col['style']) . '"' : '') . '>'
                . self::item_icon($item['icon'] ?? '', 'wpd-stat__icon')
                . '<span class="wpd-stat__num"' . $attrs . '>' . esc_html($num) . '</span>'
                . '<span class="wpd-stat__label">' . esc_html($label) . '</span>'
                . '</li>';
        }

        if ($html === '') {
            return '';
        }
        $look  = self::block_look($o, 'stats');
        $num   = self::px($o, 'stats_num_size', 20, 96);
        $title = trim((string) ($o['stats_title'] ?? ''));
        return self::look_open('section', 'wpd-stats', $look, $num ? '--wpd-bl-num:' . $num . 'px;' : '', ' aria-label="Ключевые цифры"')
            . ($title !== '' ? '<h2 class="wpd-h2">' . esc_html($title) . '</h2>' : '')
            . '<ul class="wpd-stats__grid">' . $html . '</ul></section>';
    }

    /**
     * Документы. 2.7.0: подпись, метка, текст и цвет значка, новая вкладка, скачивание, скрытие и даты, «Раскладка и вид».
     *
     * @param array|null  $items [['title' => ..., 'url' => ..., ...]]; null — из общих настроек
     * @param string|null $title заголовок блока; null или пусто — из общих настроек
     */
    public static function docs_html(array $o, ?array $items = null, ?string $title = null): string
    {
        $items = $items === null ? WPD_Settings::docs_items($o['docs']) : self::clean_items($items, self::row_keys('docs'));
        $items = self::visible_items($items, $o);
        $title = ($title === null || $title === '') ? $o['docs_title'] : $title;
        $show_type = !array_key_exists('docs_show_type', $o) || !empty($o['docs_show_type']);
        $all_new   = !empty($o['docs_new_tab']);
        $list  = '';

        foreach ($items as $item) {
            $url = esc_url((string) ($item['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $name   = (string) ($item['title'] ?? '') !== '' ? (string) $item['title'] : (string) $item['url'];
            $is_pdf = (bool) preg_match('/\.pdf(\?|#|$)/i', (string) $item['url']);
            $type   = trim((string) ($item['type'] ?? ''));
            $type   = $type !== '' ? (function_exists('mb_substr') ? mb_substr($type, 0, 6) : substr($type, 0, 6)) : ($is_pdf ? 'PDF' : 'WEB');
            $desc   = trim((string) ($item['desc'] ?? ''));
            $col    = self::item_color($item['color'] ?? '', '--wpd-it-icon');
            $class  = 'wpd-doc' . ($col['class'] !== '' ? ' has-icon-color' : '');
            $attrs  = ' href="' . $url . '"';
            if ($all_new || !empty($item['new_tab'])) {
                $attrs .= ' target="_blank" rel="noopener noreferrer"';
            }
            if (!empty($item['download'])) {
                $attrs .= ' download';
            }
            if ($col['style'] !== '') {
                $attrs .= ' style="' . esc_attr($col['style']) . '"';
            }
            $name_html = '<span class="wpd-doc__name">' . esc_html($name) . '</span>';
            if ($desc !== '') {
                $name_html = '<span class="wpd-doc__text">' . $name_html . '<span class="wpd-doc__desc">' . esc_html($desc) . '</span></span>';
            }
            $list .= '<li><a class="' . $class . '"' . $attrs . '>'
                . ($show_type ? '<span class="wpd-doc__icon" aria-hidden="true">' . esc_html($type) . '</span>' : '')
                . $name_html
                . self::badge_html($item['badge'] ?? '', 'inline')
                . '</a></li>';
        }

        if ($list === '') {
            return '';
        }

        $look = self::block_look($o, 'docs');
        return self::look_open('section', 'wpd-docs' . ($show_type ? '' : ' wpd-docs--notype'), $look)
            . '<h2 class="wpd-h2">' . esc_html($title) . '</h2>'
            . '<ul class="wpd-docs__list">' . $list . '</ul></section>';
    }

    /**
     * Частые вопросы. 2.7.0: открытые ответы, «только один открыт», нумерация, скрытие и даты, «Вид».
     *
     * @param array|null  $items [['q' => ..., 'a' => ..., ...]]; null — из общих настроек
     * @param string|null $title заголовок блока; null или пусто — из общих настроек
     */
    public static function faq_html(array $o, ?array $items = null, ?string $title = null): string
    {
        $items = $items === null ? WPD_Settings::faq_items($o['faq']) : self::clean_items($items, self::row_keys('faq'));
        $items = array_values(array_filter(self::visible_items($items, $o), function ($item) {
            return (string) ($item['q'] ?? '') !== '';
        }));
        $title = ($title === null || $title === '') ? $o['faq_title'] : $title;
        if (!$items) {
            return '';
        }

        $searchable = !empty($o['faq_search']) && count($items) >= self::FAQ_SEARCH_MIN;
        $look       = self::block_look($o, 'faq');

        $html = self::look_open('section', 'wpd-faq' . (!empty($o['faq_numbered']) ? ' wpd-faq--numbered' : ''), $look, '',
                ($searchable ? ' data-wpd-search' : '') . (!empty($o['faq_single']) ? ' data-wpd-single' : ''))
            . '<h2 class="wpd-h2">' . esc_html($title) . '</h2>';

        if ($searchable) {
            // hidden по умолчанию: без JavaScript поиск не показывается
            $html .= '<input type="search" class="wpd-faq__search" placeholder="Поиск по вопросам"'
                . ' aria-label="Поиск по вопросам" hidden>'
                . '<p class="wpd-faq__empty" hidden>Ничего не найдено. Попробуйте другие слова.</p>';
        }

        $html .= '<div class="wpd-faq__list">';

        foreach ($items as $i => $item) {
            $open  = !empty($item['open']) || ($i === 0 && !empty($o['faq_open_first']));
            $html .= '<details class="wpd-faq__item"' . ($open ? ' open' : '') . '>'
                . '<summary class="wpd-faq__q">' . esc_html($item['q']) . '</summary>'
                . '<div class="wpd-faq__a">' . wp_kses(nl2br((string) ($item['a'] ?? '')), WPD_Settings::allowed_html()) . '</div>'
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
    /**
     * Ссылка ведёт на другой сайт (другой домен, чем у сайта WordPress). Относительные ссылки — свои.
     */
    public static function is_external(string $url): bool
    {
        $host = wp_parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return false;
        }
        $own = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $strip = function (string $h): string { return preg_replace('/^www\./', '', strtolower($h)); };
        return $strip($host) !== $strip($own);
    }

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
        $items = self::visible_items(WPD_Settings::sanitize_rows(self::arg($args, 'items', $o['slides']), 'slides'), $o);
        if (!$items) {
            return '';
        }

        $height  = min(900, max(200, (int) self::arg($args, 'height', $o['slider_height'])));
        $classes = ['wpd-slider'];
        // 2.7.0: положение подписи и «Вид»
        $cap = (string) ($o['slider_caption_pos'] ?? 'bottom');
        if (in_array($cap, ['top', 'center'], true)) {
            $classes[] = 'wpd-slider--cap-' . $cap;
        }
        $look = self::block_look($o, 'slider');
        if ($look['class'] !== 'wpd-look') {
            $classes[] = $look['class'];
        }
        $interval = isset($o['slider_interval']) && is_numeric($o['slider_interval']) ? max(2, min(30, (int) $o['slider_interval'])) : 6;
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
            '<section class="%s" style="--wpd-slider-h:%dpx%s" aria-roledescription="carousel" aria-label="Слайдер"'
            . ' data-wpd-carousel="slider"%s%s%s>',
            esc_attr(implode(' ', $classes)),
            $height,
            $look['style'] !== '' ? esc_attr(';' . rtrim($look['style'], ';')) : '',
            self::arg($args, 'autoplay', $o['slider_autoplay']) ? ' data-autoplay="' . ($interval * 1000) . '"' : '',
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
            $button = $item['url'] !== '' ? trim((string) ($item['button'] ?? '')) : '';
            $badge  = trim((string) ($item['badge'] ?? ''));
            if ($item['heading'] !== '' || $item['text'] !== '' || $button !== '' || $badge !== '') {
                $inner .= '<div class="wpd-slide__caption">';
                if ($badge !== '') {
                    $inner .= '<span class="wpd-slide__badge">' . esc_html($badge) . '</span>';
                }
                if ($item['heading'] !== '') {
                    $inner .= '<h2 class="wpd-slide__title">' . esc_html($item['heading']) . '</h2>';
                }
                if ($item['text'] !== '') {
                    $inner .= '<p class="wpd-slide__text">' . nl2br(esc_html($item['text'])) . '</p>';
                }
                if ($button !== '') {
                    $inner .= '<span class="wpd-btn wpd-slide__btn">' . esc_html($button) . ' &rarr;</span>';
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
    /**
     * Кнопки-ссылки. 2.6.0: заголовок, раскладка, вид и цвета блока; у кнопки — подпись, метка, картинка вместо иконки,
     * свои цвета, скачивание, скрытие и показ по датам.
     *
     * @param array|null  $items null — из общих настроек
     * @param string|null $title null — из общих настроек; пустая строка — без заголовка
     * @param bool        $editor true — для холста редактора: скрытые кнопки и кнопки вне дат показа выводятся приглушёнными
     */
    public static function buttons_html(array $o, ?array $items = null, ?string $title = null, bool $editor = false): string
    {
        $items = WPD_Settings::sanitize_rows($items === null ? $o['buttons'] : $items, 'buttons');
        $now   = self::buttons_now($o);
        $rows  = [];
        foreach ($items as $item) {
            if ($item['text'] === '') {
                continue;
            }
            $visible = self::button_visible($item, $now);
            if ($visible || $editor) {
                $item['_off'] = !$visible;
                $rows[] = $item;
            }
        }
        if (!$rows) {
            return '';
        }
        self::enqueue_icons();
        $title = $title === null ? (string) ($o['buttons_title'] ?? '') : $title;
        $look  = self::buttons_look($o);

        $html = '<nav class="' . esc_attr($look['class']) . '"' . ($look['style'] !== '' ? ' style="' . esc_attr($look['style']) . '"' : '')
            . ' aria-label="' . esc_attr($title !== '' ? $title : 'Быстрые ссылки') . '">';
        if ($title !== '') {
            $html .= '<h2 class="wpd-h2">' . esc_html($title) . '</h2>';
        }
        $html .= '<ul class="wpd-buttons__grid">';
        foreach ($rows as $item) {
            $item_look = self::button_item_look($item);
            $class = 'wpd-qbtn' . ($item['small'] ? ' wpd-qbtn--small' : '') . ($item['url'] === '' ? ' wpd-qbtn--nolink' : '')
                . $item_look['class'] . (!empty($item['_off']) ? ' wpd-qbtn--off' : '');
            $style = $item_look['style'] !== '' ? ' style="' . esc_attr($item_look['style']) . '"' : '';
            $media = $item['image'] !== ''
                ? '<img class="wpd-qbtn__img" src="' . esc_url($item['image']) . '" alt="" loading="lazy">'
                : '<span class="wpd-qbtn__icon dashicons dashicons-' . esc_attr($item['icon']) . '" aria-hidden="true"></span>';
            $inner = $media . '<span class="wpd-qbtn__body"><span class="wpd-qbtn__text">' . esc_html($item['text']) . '</span>'
                . ($item['desc'] !== '' ? '<span class="wpd-qbtn__desc">' . esc_html($item['desc']) . '</span>' : '') . '</span>'
                . ($item['badge'] !== '' ? '<span class="wpd-qbtn__badge">' . esc_html($item['badge']) . '</span>' : '');
            if ($item['url'] !== '') {
                $attrs = self::link_attrs($item['url'], $item['new_tab']) . ($item['download'] ? ' download' : '');
                $html .= '<li><a class="' . esc_attr($class) . '"' . $style . $attrs . '>' . $inner . '</a></li>';
            } else {
                $html .= '<li><span class="' . esc_attr($class) . '"' . $style . '>' . $inner . '</span></li>';
            }
        }
        return $html . '</ul></nav>';
    }

    /** Текущее время для дат показа кнопок — в часовом поясе таймера, «ГГГГ-ММ-ДД ЧЧ:ММ». Фильтр wpd_buttons_now — для проверок. */
    public static function buttons_now(array $o): string
    {
        $now = (string) apply_filters('wpd_buttons_now', '');
        if ($now !== '') {
            return $now;
        }
        return (new DateTime('now', WPD_Settings::countdown_timezone($o)))->format('Y-m-d H:i');
    }

    /** Кнопка показывается: не скрыта, дата «с» наступила, дата «до» ещё нет. */
    public static function button_visible(array $item, string $now): bool
    {
        if (!empty($item['hidden'])) {
            return false;
        }
        $from = (string) ($item['from'] ?? '');
        $to   = (string) ($item['to'] ?? '');
        if ($from !== '' && strcmp($now, $from) < 0) {
            return false;
        }
        if ($to !== '' && strcmp($now, $to) >= 0) {
            return false;
        }
        return true;
    }

    /** Тёмный цвет (#rrggbb): на нём нужен белый текст. */
    public static function is_dark(string $hex): bool
    {
        if (!preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', $hex, $m)) {
            if (!preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/i', $hex, $m)) {
                return false;
            }
            $m = [0, $m[1] . $m[1], $m[2] . $m[2], $m[3] . $m[3]];
        }
        $lum = (0.299 * hexdec($m[1]) + 0.587 * hexdec($m[2]) + 0.114 * hexdec($m[3])) / 255;
        return $lum < 0.6;
    }

    /**
     * Классы и CSS-переменные блока кнопок из настроек (или атрибутов блока, уже подставленных в $o).
     *
     * @return array{class:string,style:string}
     */
    public static function buttons_look(array $o): array
    {
        $map   = WPD_Settings::field_map();
        $pick  = function (string $key) use ($o, $map): string {
            $v = isset($o[$key]) && is_scalar($o[$key]) ? (string) $o[$key] : '';
            return array_key_exists($v, $map[$key]['options']) ? $v : (string) $map[$key]['default'];
        };
        $color = function (string $key) use ($o): string {
            return (string) sanitize_hex_color(isset($o[$key]) && is_scalar($o[$key]) ? trim((string) $o[$key]) : '');
        };
        $int   = function (string $key) use ($o, $map): int {
            $v = isset($o[$key]) && is_numeric($o[$key]) ? (int) $o[$key] : 0;
            return $v > 0 ? min((int) $map[$key]['max'], $v) : 0;
        };

        $class = ['wpd-buttons'];
        $vars  = [];
        $layout  = $pick('buttons_layout');
        $variant = $pick('buttons_variant');
        if ($layout !== 'stack') {
            $class[] = 'wpd-buttons--' . $layout;
        }
        if ($variant !== 'card') {
            $class[] = 'wpd-buttons--' . $variant;
        }
        if (($cols = $pick('buttons_columns')) !== '') {
            $class[] = 'wpd-buttons--cols';
            $vars['--wpd-qb-cols'] = $cols;
        }
        if (($cols_m = $pick('buttons_columns_mobile')) !== '') {
            $class[] = 'wpd-buttons--cols-m';
            $vars['--wpd-qb-cols-m'] = $cols_m;
        }
        if (($align = $pick('buttons_align')) !== '') {
            $class[] = 'wpd-buttons--align-' . $align;
        }
        if (($radius = $pick('buttons_radius')) !== '') {
            $vars['--wpd-qb-radius'] = ['none' => '0px', 'small' => '6px', 'medium' => '12px', 'large' => '20px', 'pill' => '999px'][$radius];
        }
        if ($gap = $int('buttons_gap')) {
            $vars['--wpd-qb-gap'] = $gap . 'px';
        }
        if ($height = $int('buttons_height')) {
            $vars['--wpd-qb-h'] = $height . 'px';
        }
        if ($size = $int('buttons_text_size')) {
            $size = max(11, $size);
            $class[] = 'wpd-buttons--fs';
            $vars['--wpd-qb-fs']       = $size . 'px';
            $vars['--wpd-qb-fs-small'] = max(11, (int) round($size * 0.85)) . 'px';
        }
        $bg = $color('buttons_bg');
        if ($bg !== '') {
            $vars['--wpd-qb-bg'] = $bg;
            if (self::is_dark($bg)) {
                $class[] = 'wpd-buttons--dark';
            }
        }
        foreach (['buttons_icon_color' => '--wpd-qb-icon', 'buttons_text_color' => '--wpd-qb-text', 'buttons_hover_color' => '--wpd-qb-hover'] as $key => $var) {
            if (($c = $color($key)) !== '') {
                $vars[$var] = $c;
            }
        }
        $style = '';
        foreach ($vars as $k => $v) {
            $style .= $k . ':' . $v . ';';
        }
        return ['class' => implode(' ', $class), 'style' => $style];
    }

    /**
     * Свой вид кнопки: цвет плитки (тёмный — белый текст), цвет иконки, метка.
     *
     * @return array{class:string,style:string}
     */
    public static function button_item_look(array $item): array
    {
        $class = '';
        $style = '';
        $bg = (string) sanitize_hex_color((string) ($item['color'] ?? ''));
        if ($bg !== '') {
            $style .= '--wpd-qb-bg:' . $bg . ';';
            $class .= ' has-color' . (self::is_dark($bg) ? ' wpd-qbtn--dark' : ' wpd-qbtn--light');
        }
        $icon = (string) sanitize_hex_color((string) ($item['icon_color'] ?? ''));
        if ($icon !== '') {
            $style .= '--wpd-qb-icon:' . $icon . ';';
            $class .= ' has-icon-color';
        }
        if ((string) ($item['badge'] ?? '') !== '') {
            $class .= ' wpd-qbtn--badge';
        }
        if ((string) ($item['image'] ?? '') !== '') {
            $class .= ' wpd-qbtn--img';
        }
        return ['class' => $class, 'style' => $style];
    }

    /**
     * Цветные карточки-разделы.
     *
     * @param array|null  $items null — из общих настроек
     * @param string|null $title null — из общих настроек; пустая строка — без заголовка
     */
    public static function cards_html(array $o, ?array $items = null, ?string $title = null): string
    {
        $items = self::visible_items(WPD_Settings::sanitize_rows($items === null ? $o['cards'] : $items, 'cards'), $o);
        if (!$items) {
            return '';
        }
        $title = $title === null ? (string) $o['cards_title'] : $title;
        $more_default = trim((string) ($o['cards_more_text'] ?? ''));
        $look  = self::block_look($o, 'cards');
        $img_h = self::px($o, 'cards_image_height', 40, 480);

        $html = self::look_open('section', 'wpd-cards' . ($img_h ? ' wpd-cards--img-h' : ''), $look, $img_h ? '--wpd-bl-img-h:' . $img_h . 'px;' : '');
        if ($title !== '') {
            $html .= '<h2 class="wpd-h2">' . esc_html($title) . '</h2>';
        }
        $html .= '<ul class="wpd-cards__grid">';
        foreach ($items as $item) {
            $inner = '<span class="wpd-card__title">' . self::item_icon($item['icon'] ?? '', 'wpd-card__icon') . esc_html($item['title']) . '</span>';
            if ($item['image'] !== '') {
                $inner .= '<img class="wpd-card__img" src="' . esc_url($item['image']) . '" alt="" loading="lazy">';
            }
            if ($item['text'] !== '') {
                $inner .= '<span class="wpd-card__text">' . nl2br(esc_html($item['text'])) . '</span>';
            }
            if ($item['url'] !== '') {
                $more = trim((string) ($item['more'] ?? '')) !== '' ? trim((string) $item['more']) : $more_default;
                $inner .= $more !== ''
                    ? '<span class="wpd-card__more wpd-card__more--text">' . esc_html($more) . ' &rarr;</span>'
                    : '<span class="wpd-card__more" aria-hidden="true">&rarr;</span>';
                $inner  = '<a class="wpd-card__link"' . self::link_attrs($item['url'], $item['new_tab']) . '>' . $inner . '</a>';
            } else {
                $inner = '<div class="wpd-card__link">' . $inner . '</div>';
            }
            $badge = self::badge_html($item['badge'] ?? '');
            $style = $item['color'] !== '' ? ' style="--wpd-card:' . esc_attr($item['color']) . '"' : '';
            $html .= '<li class="wpd-card' . ($item['color'] !== '' ? ' has-color' : '') . ($badge !== '' ? ' wpd-card--badge' : '') . '"' . $style . '>'
                . $inner . $badge . '</li>';
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

        // 2.7.0: показ по датам (часовой пояс таймера)
        if (!self::button_visible(['from' => (string) ($o['notice_from'] ?? ''), 'to' => (string) ($o['notice_to'] ?? '')], self::buttons_now($o))) {
            return '';
        }
        $button = $url !== '' ? trim((string) ($o['notice_button_text'] ?? '')) : '';

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
        $look = self::block_look($o, 'notice');
        // Свой «Цвет фона» объявления главнее вида, поэтому тон текста — по нему: тёмный — светлый текст
        if ($color !== '') {
            $plain = (string) preg_replace('/ wpd-look--on(dark|light)\b/', '', $look['class']);
            $look['class'] = $plain . (self::is_dark($color) ? ' wpd-look--ondark' : ($plain !== $look['class'] ? ' wpd-look--onlight' : ''));
        }
        $html = self::look_open('section', 'wpd-notice wpd-notice--' . str_replace('_', '-', $layout), $look,
            $color !== '' ? '--wpd-notice-bg:' . $color : '');
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
            if ($button !== '') {
                $html .= '<p class="wpd-notice__actions"><a class="wpd-btn wpd-notice__btn"' . self::link_attrs($url, $new_tab) . '>'
                    . esc_html($button) . ' &rarr;</a></p>';
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

        $look = self::block_look($o, 'news');
        $show_image   = !array_key_exists('news_show_image', $o) || !empty($o['news_show_image']);
        $show_date    = !array_key_exists('news_show_date', $o) || !empty($o['news_show_date']);
        $show_excerpt = !array_key_exists('news_show_excerpt', $o) || !empty($o['news_show_excerpt']);
        $words        = isset($o['news_excerpt_words']) && is_numeric($o['news_excerpt_words']) ? max(5, min(80, (int) $o['news_excerpt_words'])) : 20;
        $html = self::look_open('section', 'wpd-news wpd-news--' . $layout . ($show_image ? '' : ' wpd-news--noimg'), $look, '',
            $layout === 'carousel' ? ' data-wpd-carousel="news" data-arrows' : '');
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
                $excerpt = wp_trim_words(wp_strip_all_tags($text), $words, '…');
            }

            $html .= '<article class="wpd-news__item">'
                . ($show_image ? '<a class="wpd-news__media" href="' . esc_url($link) . '" tabindex="-1" aria-hidden="true">'
                    . ($thumb ? '<img src="' . esc_url($thumb) . '" alt="" loading="lazy">' : '<span class="wpd-news__ph"></span>')
                    . '</a>' : '')
                . '<div class="wpd-news__body">'
                . ($show_date ? '<time class="wpd-news__date" datetime="' . esc_attr(get_the_date(DATE_ATOM, $post)) . '">' . esc_html(get_the_date('', $post)) . '</time>' : '')
                . '<h3 class="wpd-news__title"><a href="' . esc_url($link) . '">' . esc_html(get_the_title($post)) . '</a></h3>'
                . ($show_excerpt && $excerpt !== '' ? '<p class="wpd-news__excerpt">' . esc_html($excerpt) . '</p>' : '')
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
        foreach (['title' => 'contacts_title', 'org' => 'contact_org', 'org_url' => 'contact_org_url', 'address' => 'contact_address',
                  'city' => 'contact_city', 'phone' => 'contact_phone', 'email' => 'contact_email', 'map' => 'contact_map',
                  'page' => 'contact_page', 'code' => 'contact_code'] as $k => $opt) {
            $v[$k] = trim((string) self::arg($args, $k, $o[$opt]));
        }
        $v['email'] = (string) sanitize_email($v['email']);
        if ($v['org'] === '' && $v['address'] === '' && $v['city'] === '' && $v['phone'] === '' && $v['email'] === '') {
            return '';
        }
        self::enqueue_icons();

        $show_icons = !array_key_exists('contacts_show_icons', $o) || !empty($o['contacts_show_icons']);
        $icon = function (string $name) use ($show_icons): string {
            return $show_icons ? '<span class="wpd-contacts__icon dashicons dashicons-' . $name . '" aria-hidden="true"></span>' : '';
        };

        $list = '';
        if ($v['org'] !== '') {
            // Название организации (1.7.0): со ссылкой, если она задана; чужой сайт — в новой вкладке
            $org = esc_html($v['org']);
            if ($v['org_url'] !== '' && esc_url($v['org_url']) !== '') {
                $org = '<a' . self::link_attrs($v['org_url'], self::is_external($v['org_url'])) . '>' . $org . '</a>';
            }
            $list .= '<li class="wpd-contacts__org">' . $icon('building') . '<span>' . $org . '</span></li>';
        }
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

        // Логотип (2.3.0): встроенный «Кванториум Красноярск» или свой; со ссылкой организации
        $logo = self::contact_logo_url($o);
        $logo_html = '';
        if ($logo !== '') {
            $alt = $v['org'] !== '' ? $v['org'] : ($o['contact_logo'] === 'custom' ? 'Логотип' : 'Кванториум Красноярск');
            $logo_html = '<img class="wpd-contacts__logo-img" src="' . esc_url($logo) . '" alt="' . esc_attr($alt) . '" loading="lazy">';
            if ($v['org_url'] !== '' && esc_url($v['org_url']) !== '') {
                $logo_html = '<a class="wpd-contacts__logo-link"' . self::link_attrs($v['org_url'], self::is_external($v['org_url'])) . '>' . $logo_html . '</a>';
            }
            $logo_html = '<div class="wpd-contacts__logo">' . $logo_html . '</div>';
        }

        $button = '';
        $target = $v['page'] !== '' ? $v['page'] : ($v['email'] !== '' ? 'mailto:' . $v['email'] : '');
        $btn_text = array_key_exists('contacts_button_text', $o) ? trim((string) $o['contacts_button_text']) : 'Связаться';
        if ($target !== '' && esc_url($target) !== '' && $btn_text !== '') {
            $button = '<a class="wpd-btn wpd-contacts__btn" href="' . esc_url($target) . '">' . esc_html($btn_text) . ' &rarr;</a>';
        }

        return self::look_open('section', 'wpd-contacts' . ($show_icons ? '' : ' wpd-contacts--noicons'), self::block_look($o, 'contacts'))
            . ($v['title'] !== '' ? '<h2 class="wpd-h2">' . esc_html($v['title']) . '</h2>' : '')
            . '<div class="wpd-contacts__box' . ($logo_html !== '' ? ' wpd-contacts__box--logo' : '') . '">' . $logo_html
            . '<ul class="wpd-contacts__list">' . $list . '</ul>' . $button . '</div></section>';
    }

    /**
     * Адрес логотипа в контактах по настройке contact_logo. Пусто — без логотипа.
     */
    public static function contact_logo_url(array $o): string
    {
        switch ((string) $o['contact_logo']) {
            case 'builtin':
                return WPD_URL . self::LOGO_IMAGE;
            case 'custom':
                return (string) $o['contact_logo_url'];
            case 'none':
                return '';
            default: // auto
                return $o['design_style'] === 'soft' ? WPD_URL . self::LOGO_IMAGE : '';
        }
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
        $p = WPD_Page_Editor::get($post_id);
        // 2.4.0: общие настройки с переопределениями страницы
        $o = WPD_Page_Editor::apply_data(WPD_Settings::get_global(), $p);

        $inner = '';
        foreach ($p['order'] as $key) {
            if (in_array($key, $p['hidden'], true)) {
                continue;
            }
            $html = '';
            switch ($key) {
                case 'hero':
                    $html = self::hero_html($o, self::page_hero_args($post_id, $p['hero'], $o));
                    break;

                case 'countdown':
                    // Таймер уже внутри видимого главного блока (1.9.0) — второй раз не выводим
                    if (empty($o['hero_countdown']) || in_array('hero', $p['hidden'], true)) {
                        $html = self::countdown_html($o);
                    }
                    break;

                case 'content':
                    if (trim($content) !== '') {
                        $html = '<div class="wpd-content">' . $content . '</div>';
                    }
                    break;

                case 'stats':
                    $html = self::stats_html($o, $p['stats_mode'] === 'custom' ? $p['stats'] : null);
                    break;

                case 'docs':
                    $html = self::docs_html($o, $p['docs_mode'] === 'custom' ? $p['docs'] : null, $p['docs_title']);
                    break;

                case 'faq':
                    $html = self::faq_html($o, $p['faq_mode'] === 'custom' ? $p['faq'] : null, $p['faq_title']);
                    break;

                default:
                    // Компоненты 1.5.0: содержимое из настроек (общих или своих для страницы)
                    $html = self::part_html($key, $o);
            }
            // Вид блока (2.4.0): фон, отступы, ширина, скрытие
            $inner .= self::decorate_block($html, $p['bstyle'][$key] ?? [], $key);
        }

        return self::wrap($inner, '', $o);
    }

    /**
     * Классы и CSS-переменные вида блока (2.4.0) добавляются к первому тегу его разметки.
     */
    public static function decorate_block(string $html, array $b, string $key): string
    {
        if ($html === '' || !$b) {
            return $html;
        }
        $b       = array_merge(['bg' => '', 'mt' => '', 'mb' => '', 'width' => '', 'hide' => ''], $b);
        $classes = ['wpd-b'];
        $style   = '';
        $bg      = (string) sanitize_hex_color((string) $b['bg']);
        if ($bg !== '' && $key !== 'hero') {
            $classes[] = 'wpd-b--bg';
            $classes[] = self::is_light_color($bg) ? 'wpd-b--bg-light' : 'wpd-b--bg-dark';
            $style    .= '--wpd-b-bg:' . $bg . ';';
        }
        foreach (['mt', 'mb'] as $side) {
            if ((string) $b[$side] !== '' && is_numeric($b[$side])) {
                $classes[] = 'wpd-b--' . $side;
                $style    .= '--wpd-b-' . $side . ':' . max(0, min(200, (int) $b[$side])) . 'px;';
            }
        }
        if (in_array($b['width'], ['narrow', 'full'], true)) {
            $classes[] = 'wpd-b--w-' . $b['width'];
        }
        if (in_array($b['hide'], ['mobile', 'desktop'], true)) {
            $classes[] = 'wpd-b--hide-' . $b['hide'];
        }
        if (count($classes) === 1 || !preg_match('/^(\s*)<([a-z][a-z0-9]*)\b([^>]*)>/i', $html, $m)) {
            return $html;
        }

        $attrs = $m[3];
        $class = esc_attr(implode(' ', $classes));
        if (preg_match('/\sclass="([^"]*)"/', $attrs)) {
            $attrs = preg_replace_callback('/\sclass="([^"]*)"/', function ($mm) use ($class) {
                return ' class="' . trim($mm[1] . ' ' . $class) . '"';
            }, $attrs, 1);
        } else {
            $attrs .= ' class="' . $class . '"';
        }
        if ($style !== '') {
            $style = esc_attr($style);
            if (preg_match('/\sstyle="([^"]*)"/', $attrs)) {
                $attrs = preg_replace_callback('/\sstyle="([^"]*)"/', function ($mm) use ($style) {
                    $old = rtrim(trim($mm[1]), ';');
                    return ' style="' . ($old !== '' ? $old . ';' : '') . $style . '"';
                }, $attrs, 1);
            } else {
                $attrs .= ' style="' . $style . '"';
            }
        }
        return $m[1] . '<' . $m[2] . $attrs . '>' . substr($html, strlen($m[0]));
    }

    /**
     * На странице записи get() возвращает настройки с её переопределениями (2.4.0): так их видят и оформление
     * страницы, и блоки «Олимпиада: …», и шорткоды, и подключение стилей.
     */
    public static function set_page_context(): void
    {
        if (is_singular()) {
            WPD_Settings::set_page((int) get_queried_object_id());
        }
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
        $o = WPD_Settings::get();
        if ($o['hide_title']) {
            $classes[] = 'wpd-has-design';
        }
        if ($o['design_style'] === 'soft') {
            $classes[] = 'wpd-soft-page'; // фон страницы — цвет «Фон страницы» (2.3.0)
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
        // Шрифты Google и свой CSS (1.9.0) — зависимость и встроенный стиль wpd.css: работают на сайте и в редакторе блоков
        $o    = WPD_Settings::get();
        $deps = [];
        $fonts = WPD_Settings::google_fonts_url($o);
        if ($fonts !== '') {
            wp_register_style('wpd-google-fonts', $fonts, [], null); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- версия ломает адрес API
            $deps[] = 'wpd-google-fonts';
        }
        wp_register_style('wpd-design', WPD_URL . 'assets/css/wpd.css', $deps, WPD_VERSION);
        wp_register_script('wpd-design', WPD_URL . 'assets/js/wpd.js', [], WPD_VERSION, true);
        $css = trim(str_replace('<', '', (string) $o['custom_css']));
        // 2.3.0: у оформленных страниц в стиле «Мягкий градиент» фон всей страницы — цвет «Фон страницы»
        $bg = (string) sanitize_hex_color((string) $o['bg']);
        if ($o['design_style'] === 'soft' && $bg !== '') {
            $css = 'body.wpd-soft-page{background-color:' . $bg . ';}' . ($css !== '' ? "\n" . $css : '');
        }
        if ($css !== '') {
            wp_add_inline_style('wpd-design', $css);
        }
    }

    public static function enqueue(): void
    {
        if (self::page_needs_assets()) {
            self::enqueue_assets();
            self::enqueue_page_assets();
            if (self::page_needs_icons()) {
                self::enqueue_icons();
            }
        }
    }

    /**
     * Стили своего оформления страницы (2.4.0): фон страницы для «Мягкого градиента», шрифты Google и свой CSS страницы.
     */
    public static function enqueue_page_assets(): void
    {
        $post_id = WPD_Settings::current_page();
        if (!$post_id) {
            return;
        }
        $p   = WPD_Page_Editor::get($post_id);
        $css = '';
        if (isset($p['ov']['page'])) {
            $o  = WPD_Settings::get();
            $bg = (string) sanitize_hex_color((string) $o['bg']);
            if ($o['design_style'] === 'soft' && $bg !== '') {
                $css .= 'body.wpd-soft-page{background-color:' . $bg . ';}';
            }
            $fonts = WPD_Settings::google_fonts_url($o);
            if ($fonts !== '' && $fonts !== WPD_Settings::google_fonts_url(WPD_Settings::get_global())) {
                wp_enqueue_style('wpd-google-fonts-page', $fonts, [], null); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
            }
        }
        $page_css = trim(str_replace('<', '', $p['page_css']));
        if ($page_css !== '') {
            $css .= ($css !== '' ? "\n" : '') . $page_css;
        }
        if ($css !== '') {
            wp_add_inline_style('wpd-design', $css);
        }
    }

    /**
     * Нужны ли иконки в <head>: на странице есть кнопки-ссылки или контакты (шорткод, блок или панель страницы).
     * Если не угадали, компонент подключит иконки сам при выводе (в подвале).
     */
    private static function page_needs_icons(): bool
    {
        $o = WPD_Settings::get();
        if ($o['hero_button_icon'] !== '' || $o['hero_button2_icon'] !== '') {
            return true; // иконки на кнопках главного блока (1.9.0)
        }
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
