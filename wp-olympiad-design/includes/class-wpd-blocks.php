<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Блоки редактора «Олимпиада: …». Динамические: разметку строит PHP (WPD_Renderer),
 * поэтому вид на сайте всегда совпадает с шорткодами и оформленными страницами.
 *
 * Значения атрибутов по умолчанию — текущие общие настройки. Пока поле блока не изменено,
 * оно не сохраняется в записи и при выводе берётся из настроек (поэтому следует за ними);
 * изменённое поле хранится в самом блоке. На сервере атрибуты регистрируются без значений
 * по умолчанию, чтобы незаданное поле всегда читалось из актуальных настроек.
 */
class WPD_Blocks
{
    const NS = 'wpd';

    public static function init(): void
    {
        add_action('init', [__CLASS__, 'register'], 20);
        add_filter('block_categories_all', [__CLASS__, 'category']);
    }

    /**
     * Описание блоков: заголовок, иконка, атрибуты и функция вывода.
     */
    public static function definitions(): array
    {
        $o = WPD_Settings::get();

        return [
            'hero' => [
                'title'       => 'Олимпиада: главный блок',
                'description' => 'Метка, крупный заголовок, текст, две кнопки и изображение справа, слева или фоном.',
                'icon'        => 'cover-image',
                'keywords'    => ['hero', 'заголовок', 'олимпиада', 'обложка'],
                'attributes'  => [
                    'badge'      => ['type' => 'string', 'default' => (string) $o['badge']],
                    'title'      => ['type' => 'string', 'default' => (string) $o['hero_title']],
                    'lead'       => ['type' => 'string', 'default' => (string) $o['hero_text']],
                    'buttonText' => ['type' => 'string', 'default' => (string) $o['hero_button_text']],
                    'buttonUrl'  => ['type' => 'string', 'default' => (string) $o['hero_button_url']],
                    'imageUrl'   => ['type' => 'string', 'default' => (string) $o['hero_image_url']],
                    'imageId'    => ['type' => 'integer', 'default' => 0],
                    'variant'    => ['type' => 'string', 'default' => (string) $o['hero_style']],
                    // 1.8.0
                    'buttonNewTab'  => ['type' => 'boolean', 'default' => (bool) $o['hero_button_new_tab']],
                    'button2Text'   => ['type' => 'string', 'default' => (string) $o['hero_button2_text']],
                    'button2Url'    => ['type' => 'string', 'default' => (string) $o['hero_button2_url']],
                    'button2NewTab' => ['type' => 'boolean', 'default' => (bool) $o['hero_button2_new_tab']],
                    'imageAlt'      => ['type' => 'string', 'default' => (string) $o['hero_image_alt']],
                    'imagePosition' => ['type' => 'string', 'default' => (string) $o['hero_image_position']],
                    'overlay'       => ['type' => 'integer', 'default' => (int) $o['hero_overlay']],
                    'bgColor'       => ['type' => 'string', 'default' => (string) $o['hero_bg']],
                    'height'        => ['type' => 'string', 'default' => (string) $o['hero_height']],
                    'textAlign'     => ['type' => 'string', 'default' => (string) $o['hero_align']],
                    'titleSize'     => ['type' => 'string', 'default' => (string) $o['hero_title_size']],
                ],
                'render'      => [__CLASS__, 'render_hero'],
            ],
            'countdown' => [
                'title'       => 'Олимпиада: обратный отсчёт',
                'description' => 'Таймер до начала и до окончания, кнопка-ссылка на время этапа. Без JavaScript показывается дата.',
                'icon'        => 'clock',
                'keywords'    => ['таймер', 'countdown', 'дата', 'окончание'],
                'attributes'  => [
                    'date'       => ['type' => 'string', 'default' => (string) $o['countdown_date']],
                    'label'      => ['type' => 'string', 'default' => (string) $o['countdown_label']],
                    'end'        => ['type' => 'string', 'default' => (string) $o['countdown_end']],
                    'endLabel'   => ['type' => 'string', 'default' => (string) $o['countdown_end_label']],
                    'doneText'   => ['type' => 'string', 'default' => (string) $o['countdown_done']],
                    'linkText'   => ['type' => 'string', 'default' => (string) $o['countdown_link_text']],
                    'linkUrl'    => ['type' => 'string', 'default' => (string) $o['countdown_link_url']],
                    'linkNewTab' => ['type' => 'boolean', 'default' => (bool) $o['countdown_link_new_tab']],
                ],
                'render'      => [__CLASS__, 'render_countdown'],
            ],
            'stats' => [
                'title'       => 'Олимпиада: статистика',
                'description' => 'Карточки с крупными цифрами.',
                'icon'        => 'chart-bar',
                'keywords'    => ['цифры', 'статистика', 'stats'],
                'attributes'  => [
                    'items' => ['type' => 'array', 'default' => WPD_Settings::stats_items($o['stats'])],
                ],
                'render'      => [__CLASS__, 'render_stats'],
            ],
            'docs' => [
                'title'       => 'Олимпиада: документы',
                'description' => 'Список ссылок на положение, результаты и другие документы.',
                'icon'        => 'media-document',
                'keywords'    => ['документы', 'pdf', 'положение'],
                'attributes'  => [
                    'title' => ['type' => 'string', 'default' => (string) $o['docs_title']],
                    'items' => ['type' => 'array', 'default' => WPD_Settings::docs_items($o['docs'])],
                ],
                'render'      => [__CLASS__, 'render_docs'],
            ],
            'faq' => [
                'title'       => 'Олимпиада: частые вопросы',
                'description' => 'Аккордеон вопросов и ответов, с поиском от четырёх вопросов.',
                'icon'        => 'editor-help',
                'keywords'    => ['faq', 'вопросы', 'аккордеон'],
                'attributes'  => [
                    'title' => ['type' => 'string', 'default' => (string) $o['faq_title']],
                    'items' => ['type' => 'array', 'default' => WPD_Settings::parse_faq($o['faq'])],
                ],
                'render'      => [__CLASS__, 'render_faq'],
            ],
            'slider' => [
                'title'       => 'Олимпиада: слайдер',
                'description' => 'Листающиеся слайды с изображением, заголовком, текстом и ссылкой.',
                'icon'        => 'slides',
                'keywords'    => ['слайдер', 'карусель', 'slider', 'баннер'],
                'attributes'  => [
                    'items'          => ['type' => 'array', 'default' => array_values((array) $o['slides'])],
                    'height'         => ['type' => 'integer', 'default' => (int) $o['slider_height']],
                    'autoplay'       => ['type' => 'boolean', 'default' => (bool) $o['slider_autoplay']],
                    'arrows'         => ['type' => 'boolean', 'default' => (bool) $o['slider_arrows']],
                    'dots'           => ['type' => 'boolean', 'default' => (bool) $o['slider_dots']],
                    'mobile'         => ['type' => 'boolean', 'default' => (bool) $o['slider_mobile']],
                    'mobileHeadings' => ['type' => 'boolean', 'default' => (bool) $o['slider_mobile_headings']],
                    'mobileTexts'    => ['type' => 'boolean', 'default' => (bool) $o['slider_mobile_texts']],
                ],
                'render'      => [__CLASS__, 'render_slider'],
            ],
            'buttons' => [
                'title'       => 'Олимпиада: кнопки-ссылки',
                'description' => 'Плитки с иконками для быстрых переходов.',
                'icon'        => 'screenoptions',
                'keywords'    => ['кнопки', 'ссылки', 'иконки', 'меню'],
                'attributes'  => [
                    'items' => ['type' => 'array', 'default' => array_values((array) $o['buttons'])],
                ],
                'render'      => [__CLASS__, 'render_buttons'],
            ],
            'cards' => [
                'title'       => 'Олимпиада: карточки',
                'description' => 'Цветные карточки-разделы с изображением, текстом и ссылкой.',
                'icon'        => 'grid-view',
                'keywords'    => ['карточки', 'разделы', 'плитки', 'cards'],
                'attributes'  => [
                    'title' => ['type' => 'string', 'default' => (string) $o['cards_title']],
                    'items' => ['type' => 'array', 'default' => array_values((array) $o['cards'])],
                ],
                'render'      => [__CLASS__, 'render_cards'],
            ],
            'notice' => [
                'title'       => 'Олимпиада: объявление',
                'description' => 'Заметное объявление: изображение, заголовок, текст и ссылка.',
                'icon'        => 'megaphone',
                'keywords'    => ['объявление', 'новость', 'notice', 'баннер'],
                'attributes'  => [
                    'layout'   => ['type' => 'string', 'default' => (string) $o['notice_layout']],
                    'image'    => ['type' => 'string', 'default' => (string) $o['notice_image']],
                    'color'    => ['type' => 'string', 'default' => (string) $o['notice_color']],
                    'url'      => ['type' => 'string', 'default' => (string) $o['notice_url']],
                    'newTab'   => ['type' => 'boolean', 'default' => (bool) $o['notice_new_tab']],
                    'preTitle' => ['type' => 'string', 'default' => (string) $o['notice_pre_title']],
                    'title'    => ['type' => 'string', 'default' => (string) $o['notice_title']],
                    'content'  => ['type' => 'string', 'default' => (string) $o['notice_content']],
                ],
                'render'      => [__CLASS__, 'render_notice'],
            ],
            'news' => [
                'title'       => 'Олимпиада: новости',
                'description' => 'Последние записи из рубрики — каруселью или сеткой.',
                'icon'        => 'admin-post',
                'keywords'    => ['новости', 'записи', 'блог', 'news'],
                'attributes'  => [
                    'title'    => ['type' => 'string', 'default' => (string) $o['news_title']],
                    'category' => ['type' => 'integer', 'default' => (int) $o['news_category']],
                    'count'    => ['type' => 'integer', 'default' => (int) $o['news_count']],
                    'layout'   => ['type' => 'string', 'default' => (string) $o['news_layout']],
                    'more'     => ['type' => 'string', 'default' => (string) $o['news_more']],
                ],
                'render'      => [__CLASS__, 'render_news'],
            ],
            'contacts' => [
                'title'       => 'Олимпиада: контакты',
                'description' => 'Название организации со ссылкой, адрес со ссылкой на карту, телефон, e-mail и кнопка «Связаться».',
                'icon'        => 'location-alt',
                'keywords'    => ['контакты', 'адрес', 'телефон', 'contacts'],
                'attributes'  => [
                    'title'   => ['type' => 'string', 'default' => (string) $o['contacts_title']],
                    'org'     => ['type' => 'string', 'default' => (string) $o['contact_org']],
                    'orgUrl'  => ['type' => 'string', 'default' => (string) $o['contact_org_url']],
                    'address' => ['type' => 'string', 'default' => (string) $o['contact_address']],
                    'city'    => ['type' => 'string', 'default' => (string) $o['contact_city']],
                    'phone'   => ['type' => 'string', 'default' => (string) $o['contact_phone']],
                    'email'   => ['type' => 'string', 'default' => (string) $o['contact_email']],
                    'map'     => ['type' => 'string', 'default' => (string) $o['contact_map']],
                    'page'    => ['type' => 'string', 'default' => (string) $o['contact_page']],
                    'code'    => ['type' => 'string', 'default' => (string) $o['contact_code']],
                ],
                'render'      => [__CLASS__, 'render_contacts'],
            ],
        ];
    }

    public static function register(): void
    {
        if (!function_exists('register_block_type')) {
            return;
        }

        wp_register_style('wpd-editor', WPD_URL . 'assets/css/wpd-editor.css', ['wpd-design', 'dashicons'], WPD_VERSION);
        wp_register_script(
            'wpd-blocks',
            WPD_URL . 'assets/js/wpd-blocks.js',
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render'],
            WPD_VERSION,
            true
        );

        $defs = self::definitions();
        $o    = WPD_Settings::get();

        $client = [];
        foreach ($defs as $slug => $def) {
            $client[self::NS . '/' . $slug] = [
                'title'       => $def['title'],
                'description' => $def['description'],
                'icon'        => $def['icon'],
                'keywords'    => $def['keywords'],
                'attributes'  => $def['attributes'],
            ];

            $server_attrs = array_map(function ($attr) {
                unset($attr['default']);
                return $attr;
            }, $def['attributes']);

            register_block_type(self::NS . '/' . $slug, [
                'api_version'     => 3,
                'title'           => $def['title'],
                'category'        => self::NS,
                'attributes'      => $server_attrs,
                'supports'        => ['html' => false, 'align' => ['wide', 'full']],
                'render_callback' => $def['render'],
                'editor_script'   => 'wpd-blocks',
                'editor_style'    => 'wpd-editor',
            ]);
        }

        wp_add_inline_script('wpd-blocks', 'window.wpdBlocks = ' . wp_json_encode([
            'blocks'      => $client,
            'category'    => self::NS,
            'vars'        => WPD_Settings::style_vars($o),
            // Модификаторы вида из вкладки «Оформление» (1.8.0) — те же классы, что на сайте
            'pageClass'   => implode(' ', WPD_Settings::page_classes($o)),
            'heroStyles'  => ['dark' => 'Тёмный', 'gradient' => 'Градиент основного цвета', 'light' => 'Светлый'],
            'heroOptions' => [
                'imagePosition' => WPD_Settings::field_map()['hero_image_position']['options'],
                'height'        => WPD_Settings::field_map()['hero_height']['options'],
                'textAlign'     => WPD_Settings::field_map()['hero_align']['options'],
                'titleSize'     => WPD_Settings::field_map()['hero_title_size']['options'],
            ],
            'palette'     => [$o['primary'], $o['accent'], $o['dark'], $o['text'], $o['bg']],
            'settingsUrl' => WPD_Settings::url(),
            // Часовой пояс таймера (1.6.1): подсказка у полей дат и подпись даты на холсте
            'countdownTz' => WPD_Settings::countdown_tz_label($o),
            'countdownTzName' => WPD_Settings::field_map()['countdown_tz']['options'][$o['countdown_tz']] ?? '',
            'icons'       => WPD_Settings::icons(),
            // Список рубрик нужен только редактору; на сайте лишний запрос не делаем
            'categories'  => is_admin() ? self::categories() : [],
            'noticeLayouts' => WPD_Settings::field_map()['notice_layout']['options'],
            'newsLayouts'   => WPD_Settings::field_map()['news_layout']['options'],
        ]) . ';', 'before');

        if (function_exists('register_block_pattern')) {
            if (function_exists('register_block_pattern_category')) {
                register_block_pattern_category(self::NS, ['label' => 'Олимпиада']);
            }
            register_block_pattern(self::NS . '/olympiad-page', [
                'title'       => 'Страница олимпиады',
                'description' => 'Главный блок, таймер, текст, статистика, документы и частые вопросы.',
                'categories'  => [self::NS],
                'content'     => implode("\n\n", [
                    '<!-- wp:wpd/hero /-->',
                    '<!-- wp:wpd/countdown /-->',
                    '<!-- wp:paragraph --><p>Расскажите об олимпиаде: кто участвует, как проходят этапы, где публикуются результаты.</p><!-- /wp:paragraph -->',
                    '<!-- wp:wpd/stats /-->',
                    '<!-- wp:wpd/docs /-->',
                    '<!-- wp:wpd/faq /-->',
                ]),
            ]);
            register_block_pattern(self::NS . '/front-page', [
                'title'       => 'Главная страница',
                'description' => 'Слайдер, кнопки-ссылки, объявление, карточки, новости и контакты — содержимое из общих настроек.',
                'categories'  => [self::NS],
                'content'     => implode("\n\n", [
                    '<!-- wp:wpd/slider {"align":"wide"} /-->',
                    '<!-- wp:wpd/buttons /-->',
                    '<!-- wp:wpd/notice /-->',
                    '<!-- wp:wpd/cards /-->',
                    '<!-- wp:wpd/news /-->',
                    '<!-- wp:wpd/contacts /-->',
                ]),
            ]);
        }
    }

    /**
     * Рубрики для выбора в блоке новостей.
     */
    private static function categories(): array
    {
        $out   = [['value' => 0, 'label' => 'Все рубрики']];
        $terms = get_terms(['taxonomy' => 'category', 'hide_empty' => false, 'number' => 200]);
        if (is_array($terms)) {
            foreach ($terms as $term) {
                $out[] = ['value' => (int) $term->term_id, 'label' => $term->name];
            }
        }
        return $out;
    }

    public static function category(array $categories): array
    {
        foreach ($categories as $cat) {
            if (($cat['slug'] ?? '') === self::NS) {
                return $categories;
            }
        }
        $categories[] = ['slug' => self::NS, 'title' => 'Олимпиада', 'icon' => null];
        return $categories;
    }

    /* ---------------------------------------------------------------
     * Вывод на сайте
     * ------------------------------------------------------------- */

    /**
     * Строковый атрибут блока. Отсутствует — значение из общих настроек ($fallback).
     */
    private static function str(array $a, string $key, string $fallback = ''): string
    {
        if (!array_key_exists($key, $a)) {
            return $fallback;
        }
        return is_scalar($a[$key]) ? (string) $a[$key] : '';
    }

    /**
     * Список элементов блока. Отсутствует — список из общих настроек.
     */
    private static function items(array $a, array $fallback): array
    {
        if (!array_key_exists('items', $a)) {
            return $fallback;
        }
        return is_array($a['items']) ? $a['items'] : [];
    }

    /**
     * Обёртка блока: .wpd-page с модификатором и пользовательским CSS-классом из редактора.
     */
    private static function wrap(string $inner, array $a, string $slug): string
    {
        if ($inner === '') {
            return '';
        }
        $classes = ['wpd-page--block', 'wpd-page--' . $slug];
        $align   = self::str($a, 'align');
        if (in_array($align, ['wide', 'full'], true)) {
            $classes[] = 'align' . $align;
        }
        foreach (preg_split('/\s+/', self::str($a, 'className')) as $class) {
            $class = sanitize_html_class($class);
            if ($class !== '') {
                $classes[] = $class;
            }
        }
        return WPD_Renderer::wrap($inner, implode(' ', $classes));
    }

    public static function render_hero($a): string
    {
        $a = is_array($a) ? $a : [];
        $o = WPD_Settings::get();
        return self::wrap(WPD_Renderer::hero_html($o, [
            'title'       => self::str($a, 'title', $o['hero_title']),
            'lead'        => self::str($a, 'lead', $o['hero_text']),
            'image'       => self::str($a, 'imageUrl', $o['hero_image_url']),
            'badge'       => self::str($a, 'badge', $o['badge']),
            'button_text' => self::str($a, 'buttonText', $o['hero_button_text']),
            'button_url'  => self::str($a, 'buttonUrl', $o['hero_button_url']),
            'style'       => self::str($a, 'variant', $o['hero_style']),
            'button_new_tab'  => self::flag($a, 'buttonNewTab', $o['hero_button_new_tab']),
            'button2_text'    => self::str($a, 'button2Text', $o['hero_button2_text']),
            'button2_url'     => self::str($a, 'button2Url', $o['hero_button2_url']),
            'button2_new_tab' => self::flag($a, 'button2NewTab', $o['hero_button2_new_tab']),
            'image_alt'       => self::str($a, 'imageAlt', $o['hero_image_alt']),
            'image_position'  => self::str($a, 'imagePosition', $o['hero_image_position']),
            'overlay'         => self::num($a, 'overlay', $o['hero_overlay']),
            'bg'              => self::str($a, 'bgColor', $o['hero_bg']),
            'height'          => self::str($a, 'height', $o['hero_height']),
            'align'           => self::str($a, 'textAlign', $o['hero_align']),
            'title_size'      => self::str($a, 'titleSize', $o['hero_title_size']),
        ]), $a, 'hero');
    }

    public static function render_countdown($a): string
    {
        $a = is_array($a) ? $a : [];
        $o = WPD_Settings::get();
        return self::wrap(WPD_Renderer::countdown_html($o, [
            'enabled'      => true,
            'date'         => self::str($a, 'date', $o['countdown_date']),
            'label'        => self::str($a, 'label', $o['countdown_label']),
            'end'          => self::str($a, 'end', $o['countdown_end']),
            'end_label'    => self::str($a, 'endLabel', $o['countdown_end_label']),
            'done'         => self::str($a, 'doneText', $o['countdown_done']),
            'link_text'    => self::str($a, 'linkText', $o['countdown_link_text']),
            'link_url'     => self::str($a, 'linkUrl', $o['countdown_link_url']),
            'link_new_tab' => self::flag($a, 'linkNewTab', $o['countdown_link_new_tab']),
        ]), $a, 'countdown');
    }

    public static function render_stats($a): string
    {
        $a = is_array($a) ? $a : [];
        $o = WPD_Settings::get();
        return self::wrap(WPD_Renderer::stats_html($o, self::items($a, WPD_Settings::stats_items($o['stats']))), $a, 'stats');
    }

    public static function render_docs($a): string
    {
        $a = is_array($a) ? $a : [];
        $o = WPD_Settings::get();
        $items = self::items($a, WPD_Settings::docs_items($o['docs']));
        return self::wrap(WPD_Renderer::docs_html($o, $items, self::str($a, 'title', $o['docs_title'])), $a, 'docs');
    }

    public static function render_faq($a): string
    {
        $a = is_array($a) ? $a : [];
        $o = WPD_Settings::get();
        $items = self::items($a, WPD_Settings::parse_faq($o['faq']));
        return self::wrap(WPD_Renderer::faq_html($o, $items, self::str($a, 'title', $o['faq_title'])), $a, 'faq');
    }

    /**
     * Логический и числовой атрибуты. Отсутствует — значение из общих настроек.
     */
    private static function flag(array $a, string $key, $fallback): bool
    {
        return array_key_exists($key, $a) ? (bool) $a[$key] : (bool) $fallback;
    }

    private static function num(array $a, string $key, $fallback): int
    {
        return array_key_exists($key, $a) && is_numeric($a[$key]) ? (int) $a[$key] : (int) $fallback;
    }

    public static function render_slider($a): string
    {
        $a = is_array($a) ? $a : [];
        $o = WPD_Settings::get();
        return self::wrap(WPD_Renderer::slider_html($o, [
            'items'           => self::items($a, (array) $o['slides']),
            'height'          => self::num($a, 'height', $o['slider_height']),
            'autoplay'        => self::flag($a, 'autoplay', $o['slider_autoplay']),
            'arrows'          => self::flag($a, 'arrows', $o['slider_arrows']),
            'dots'            => self::flag($a, 'dots', $o['slider_dots']),
            'mobile'          => self::flag($a, 'mobile', $o['slider_mobile']),
            'mobile_headings' => self::flag($a, 'mobileHeadings', $o['slider_mobile_headings']),
            'mobile_texts'    => self::flag($a, 'mobileTexts', $o['slider_mobile_texts']),
        ]), $a, 'slider');
    }

    public static function render_buttons($a): string
    {
        $a = is_array($a) ? $a : [];
        $o = WPD_Settings::get();
        return self::wrap(WPD_Renderer::buttons_html($o, self::items($a, (array) $o['buttons'])), $a, 'buttons');
    }

    public static function render_cards($a): string
    {
        $a = is_array($a) ? $a : [];
        $o = WPD_Settings::get();
        return self::wrap(WPD_Renderer::cards_html($o, self::items($a, (array) $o['cards']), self::str($a, 'title', (string) $o['cards_title'])), $a, 'cards');
    }

    public static function render_notice($a): string
    {
        $a = is_array($a) ? $a : [];
        $o = WPD_Settings::get();
        return self::wrap(WPD_Renderer::notice_html($o, [
            'layout'    => self::str($a, 'layout', $o['notice_layout']),
            'image'     => self::str($a, 'image', $o['notice_image']),
            'color'     => self::str($a, 'color', $o['notice_color']),
            'url'       => self::str($a, 'url', $o['notice_url']),
            'new_tab'   => self::flag($a, 'newTab', $o['notice_new_tab']),
            'pre_title' => self::str($a, 'preTitle', $o['notice_pre_title']),
            'title'     => self::str($a, 'title', $o['notice_title']),
            'content'   => self::str($a, 'content', $o['notice_content']),
        ]), $a, 'notice');
    }

    public static function render_news($a): string
    {
        $a = is_array($a) ? $a : [];
        $o = WPD_Settings::get();
        return self::wrap(WPD_Renderer::news_html($o, [
            'title'    => self::str($a, 'title', $o['news_title']),
            'category' => self::num($a, 'category', $o['news_category']),
            'count'    => self::num($a, 'count', $o['news_count']),
            'layout'   => self::str($a, 'layout', $o['news_layout']),
            'more'     => self::str($a, 'more', $o['news_more']),
        ]), $a, 'news');
    }

    public static function render_contacts($a): string
    {
        $a = is_array($a) ? $a : [];
        $o = WPD_Settings::get();
        $args = [];
        foreach (['title' => 'contacts_title', 'org' => 'contact_org', 'address' => 'contact_address', 'city' => 'contact_city',
                  'phone' => 'contact_phone', 'email' => 'contact_email', 'map' => 'contact_map', 'page' => 'contact_page',
                  'code' => 'contact_code'] as $key => $opt) {
            $args[$key] = self::str($a, $key, (string) $o[$opt]);
        }
        $args['org_url'] = self::str($a, 'orgUrl', (string) $o['contact_org_url']);
        return self::wrap(WPD_Renderer::contacts_html($o, $args), $a, 'contacts');
    }
}
