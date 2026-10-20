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
                'description' => 'Метка, крупный заголовок, текст, кнопка и изображение.',
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
                ],
                'render'      => [__CLASS__, 'render_hero'],
            ],
            'countdown' => [
                'title'       => 'Олимпиада: обратный отсчёт',
                'description' => 'Таймер до даты события. Без JavaScript показывается сама дата.',
                'icon'        => 'clock',
                'keywords'    => ['таймер', 'countdown', 'дата'],
                'attributes'  => [
                    'date'     => ['type' => 'string', 'default' => (string) $o['countdown_date']],
                    'label'    => ['type' => 'string', 'default' => (string) $o['countdown_label']],
                    'doneText' => ['type' => 'string', 'default' => (string) $o['countdown_done']],
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
        ];
    }

    public static function register(): void
    {
        if (!function_exists('register_block_type')) {
            return;
        }

        wp_register_style('wpd-editor', WPD_URL . 'assets/css/wpd-editor.css', ['wpd-design'], WPD_VERSION);
        wp_register_script(
            'wpd-blocks',
            WPD_URL . 'assets/js/wpd-blocks.js',
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components'],
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
            'vars'        => [
                '--wpd-primary' => $o['primary'],
                '--wpd-accent'  => $o['accent'],
                '--wpd-dark'    => $o['dark'],
                '--wpd-text'    => $o['text'],
                '--wpd-bg'      => $o['bg'],
                '--wpd-radius'  => (int) $o['radius'] . 'px',
                '--wpd-font'    => $o['font'],
            ],
            'heroStyles'  => ['dark' => 'Тёмный', 'gradient' => 'Градиент основного цвета', 'light' => 'Светлый'],
            'settingsUrl' => admin_url('options-general.php?page=' . WPD_Settings::PAGE),
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
        }
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
        ]), $a, 'hero');
    }

    public static function render_countdown($a): string
    {
        $a = is_array($a) ? $a : [];
        $o = WPD_Settings::get();
        return self::wrap(WPD_Renderer::countdown_html($o, [
            'enabled' => true,
            'date'    => self::str($a, 'date', $o['countdown_date']),
            'label'   => self::str($a, 'label', $o['countdown_label']),
            'done'    => self::str($a, 'doneText', $o['countdown_done']),
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
}
