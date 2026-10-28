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
        // 2.4.0: своё оформление страницы в предпросмотре блоков и на холсте редактора
        add_filter('rest_request_before_callbacks', [__CLASS__, 'rest_page_context'], 10, 3);
        add_action('enqueue_block_editor_assets', [__CLASS__, 'editor_page_data']);
    }

    /**
     * Предпросмотр блока (ServerSideRender передаёт post_id записи): блок выводится с настройками этой страницы.
     * Права на запись проверяет сам маршрут block-renderer.
     *
     * @param mixed $response
     * @return mixed
     */
    public static function rest_page_context($response, $handler, $request)
    {
        if ($request instanceof WP_REST_Request
            && strpos($request->get_route(), '/wp/v2/block-renderer/' . self::NS . '/') === 0
            && (int) $request->get_param('post_id') > 0) {
            WPD_Settings::set_page((int) $request->get_param('post_id'));
        }
        return $response;
    }

    /**
     * Данные холста для редактируемой записи: CSS-переменные и классы с учётом её своего оформления.
     */
    public static function editor_page_data(): void
    {
        $post = get_post();
        if (!$post instanceof WP_Post || !wp_script_is('wpd-blocks', 'registered')) {
            return;
        }
        $o = WPD_Page_Editor::options((int) $post->ID);
        wp_add_inline_script('wpd-blocks', 'window.wpdBlocks = Object.assign(window.wpdBlocks || {}, ' . wp_json_encode([
            'postId'    => (int) $post->ID,
            'vars'      => WPD_Settings::style_vars($o),
            'pageClass' => implode(' ', WPD_Settings::page_classes($o)),
            'softOrb'   => !empty($o['hero_soft_orb']) ? WPD_URL . WPD_Renderer::ORB_IMAGE : '',
            'palette'   => [$o['primary'], $o['accent'], $o['dark'], $o['text'], $o['bg']],
            'iconSizes' => self::icon_sizes_data($o),
            'buttonsSettings' => self::buttons_settings_data($o),
            'lookSettings'    => self::look_settings_data($o),
        ]) . ');', 'before');
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
                'description' => 'Метка, крупный заголовок, текст, две кнопки с иконками, таймер и изображение справа, слева или фоном.',
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
                    // 1.9.0
                    'buttonIcon'    => ['type' => 'string', 'default' => (string) $o['hero_button_icon']],
                    'button2Icon'   => ['type' => 'string', 'default' => (string) $o['hero_button2_icon']],
                    'showCountdown' => ['type' => 'boolean', 'default' => (bool) $o['hero_countdown']],
                    'imageFit'      => ['type' => 'string', 'default' => (string) $o['hero_image_fit']],
                    'imageStyle'    => ['type' => 'string', 'default' => (string) $o['hero_image_style']],
                    'imageMobile'   => ['type' => 'boolean', 'default' => (bool) $o['hero_image_mobile']],
                    'overlayColor'  => ['type' => 'string', 'default' => (string) $o['hero_overlay_color']],
                    'badgeColor'    => ['type' => 'string', 'default' => (string) $o['hero_badge_color']],
                    'titleColor'    => ['type' => 'string', 'default' => (string) $o['hero_title_color']],
                    'textColor'     => ['type' => 'string', 'default' => (string) $o['hero_text_color']],
                    'minHeight'     => ['type' => 'integer', 'default' => (int) $o['hero_min_height']],
                    'padding'       => ['type' => 'integer', 'default' => (int) $o['hero_padding']],
                    'verticalAlign' => ['type' => 'string', 'default' => (string) $o['hero_valign']],
                    'textWidth'     => ['type' => 'integer', 'default' => (int) $o['hero_text_width']],
                    // 2.5.0
                    'buttonIconSize' => ['type' => 'integer', 'default' => (int) $o['hero_button_icon_size']],
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
                ] + self::look_attr_defs('countdown', $o),
                'render'      => [__CLASS__, 'render_countdown'],
            ],
            'stats' => [
                'title'       => 'Олимпиада: статистика',
                'description' => 'Карточки с крупными цифрами.',
                'icon'        => 'chart-bar',
                'keywords'    => ['цифры', 'статистика', 'stats'],
                'attributes'  => [
                    'items' => ['type' => 'array', 'default' => WPD_Settings::stats_items($o['stats'])],
                ] + self::look_attr_defs('stats', $o),
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
                    'iconSize' => ['type' => 'integer', 'default' => (int) $o['docs_icon_size']],
                ] + self::look_attr_defs('docs', $o),
                'render'      => [__CLASS__, 'render_docs'],
            ],
            'faq' => [
                'title'       => 'Олимпиада: частые вопросы',
                'description' => 'Аккордеон вопросов и ответов, с поиском от четырёх вопросов.',
                'icon'        => 'editor-help',
                'keywords'    => ['faq', 'вопросы', 'аккордеон'],
                'attributes'  => [
                    'title' => ['type' => 'string', 'default' => (string) $o['faq_title']],
                    'items' => ['type' => 'array', 'default' => WPD_Settings::faq_items($o['faq'])],
                ] + self::look_attr_defs('faq', $o),
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
                ] + self::look_attr_defs('slider', $o),
                'render'      => [__CLASS__, 'render_slider'],
            ],
            'buttons' => [
                'title'       => 'Олимпиада: кнопки-ссылки',
                'description' => 'Плитки с иконками для быстрых переходов.',
                'icon'        => 'screenoptions',
                'keywords'    => ['кнопки', 'ссылки', 'иконки', 'меню'],
                'attributes'  => [
                    'items' => ['type' => 'array', 'default' => array_values((array) $o['buttons'])],
                    'iconSize' => ['type' => 'integer', 'default' => (int) $o['buttons_icon_size']],
                ] + self::buttons_attr_defs($o),
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
                ] + self::look_attr_defs('cards', $o),
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
                ] + self::look_attr_defs('notice', $o),
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
                ] + self::look_attr_defs('news', $o),
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
                    'iconSize' => ['type' => 'integer', 'default' => (int) $o['contacts_icon_size']],
                ] + self::look_attr_defs('contacts', $o),
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
            'heroStyles'  => WPD_Settings::hero_styles(),
            // 2.3.0: встроенная иллюстрация для предпросмотра стиля «Мягкий градиент» без изображения
            'softOrb'     => !empty($o['hero_soft_orb']) ? WPD_URL . WPD_Renderer::ORB_IMAGE : '',
            'heroOptions' => [
                'imagePosition' => WPD_Settings::field_map()['hero_image_position']['options'],
                'height'        => WPD_Settings::field_map()['hero_height']['options'],
                'textAlign'     => WPD_Settings::field_map()['hero_align']['options'],
                'titleSize'     => WPD_Settings::field_map()['hero_title_size']['options'],
                'imageFit'      => WPD_Settings::field_map()['hero_image_fit']['options'],
                'imageStyle'    => WPD_Settings::field_map()['hero_image_style']['options'],
                'verticalAlign' => WPD_Settings::field_map()['hero_valign']['options'],
            ],
            'palette'     => [$o['primary'], $o['accent'], $o['dark'], $o['text'], $o['bg']],
            'settingsUrl' => WPD_Settings::url(),
            // Часовой пояс таймера (1.6.1): подсказка у полей дат и подпись даты на холсте
            'countdownTz' => WPD_Settings::countdown_tz_label($o),
            'countdownTzName' => WPD_Settings::field_map()['countdown_tz']['options'][$o['countdown_tz']] ?? '',
            'icons'       => WPD_Settings::icons(),
            // 2.5.0: размеры иконок — CSS-переменная, границы и размер по умолчанию для атрибутов блоков
            'iconSizes'   => self::icon_sizes_data(),
            // 2.6.0: кнопки-ссылки — варианты списков и значения настроек (для атрибутов, совпадающих со значением по умолчанию)
            'buttonsOptions'  => self::buttons_options_data(),
            'buttonsSettings' => self::buttons_settings_data($o),
            // 2.7.0: «Раскладка и вид» и свои параметры остальных блоков — описание полей и значения настроек
            'lookOptions'     => self::look_options_data(),
            'lookSettings'    => self::look_settings_data($o),
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
    private static function wrap(string $inner, array $a, string $slug, ?array $o = null): string
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
        return WPD_Renderer::wrap($inner, implode(' ', $classes), $o);
    }

    /**
     * Размер иконок из атрибута блока (2.5.0): положительное значение заменяет настройку, 0 или отсутствие —
     * размер из общих настроек (или своих для страницы).
     */
    public static function with_icon_size(array $o, array $a, string $attr, string $key): array
    {
        if (array_key_exists($attr, $a) && is_numeric($a[$attr]) && (int) $a[$attr] > 0) {
            $o[$key] = (int) $a[$attr];
        }
        return $o;
    }

    /**
     * Атрибуты блока «Кнопки-ссылки» (2.6.0) → поля настроек. Строки и цвета: присутствующий атрибут заменяет
     * настройку (пустой цвет — без цвета, пустой заголовок — без заголовка); числа: больше 0 — заменяет, 0 — как в настройках.
     */
    const BUTTONS_ATTRS = [
        'title'         => 'buttons_title',
        'columns'       => 'buttons_columns',
        'columnsMobile' => 'buttons_columns_mobile',
        'gap'           => 'buttons_gap',
        'height'        => 'buttons_height',
        'textAlign'     => 'buttons_align',
        'layout'        => 'buttons_layout',
        'variant'       => 'buttons_variant',
        'radius'        => 'buttons_radius',
        'textSize'      => 'buttons_text_size',
        'bgColor'       => 'buttons_bg',
        'iconColor'     => 'buttons_icon_color',
        'textColor'     => 'buttons_text_color',
        'hoverColor'    => 'buttons_hover_color',
    ];

    /** Определения атрибутов блока кнопок; значения по умолчанию — текущие настройки (на сервере потом убираются). */
    private static function buttons_attr_defs(array $o): array
    {
        $map = WPD_Settings::field_map();
        $out = [];
        foreach (self::BUTTONS_ATTRS as $attr => $key) {
            $out[$attr] = $map[$key]['type'] === 'number'
                ? ['type' => 'integer', 'default' => (int) $o[$key]]
                : ['type' => 'string', 'default' => (string) $o[$key]];
        }
        return $out;
    }

    /** Значения настроек кнопок для холста: атрибут → значение (общие или свои для страницы). */
    public static function buttons_settings_data(?array $o = null): array
    {
        $o   = $o ?? WPD_Settings::get();
        $map = WPD_Settings::field_map();
        $out = [];
        foreach (self::BUTTONS_ATTRS as $attr => $key) {
            $out[$attr] = $map[$key]['type'] === 'number' ? (int) $o[$key] : (string) $o[$key];
        }
        return $out;
    }

    /** Варианты выпадающих списков и границы чисел для боковой панели блока кнопок. */
    public static function buttons_options_data(): array
    {
        $map = WPD_Settings::field_map();
        $out = [];
        foreach (self::BUTTONS_ATTRS as $attr => $key) {
            $f = $map[$key];
            if ($f['type'] === 'select') {
                $out[$attr] = ['label' => $f['label'], 'options' => $f['options']];
            } elseif ($f['type'] === 'number') {
                $out[$attr] = ['label' => $f['label'], 'max' => (int) $f['max'], 'help' => (string) ($f['desc'] ?? '')];
            } else {
                $out[$attr] = ['label' => $f['label'], 'help' => (string) ($f['desc'] ?? '')];
            }
        }
        return $out;
    }

    /** Подставляет атрибуты блока кнопок в настройки (значения проверяет рендерер). */
    public static function with_buttons_attrs(array $o, array $a): array
    {
        $map = WPD_Settings::field_map();
        foreach (self::BUTTONS_ATTRS as $attr => $key) {
            if (!array_key_exists($attr, $a) || !is_scalar($a[$attr])) {
                continue;
            }
            if ($map[$key]['type'] === 'number') {
                if (is_numeric($a[$attr]) && (int) $a[$attr] > 0) {
                    $o[$key] = min((int) $map[$key]['max'], (int) $a[$attr]);
                }
            } elseif ($map[$key]['type'] === 'text') {
                $o[$key] = sanitize_text_field((string) $a[$attr]);
            } else {
                $o[$key] = (string) $a[$attr];
            }
        }
        return $o;
    }

    /** Имена атрибутов блока для общих параметров «Раскладки и вида» (2.7.0). */
    const LOOK_ATTR_NAMES = [
        'columns' => 'columns', 'columns_mobile' => 'columnsMobile', 'gap' => 'gap', 'align' => 'textAlign', 'variant' => 'variant',
        'radius' => 'radius', 'text_size' => 'textSize', 'bg' => 'bgColor', 'accent' => 'accentColor', 'text_color' => 'textColor',
    ];

    /** Свои параметры блоков (2.7.0): атрибут → поле настроек. */
    const LOOK_EXTRA_ATTRS = [
        'countdown' => ['valueSize' => 'countdown_value_size'],
        'stats'     => ['title' => 'stats_title', 'numSize' => 'stats_num_size'],
        'docs'      => ['newTab' => 'docs_new_tab', 'showType' => 'docs_show_type'],
        'faq'       => ['openFirst' => 'faq_open_first', 'single' => 'faq_single', 'numbered' => 'faq_numbered'],
        'slider'    => ['interval' => 'slider_interval', 'captionPos' => 'slider_caption_pos'],
        'cards'     => ['moreText' => 'cards_more_text', 'imageHeight' => 'cards_image_height'],
        'notice'    => ['buttonText' => 'notice_button_text', 'from' => 'notice_from', 'to' => 'notice_to'],
        'news'      => ['excerptWords' => 'news_excerpt_words', 'showImage' => 'news_show_image', 'showDate' => 'news_show_date', 'showExcerpt' => 'news_show_excerpt'],
        'contacts'  => ['buttonText' => 'contacts_button_text', 'showIcons' => 'contacts_show_icons'],
    ];

    /** Атрибуты «Раскладки и вида» и своих параметров блока: атрибут → поле настроек. */
    public static function look_attrs(string $slug): array
    {
        $out = [];
        foreach (WPD_Settings::LOOK_BLOCKS[$slug] ?? [] as $k) {
            $out[self::LOOK_ATTR_NAMES[$k]] = $slug . '_' . $k;
        }
        return $out + (self::LOOK_EXTRA_ATTRS[$slug] ?? []);
    }

    /** Тип атрибута по типу поля настроек. */
    private static function attr_type(string $field_type): string
    {
        return $field_type === 'number' ? 'integer' : ($field_type === 'checkbox' ? 'boolean' : 'string');
    }

    /** Значение настройки в типе атрибута. */
    private static function attr_value(string $field_type, $value)
    {
        return $field_type === 'number' ? (int) $value : ($field_type === 'checkbox' ? (bool) $value : (string) $value);
    }

    /** Определения атрибутов «Раскладки и вида» блока; значения по умолчанию — текущие настройки. */
    private static function look_attr_defs(string $slug, array $o): array
    {
        $map = WPD_Settings::field_map();
        $out = [];
        foreach (self::look_attrs($slug) as $attr => $key) {
            $out[$attr] = ['type' => self::attr_type($map[$key]['type']), 'default' => self::attr_value($map[$key]['type'], $o[$key])];
        }
        return $out;
    }

    /** Значения настроек «Раскладки и вида» для холста: блок → атрибут → значение (общие или свои для страницы). */
    public static function look_settings_data(?array $o = null): array
    {
        $o   = $o ?? WPD_Settings::get();
        $map = WPD_Settings::field_map();
        $out = [];
        foreach (array_keys(WPD_Settings::LOOK_BLOCKS) as $slug) {
            foreach (self::look_attrs($slug) as $attr => $key) {
                $out[$slug][$attr] = self::attr_value($map[$key]['type'], $o[$key]);
            }
        }
        return $out;
    }

    /**
     * Описание полей «Раскладки и вида» для боковой панели блоков: блок → атрибут → подпись, тип, варианты или границы,
     * группа панели (look — раскладка и вид, colors — цвета, extra — параметры блока).
     */
    public static function look_options_data(): array
    {
        $map = WPD_Settings::field_map();
        $out = [];
        foreach (array_keys(WPD_Settings::LOOK_BLOCKS) as $slug) {
            foreach (self::look_attrs($slug) as $attr => $key) {
                $f = $map[$key];
                $generic = substr($key, strlen($slug) + 1);
                $group = in_array($generic, WPD_Settings::LOOK_BLOCKS[$slug], true)
                    ? (in_array($generic, ['bg', 'accent', 'text_color'], true) ? 'colors' : 'look')
                    : 'extra';
                $d = ['label' => $f['label'], 'type' => $f['type'], 'group' => $group, 'help' => (string) ($f['desc'] ?? '')];
                if ($f['type'] === 'select') {
                    $d['options'] = $f['options'];
                } elseif ($f['type'] === 'number') {
                    $d['min'] = (int) $f['min'];
                    $d['max'] = (int) $f['max'];
                }
                $out[$slug][$attr] = $d;
            }
        }
        return $out;
    }

    /**
     * Подставляет атрибуты «Раскладки и вида» блока в настройки. Строки, цвета, списки, флажки и даты: присутствующий атрибут
     * заменяет настройку; числа: больше 0 — заменяет, 0 — как в настройках. Значения списков и цветов проверяет рендерер.
     */
    public static function with_look_attrs(array $o, array $a, string $slug): array
    {
        $map = WPD_Settings::field_map();
        foreach (self::look_attrs($slug) as $attr => $key) {
            if (!array_key_exists($attr, $a) || !is_scalar($a[$attr])) {
                continue;
            }
            switch ($map[$key]['type']) {
                case 'number':
                    if (is_numeric($a[$attr]) && (int) $a[$attr] > 0) {
                        $o[$key] = max((int) $map[$key]['min'], min((int) $map[$key]['max'], (int) $a[$attr]));
                    }
                    break;
                case 'checkbox':
                    $o[$key] = $a[$attr] ? 1 : 0;
                    break;
                case 'datetime':
                    $o[$key] = WPD_Settings::normalize_datetime((string) $a[$attr]);
                    break;
                case 'text':
                    $o[$key] = sanitize_text_field((string) $a[$attr]);
                    break;
                default:
                    $o[$key] = (string) $a[$attr];
            }
        }
        return $o;
    }

    /** Данные о размерах иконок для редактора: ключ блока → атрибут, переменная, границы, значение настроек. */
    public static function icon_sizes_data(?array $o = null): array
    {
        $o   = $o ?? WPD_Settings::get();
        $map = ['buttons' => ['iconSize', 'buttons_icon_size'], 'contacts' => ['iconSize', 'contacts_icon_size'],
                'hero' => ['buttonIconSize', 'hero_button_icon_size'], 'docs' => ['iconSize', 'docs_icon_size']];
        $out = [];
        foreach ($map as $slug => $pair) {
            $d = WPD_Settings::ICON_SIZES[$pair[1]];
            $out[$slug] = ['attr' => $pair[0], 'var' => $d['var'], 'min' => $d['min'], 'max' => $d['max'],
                           'default' => $d['default'], 'setting' => (int) $o[$pair[1]]];
        }
        return $out;
    }

    public static function render_hero($a): string
    {
        $a = is_array($a) ? $a : [];
        $o = self::with_icon_size(WPD_Settings::get(), $a, 'buttonIconSize', 'hero_button_icon_size');
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
            'button_icon'     => self::str($a, 'buttonIcon', $o['hero_button_icon']),
            'button2_icon'    => self::str($a, 'button2Icon', $o['hero_button2_icon']),
            'countdown'       => self::flag($a, 'showCountdown', $o['hero_countdown']),
            'image_fit'       => self::str($a, 'imageFit', $o['hero_image_fit']),
            'image_style'     => self::str($a, 'imageStyle', $o['hero_image_style']),
            'image_mobile'    => self::flag($a, 'imageMobile', $o['hero_image_mobile']),
            'overlay_color'   => self::str($a, 'overlayColor', $o['hero_overlay_color']),
            'badge_color'     => self::str($a, 'badgeColor', $o['hero_badge_color']),
            'title_color'     => self::str($a, 'titleColor', $o['hero_title_color']),
            'text_color'      => self::str($a, 'textColor', $o['hero_text_color']),
            'min_height'      => self::num($a, 'minHeight', $o['hero_min_height']),
            'padding'         => self::num($a, 'padding', $o['hero_padding']),
            'valign'          => self::str($a, 'verticalAlign', $o['hero_valign']),
            'text_width'      => self::num($a, 'textWidth', $o['hero_text_width']),
        ]), $a, 'hero', $o);
    }

    public static function render_countdown($a): string
    {
        $a = is_array($a) ? $a : [];
        $o = self::with_look_attrs(WPD_Settings::get(), $a, 'countdown');
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
        ]), $a, 'countdown', $o);
    }

    public static function render_stats($a): string
    {
        $a = is_array($a) ? $a : [];
        $o = self::with_look_attrs(WPD_Settings::get(), $a, 'stats');
        return self::wrap(WPD_Renderer::stats_html($o, self::items($a, WPD_Settings::stats_items($o['stats']))), $a, 'stats', $o);
    }

    public static function render_docs($a): string
    {
        $a = is_array($a) ? $a : [];
        $o = self::with_look_attrs(self::with_icon_size(WPD_Settings::get(), $a, 'iconSize', 'docs_icon_size'), $a, 'docs');
        $items = self::items($a, WPD_Settings::docs_items($o['docs']));
        return self::wrap(WPD_Renderer::docs_html($o, $items, self::str($a, 'title', $o['docs_title'])), $a, 'docs', $o);
    }

    public static function render_faq($a): string
    {
        $a = is_array($a) ? $a : [];
        $o = self::with_look_attrs(WPD_Settings::get(), $a, 'faq');
        $items = self::items($a, WPD_Settings::faq_items($o['faq']));
        return self::wrap(WPD_Renderer::faq_html($o, $items, self::str($a, 'title', $o['faq_title'])), $a, 'faq', $o);
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
        $o = self::with_look_attrs(WPD_Settings::get(), $a, 'slider');
        return self::wrap(WPD_Renderer::slider_html($o, [
            'items'           => self::items($a, (array) $o['slides']),
            'height'          => self::num($a, 'height', $o['slider_height']),
            'autoplay'        => self::flag($a, 'autoplay', $o['slider_autoplay']),
            'arrows'          => self::flag($a, 'arrows', $o['slider_arrows']),
            'dots'            => self::flag($a, 'dots', $o['slider_dots']),
            'mobile'          => self::flag($a, 'mobile', $o['slider_mobile']),
            'mobile_headings' => self::flag($a, 'mobileHeadings', $o['slider_mobile_headings']),
            'mobile_texts'    => self::flag($a, 'mobileTexts', $o['slider_mobile_texts']),
        ]), $a, 'slider', $o);
    }

    public static function render_buttons($a): string
    {
        $a = is_array($a) ? $a : [];
        $o = self::with_buttons_attrs(self::with_icon_size(WPD_Settings::get(), $a, 'iconSize', 'buttons_icon_size'), $a);
        return self::wrap(WPD_Renderer::buttons_html($o, self::items($a, (array) $o['buttons'])), $a, 'buttons', $o);
    }

    public static function render_cards($a): string
    {
        $a = is_array($a) ? $a : [];
        $o = self::with_look_attrs(WPD_Settings::get(), $a, 'cards');
        return self::wrap(WPD_Renderer::cards_html($o, self::items($a, (array) $o['cards']), self::str($a, 'title', (string) $o['cards_title'])), $a, 'cards', $o);
    }

    public static function render_notice($a): string
    {
        $a = is_array($a) ? $a : [];
        $o = self::with_look_attrs(WPD_Settings::get(), $a, 'notice');
        return self::wrap(WPD_Renderer::notice_html($o, [
            'layout'    => self::str($a, 'layout', $o['notice_layout']),
            'image'     => self::str($a, 'image', $o['notice_image']),
            'color'     => self::str($a, 'color', $o['notice_color']),
            'url'       => self::str($a, 'url', $o['notice_url']),
            'new_tab'   => self::flag($a, 'newTab', $o['notice_new_tab']),
            'pre_title' => self::str($a, 'preTitle', $o['notice_pre_title']),
            'title'     => self::str($a, 'title', $o['notice_title']),
            'content'   => self::str($a, 'content', $o['notice_content']),
        ]), $a, 'notice', $o);
    }

    public static function render_news($a): string
    {
        $a = is_array($a) ? $a : [];
        $o = self::with_look_attrs(WPD_Settings::get(), $a, 'news');
        return self::wrap(WPD_Renderer::news_html($o, [
            'title'    => self::str($a, 'title', $o['news_title']),
            'category' => self::num($a, 'category', $o['news_category']),
            'count'    => self::num($a, 'count', $o['news_count']),
            'layout'   => self::str($a, 'layout', $o['news_layout']),
            'more'     => self::str($a, 'more', $o['news_more']),
        ]), $a, 'news', $o);
    }

    public static function render_contacts($a): string
    {
        $a = is_array($a) ? $a : [];
        $o = self::with_look_attrs(self::with_icon_size(WPD_Settings::get(), $a, 'iconSize', 'contacts_icon_size'), $a, 'contacts');
        $args = [];
        foreach (['title' => 'contacts_title', 'org' => 'contact_org', 'address' => 'contact_address', 'city' => 'contact_city',
                  'phone' => 'contact_phone', 'email' => 'contact_email', 'map' => 'contact_map', 'page' => 'contact_page',
                  'code' => 'contact_code'] as $key => $opt) {
            $args[$key] = self::str($a, $key, (string) $o[$opt]);
        }
        $args['org_url'] = self::str($a, 'orgUrl', (string) $o['contact_org_url']);
        return self::wrap(WPD_Renderer::contacts_html($o, $args), $a, 'contacts', $o);
    }
}
