<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Панель «Блоки олимпиады» в редакторе записи: порядок и видимость блоков,
 * собственное содержимое главного блока, статистики, документов и FAQ для конкретной страницы.
 * С 2.4.0 — своё оформление страницы: любые группы общих настроек (цвета и вид, главный блок, таймер,
 * компоненты), вид каждого блока (фон, отступы, ширина, скрытие), свой CSS, копирование, сброс,
 * экспорт и импорт. Работает и в классическом, и в блочном редакторе (как обычный мета-бокс);
 * в блочном редакторе основное оформление дублируется в боковой колонке «Оформление олимпиады».
 */
class WPD_Page_Editor
{
    const META   = '_wpd_page';
    const NONCE  = 'wpd_page_nonce';
    const ACTION = 'wpd_save_page';

    /** Списки, которые можно задать для страницы, и ключи их строк. */
    const LISTS = [
        'stats' => ['num', 'label', 'icon', 'color', 'hidden', 'from', 'to'],
        'docs'  => ['title', 'url', 'desc', 'badge', 'type', 'color', 'new_tab', 'download', 'hidden', 'from', 'to', 'src', 'nf'],
        'faq'   => ['q', 'a', 'open', 'hidden', 'from', 'to'],
    ];

    /**
     * Поля общих настроек, которые не переопределяются группами (2.4.0): у них уже есть своё поле в панели
     * (заголовок, метка, текст, кнопка, изображение и стиль главного блока, списки) или они общие для сайта.
     */
    const OV_EXCLUDE = ['hero_title', 'hero_text', 'badge', 'hero_button_text', 'hero_button_url', 'hero_image_url', 'hero_style',
        'custom_css', 'stats', 'docs', 'faq', 'docs_title', 'faq_title'];

    /** Вид отдельного блока (2.4.0). */
    const BLOCK_WIDTHS = ['' => 'Обычная', 'narrow' => 'Узкая (760 px)', 'full' => 'Во всю ширину экрана'];
    const BLOCK_HIDE   = ['' => 'Везде', 'mobile' => 'Скрыть на телефоне', 'desktop' => 'Скрыть на компьютере'];

    /** Мета-поле-отметка: правка панели в блочном редакторе делает запись «изменённой», чтобы кнопка сохранения стала активной. */
    const REV_META = '_wpd_rev';

    public static function init(): void
    {
        add_action('add_meta_boxes', [__CLASS__, 'add_meta_box']);
        add_action('save_post', [__CLASS__, 'save'], 10, 2);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);
        add_action('enqueue_block_editor_assets', [__CLASS__, 'enqueue_sidebar']);
        add_action('rest_api_init', [__CLASS__, 'register_rest']);
        add_action('init', [__CLASS__, 'register_meta']);
    }

    /**
     * Группы общих настроек, которые можно задать для страницы (2.4.0): секции настроек и отдельные поля.
     */
    public static function groups(): array
    {
        return [
            'page'     => ['title' => 'Оформление страницы', 'sections' => ['colors', 'look', 'fonts', 'behavior'], 'extra' => ['google_fonts', 'docs_icon_size'],
                           'desc' => 'Стиль оформления, палитра и цвета, шрифты (свои файлы, сервисы, шрифты заголовка, кнопок и цифр), размеры, кнопки, тени, ширина и отступы, заголовок темы, размер значков документов.'],
            'hero'     => ['title' => 'Главный блок: кнопки, изображение, вид', 'sections' => ['hero', 'hero_image', 'hero_look', 'hero_logo'], 'extra' => [],
                           'desc' => 'Логотип, вторая кнопка и иконки, таймер в блоке, положение, подгонка и рамка изображения, затемнение, цвета, высота, отступы, выравнивание, размер заголовка, размер иконок на кнопках.'],
            'features' => ['title' => 'Таймер и эффекты', 'sections' => ['features', 'countdown_look'], 'extra' => [],
                           'desc' => 'Свои даты, подписи и кнопка таймера, часовой пояс, поиск по FAQ, анимация чисел, вид таймера.'],
            // 2.7.0: раскладка и вид статистики, документов и FAQ (содержимое списков — в панелях выше)
            'stats'    => ['title' => 'Статистика: заголовок, раскладка и вид', 'sections' => ['stats', 'stats_look'], 'extra' => [],
                           'desc' => 'Заголовок, колонки, отступы, вид, скругление, размер текста и чисел, цвета карточек.'],
            'docs'     => ['title' => 'Документы: раскладка и вид', 'sections' => ['docs', 'docs_look'], 'extra' => [],
                           'desc' => 'Новая вкладка, колонки, отступы, вид, скругление, размер текста, значки, цвета.'],
            'faq'      => ['title' => 'Частые вопросы: поведение и вид', 'sections' => ['faq', 'faq_look'], 'extra' => [],
                           'desc' => 'Открытый первый ответ, только один открытый, нумерация, отступы, вид, скругление, размер текста, цвета.'],
            'slider'   => ['title' => 'Слайдер', 'sections' => ['slider', 'slider_look'], 'extra' => [], 'desc' => 'Свои слайды, высота, автопрокрутка и интервал, стрелки и точки, положение и цвета подписи.'],
            'buttons'  => ['title' => 'Кнопки-ссылки', 'sections' => ['buttons', 'buttons_layout', 'buttons_look'], 'extra' => [],
                           'desc' => 'Свои плитки с иконками, размер иконок, заголовок, колонки, вид и цвета плиток.'],
            'cards'    => ['title' => 'Карточки', 'sections' => ['cards', 'cards_look'], 'extra' => [], 'desc' => 'Свой заголовок и карточки, колонки, вид, цвета, высота изображений.'],
            'notice'   => ['title' => 'Объявление', 'sections' => ['notice', 'notice_look'], 'extra' => [], 'desc' => 'Свой текст, изображение, цвет, ссылка и кнопка, даты показа, вид.'],
            'news'     => ['title' => 'Новости', 'sections' => ['news', 'news_look'], 'extra' => [], 'desc' => 'Своя рубрика, количество и вид, колонки, что показывать в карточках, цвета.'],
            'contacts' => ['title' => 'Контакты', 'sections' => ['contacts', 'contacts_look'], 'extra' => [], 'desc' => 'Свои адрес, телефон, e-mail, организация, логотип, кнопка, размер иконок, колонки и вид.'],
            'partners' => ['title' => 'Логотипы партнёров', 'sections' => ['partners', 'partners_look'], 'extra' => [],
                           'desc' => 'Свои логотипы со ссылками, сетка или лента, высота, чёрно-белые логотипы, карточки, подписи.'],
        ];
    }

    /**
     * Поля общих настроек в группе, в порядке страницы настроек.
     */
    public static function group_keys(string $group): array
    {
        static $cache = [];
        if (isset($cache[$group])) {
            return $cache[$group];
        }
        $groups = self::groups();
        if (!isset($groups[$group])) {
            return [];
        }
        // Поле, которое другая группа забрала себе в extra (размер значков документов — в «Оформлении страницы»), только там
        $claimed = [];
        foreach ($groups as $name => $def) {
            if ($name !== $group) {
                $claimed = array_merge($claimed, $def['extra']);
            }
        }
        $keys = [];
        foreach (WPD_Settings::field_map() as $key => $field) {
            if ((in_array($field['section'], $groups[$group]['sections'], true) || in_array($key, $groups[$group]['extra'], true))
                && !in_array($key, self::OV_EXCLUDE, true) && !in_array($key, $claimed, true)) {
                $keys[] = $key;
            }
        }
        $cache[$group] = $keys;
        return $keys;
    }

    /**
     * Общие настройки с переопределениями страницы (2.4.0).
     */
    public static function apply(array $global, int $post_id): array
    {
        return self::apply_data($global, self::get($post_id));
    }

    /** То же для уже прочитанных (или ещё не сохранённых — предпросмотр) настроек страницы. */
    public static function apply_data(array $global, array $p): array
    {
        $o = $global;
        foreach ((array) ($p['ov'] ?? []) as $vals) {
            foreach ((array) $vals as $key => $value) {
                if (array_key_exists($key, $o)) {
                    $o[$key] = $value;
                }
            }
        }
        return $o;
    }

    /** Настройки для вывода страницы: общие + её переопределения. */
    public static function options(int $post_id): array
    {
        return self::apply(WPD_Settings::get_global(), $post_id);
    }

    /**
     * Вид отдельных блоков: фон, отступы сверху и снизу, ширина, скрытие на телефоне или компьютере.
     * Сохраняются только блоки, у которых что-то задано.
     */
    public static function clean_bstyle(array $raw): array
    {
        $str = function ($v): string {
            return is_scalar($v) ? trim((string) $v) : '';
        };
        $px = function ($v) use ($str): string {
            $v = $str($v);
            return ($v === '' || !is_numeric($v)) ? '' : (string) max(0, min(200, (int) $v));
        };
        $out = [];
        foreach (array_keys(self::blocks()) as $key) {
            $b = is_array($raw[$key] ?? null) ? $raw[$key] : [];
            $width = $str($b['width'] ?? '');
            $hide  = $str($b['hide'] ?? '');
            $row = [
                'bg'    => $key === 'hero' ? '' : (string) sanitize_hex_color($str($b['bg'] ?? '')),
                'mt'    => $px($b['mt'] ?? ''),
                'mb'    => $px($b['mb'] ?? ''),
                'width' => isset(self::BLOCK_WIDTHS[$width]) ? $width : '',
                'hide'  => isset(self::BLOCK_HIDE[$hide]) ? $hide : '',
            ];
            if (implode('', $row) !== '') {
                $out[$key] = $row;
            }
        }
        return $out;
    }

    /**
     * Сохранённые настройки страницы (формат мета-поля) → формат формы панели. Нужен для копирования и импорта:
     * данные проходят ту же санитизацию, что и при сохранении панели.
     */
    public static function meta_to_input(array $meta): array
    {
        $m      = self::normalize($meta);
        $global = WPD_Settings::get_global();
        $input  = [
            'order'      => $m['order'],
            'show'       => [],
            'hero'       => $m['hero'],
            'stats_mode' => $m['stats_mode'],
            'docs_mode'  => $m['docs_mode'],
            'faq_mode'   => $m['faq_mode'],
            'docs_title' => $m['docs_title'],
            'faq_title'  => $m['faq_title'],
            'docs_nf'      => $m['docs_nf'],
            'docs_nf_auto' => $m['docs_nf_auto'],
            'stats'      => $m['stats'],
            'docs'       => $m['docs'],
            'faq'        => $m['faq'],
            'ovg'        => [],
            'ov'         => [],
            'bstyle'     => $m['bstyle'],
            'page_css'   => $m['page_css'],
        ];
        foreach ($m['order'] as $key) {
            if (!in_array($key, $m['hidden'], true)) {
                $input['show'][$key] = 1;
            }
        }
        foreach ($m['ov'] as $group => $vals) {
            $input['ovg'][$group] = 1;
            foreach (self::group_keys($group) as $key) {
                $input['ov'][$key] = array_key_exists($key, $vals) ? $vals[$key] : $global[$key];
            }
        }
        return $input;
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
            'partners'  => 'Логотипы партнёров',
        ];
    }

    /**
     * Компоненты 1.5.0. Пока страница не сохранила их видимость явно, они скрыты:
     * иначе после обновления плагина на уже оформленных страницах внезапно появились бы новые блоки.
     */
    const OPT_IN = ['slider', 'buttons', 'notice', 'cards', 'news', 'contacts', 'partners'];

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
            // 2.8.0: подпапка сетевой папки для своего списка документов и её автообновление
            'docs_nf'      => '',
            'docs_nf_auto' => 0,
            'faq_mode'   => 'global',
            'faq_title'  => '',
            'faq'        => [],
            // 2.4.0
            'ov'         => [],
            'bstyle'     => [],
            'page_css'   => '',
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
        foreach (['docs_title', 'faq_title', 'docs_nf'] as $key) {
            $out[$key] = isset($raw[$key]) && is_scalar($raw[$key]) ? (string) $raw[$key] : '';
        }
        $out['docs_nf_auto'] = empty($raw['docs_nf_auto']) ? 0 : 1;

        // 2.4.0: переопределения общих настроек по группам (только известные группы и поля), вид блоков, свой CSS
        $ov = is_array($raw['ov'] ?? null) ? $raw['ov'] : [];
        foreach (array_keys(self::groups()) as $group) {
            if (!is_array($ov[$group] ?? null)) {
                continue;
            }
            $vals = [];
            foreach (self::group_keys($group) as $key) {
                if (array_key_exists($key, $ov[$group])) {
                    $vals[$key] = $ov[$group][$key];
                }
            }
            $out['ov'][$group] = $vals;
        }
        $out['bstyle']   = self::clean_bstyle(is_array($raw['bstyle'] ?? null) ? $raw['bstyle'] : []);
        $out['page_css'] = isset($raw['page_css']) && is_scalar($raw['page_css']) ? (string) $raw['page_css'] : '';

        return $out;
    }

    /**
     * Санитизация данных формы панели (уже без слешей).
     * Формат: order[] — порядок, show[ключ] — видимость, hero[...], списки строк stats/docs/faq;
     * с 2.4.0 — ovg[группа] (флажок «Своё для этой страницы»), ov[поле] (значения полей групп), bstyle[блок][...], page_css.
     *
     * @param int  $post_id запись: нужна, чтобы понять, сменился ли стиль оформления страницы
     * @param bool $switch  подстраивать палитру и стиль главного блока под сменившийся стиль оформления
     */
    public static function sanitize($input, int $post_id = 0, bool $switch = true): array
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
        $out['docs_nf']      = WPD_Smb::clean_rel(sanitize_text_field($norm['docs_nf']));
        $out['docs_nf_auto'] = $norm['docs_nf_auto'];

        // 2.7.0: строки списков очищаются так же, как в общих настройках (с метками, цветами, скрытием и датами)
        foreach (array_keys(self::LISTS) as $list) {
            $out[$list] = WPD_Settings::sanitize_rows($norm[$list], $list);
        }

        // 2.4.0: группы общих настроек со своими значениями. Включённая группа хранит все свои поля.
        $ovg    = is_array($input['ovg'] ?? null) ? $input['ovg'] : [];
        $ov     = is_array($input['ov'] ?? null) ? $input['ov'] : [];
        $global = WPD_Settings::get_global();
        $map    = WPD_Settings::field_map();
        foreach (array_keys(self::groups()) as $group) {
            if (empty($ovg[$group])) {
                continue;
            }
            $vals = [];
            foreach (self::group_keys($group) as $key) {
                $type = $map[$key]['type'];
                if ($type === 'checkbox') {
                    $vals[$key] = empty($ov[$key]) ? 0 : 1;
                } elseif ($type === 'repeater') {
                    $vals[$key] = array_key_exists($key, $ov) ? WPD_Settings::sanitize_rows($ov[$key], $key) : $global[$key];
                } else {
                    $vals[$key] = array_key_exists($key, $ov) ? WPD_Settings::sanitize_value($key, $ov[$key]) : $global[$key];
                }
            }
            $out['ov'][$group] = $vals;
        }

        if (isset($out['ov']['page'])) {
            $pg = $out['ov']['page'];
            // Смена стиля оформления страницы — как в общих настройках (2.3.0): палитра и стиль главного блока
            // подстраиваются, только когда стиль действительно сменился.
            if ($switch) {
                $prev   = $post_id ? self::get($post_id) : self::defaults();
                $before = (string) ($prev['ov']['page']['design_style'] ?? $global['design_style']);
                $now    = (string) $pg['design_style'];
                $hero   = $out['hero']['style'] !== '' ? $out['hero']['style'] : (string) $global['hero_style'];
                if ($now === 'soft' && $before !== 'soft') {
                    $pg['palette']        = 'soft';
                    $out['hero']['style'] = 'soft';
                } elseif ($now !== 'soft' && $before === 'soft') {
                    if ($pg['palette'] === 'soft') {
                        $pg['palette'] = 'olympiad';
                    }
                    if ($hero === 'soft') {
                        $out['hero']['style'] = 'dark';
                    }
                }
            }
            // Готовая палитра задаёт цвета
            $palettes = WPD_Settings::palettes();
            if (isset($palettes[$pg['palette']])) {
                foreach (WPD_Settings::PALETTE_KEYS as $i => $color_key) {
                    $pg[$color_key] = $palettes[$pg['palette']]['colors'][$i];
                }
            }
            $out['ov']['page'] = $pg;
        }

        $out['bstyle']   = self::clean_bstyle(is_array($input['bstyle'] ?? null) ? $input['bstyle'] : []);
        $out['page_css'] = isset($input['page_css']) ? (string) WPD_Settings::sanitize_value('custom_css', $input['page_css']) : '';

        return $out;
    }

    /** Запись настроек страницы: значения по умолчанию не хранятся. */
    public static function store(int $post_id, array $clean): void
    {
        if ($clean === self::defaults()) {
            delete_post_meta($post_id, self::META);
        } else {
            // update_post_meta снимает слеши, поэтому данные передаются экранированными.
            update_post_meta($post_id, self::META, wp_slash($clean));
        }
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
        self::store($post_id, self::sanitize($raw, $post_id));
    }

    /* ---------------------------------------------------------------
     * REST: предпросмотр, копирование, сброс, экспорт и импорт (2.4.0)
     * ------------------------------------------------------------- */

    public static function register_meta(): void
    {
        foreach (WPD_Renderer::post_types() as $type) {
            register_post_meta($type, self::REV_META, [
                'type'          => 'string',
                'single'        => true,
                'show_in_rest'  => true,
                'auth_callback' => function ($allowed, $meta_key, $post_id) {
                    return current_user_can('edit_post', (int) $post_id);
                },
            ]);
        }
    }

    public static function register_rest(): void
    {
        register_rest_route('wpd/v1', '/preview', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'rest_preview'],
            'permission_callback' => function (WP_REST_Request $request) {
                return self::can_edit((int) $request->get_param('post_id'));
            },
            'args'                => [
                'post_id' => ['type' => 'integer', 'required' => true],
                'form'    => ['type' => 'string', 'required' => true],
            ],
        ]);
        $can = function (WP_REST_Request $request) {
            return self::can_edit((int) $request['id']);
        };
        register_rest_route('wpd/v1', '/page/(?P<id>\d+)', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_export'], 'permission_callback' => $can],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_import'], 'permission_callback' => $can],
            ['methods' => 'DELETE', 'callback' => [__CLASS__, 'rest_reset'], 'permission_callback' => $can],
        ]);
    }

    /** Запись подходящего типа, которую текущий пользователь может редактировать. */
    public static function can_edit(int $post_id): bool
    {
        $post = $post_id ? get_post($post_id) : null;
        return $post instanceof WP_Post
            && in_array($post->post_type, WPD_Renderer::post_types(), true)
            && current_user_can('edit_post', $post_id);
    }

    /**
     * Предпросмотр в блочном редакторе: CSS-переменные и классы корневого контейнера для ещё не сохранённой формы панели.
     */
    public static function rest_preview(WP_REST_Request $request): array
    {
        $post_id = (int) $request->get_param('post_id');
        $parsed  = [];
        parse_str((string) $request->get_param('form'), $parsed);
        $input = is_array($parsed['wpd_page'] ?? null) ? $parsed['wpd_page'] : [];
        $clean = self::sanitize($input, $post_id);
        $o     = self::apply_data(WPD_Settings::get_global(), $clean);

        return [
            'style'     => WPD_Settings::style_attr($o),
            'classes'   => array_values(array_merge(['wpd-page'], WPD_Settings::page_classes($o))),
            'vars'      => WPD_Settings::style_vars($o),
            'soft'      => $o['design_style'] === 'soft',
            'bg'        => (string) $o['bg'],
            'heroStyle' => $clean['hero']['style'] !== '' ? $clean['hero']['style'] : (string) $o['hero_style'],
        ];
    }

    /** Экспорт: сохранённые настройки страницы в JSON. */
    public static function rest_export(WP_REST_Request $request): array
    {
        $id = (int) $request['id'];
        return [
            'plugin'  => 'wp-olympiad-design',
            'version' => WPD_VERSION,
            'title'   => get_the_title($id),
            'enabled' => WPD_Renderer::is_design_enabled($id),
            'data'    => self::get($id),
        ];
    }

    /**
     * Импорт JSON (data) или копирование с другой записи (from). Данные проходят санитизацию панели.
     * Если в источнике оформление включено, оно включается и здесь (выключенное не выключает).
     *
     * @return array|WP_Error
     */
    public static function rest_import(WP_REST_Request $request)
    {
        $id      = (int) $request['id'];
        $from    = (int) $request->get_param('from');
        $enabled = false;

        if ($from) {
            if ($from === $id || !self::can_edit($from)) {
                return new WP_Error('wpd_from', 'Нет доступа к странице-источнику.', ['status' => 403]);
            }
            $meta    = self::get($from);
            $enabled = WPD_Renderer::is_design_enabled($from);
        } else {
            $data = $request->get_param('data');
            if (is_string($data)) {
                $data = json_decode($data, true);
            }
            if (!is_array($data)) {
                return new WP_Error('wpd_json', 'Не удалось прочитать данные: вставьте JSON, полученный кнопкой «Экспорт».', ['status' => 400]);
            }
            if (isset($data['data']) && is_array($data['data'])) {
                $enabled = !empty($data['enabled']);
                $data    = $data['data'];
            }
            $known = array_intersect(array_keys($data), array_keys(self::defaults()));
            if (!$known) {
                return new WP_Error('wpd_json', 'В JSON нет настроек страницы.', ['status' => 400]);
            }
            $meta = $data;
        }

        self::store($id, self::sanitize(self::meta_to_input($meta), $id, false));
        if ($enabled) {
            update_post_meta($id, WPD_Renderer::META_KEY, 1);
        }
        return ['ok' => true, 'enabled' => WPD_Renderer::is_design_enabled($id), 'data' => self::get($id)];
    }

    /** Сброс: все настройки страницы удаляются, действуют общие. Оформление страницы не выключается. */
    public static function rest_reset(WP_REST_Request $request): array
    {
        delete_post_meta((int) $request['id'], self::META);
        return ['ok' => true];
    }

    /**
     * Записи, с которых можно скопировать настройки: с сохранённой панелью или включённым оформлением.
     */
    public static function copy_sources(int $exclude): array
    {
        $ids = get_posts([
            'post_type'      => WPD_Renderer::post_types(),
            'post_status'    => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => 100,
            'fields'         => 'ids',
            'orderby'        => 'title',
            'order'          => 'ASC',
            'post__not_in'   => [$exclude],
            'no_found_rows'  => true,
            'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery
                'relation' => 'OR',
                ['key' => self::META, 'compare' => 'EXISTS'],
                ['key' => WPD_Renderer::META_KEY, 'value' => '1'],
            ],
        ]);
        $out = [];
        foreach ($ids as $id) {
            if (current_user_can('edit_post', (int) $id)) {
                $title = get_the_title((int) $id);
                $out[(int) $id] = $title !== '' ? $title : '(без заголовка) #' . $id;
            }
        }
        return $out;
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
        // 2.4.0: поля групп общих настроек в панели — те же, что на странице настроек (повторяющиеся поля, палитры, цвета)
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_style('wpd-settings', WPD_URL . 'assets/css/wpd-settings.css', ['wp-color-picker'], WPD_VERSION);
        wp_enqueue_script('wpd-settings', WPD_URL . 'assets/js/wpd-settings.js', ['jquery', 'wp-color-picker'], WPD_VERSION, true);
        wp_localize_script('wpd-settings', 'wpdSettings', [
            'chooseImage' => 'Выбор изображения',
            'use'         => 'Выбрать',
            'remove'      => 'Удалить?',
        ]);
        wp_enqueue_style('wpd-admin', WPD_URL . 'assets/css/wpd-admin.css', ['wpd-settings'], WPD_VERSION);
        wp_enqueue_script('wpd-admin', WPD_URL . 'assets/js/wpd-admin.js', ['jquery', 'jquery-ui-sortable', 'wp-api-fetch', 'wpd-settings'], WPD_VERSION, true);
        // 2.8.0: «Импортировать сейчас» из сетевой папки в своём списке документов
        if (class_exists('WPD_Netfolder') && WPD_Netfolder::enabled()) {
            wp_enqueue_script('wpd-netfolder', WPD_URL . 'assets/js/wpd-netfolder.js', ['wp-api-fetch', 'wpd-admin'], WPD_VERSION, true);
        }

        $o = WPD_Settings::get_global();
        $post_id = isset($_GET['post']) ? absint($_GET['post']) : (int) get_the_ID(); // phpcs:ignore WordPress.Security.NonceVerification
        wp_localize_script('wpd-admin', 'wpdPageEditor', [
            'postId' => $post_id,
            'global' => [
                'stats' => WPD_Settings::stats_items($o['stats']),
                'docs'  => WPD_Settings::docs_items($o['docs']),
                'faq'   => WPD_Settings::faq_items($o['faq']),
            ],
            'i18n'   => [
                'replace'     => 'Заменить текущие строки значениями из общих настроек?',
                'emptyGlobal' => 'В общих настройках этот список пуст.',
                'chooseImage' => 'Изображение главного блока',
                'chooseFile'  => 'Файл документа',
                'use'         => 'Выбрать',
                'remove'      => 'Удалить строку?',
                // 2.4.0
                'copyConfirm'   => 'Заменить все настройки этой страницы настройками выбранной? Несохранённые изменения пропадут, страница перезагрузится.',
                'resetConfirm'  => 'Удалить все настройки этой страницы (порядок блоков, своё содержимое, своё оформление)? Будут действовать общие настройки. Страница перезагрузится.',
                'importConfirm' => 'Заменить все настройки этой страницы импортированными? Несохранённые изменения пропадут, страница перезагрузится.',
                'chooseSource'  => 'Выберите страницу.',
                'emptyImport'   => 'Вставьте JSON, полученный кнопкой «Экспорт».',
                'failed'        => 'Не получилось',
                'copied'        => 'Скопировано',
            ],
        ]);
    }

    /**
     * Боковая колонка «Оформление олимпиады» в блочном редакторе (2.4.0). Управляет теми же полями панели,
     * поэтому сохраняется вместе с ней; изменения сразу видны в блоках «Олимпиада: …» на холсте.
     */
    public static function enqueue_sidebar(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->post_type, WPD_Renderer::post_types(), true)) {
            return;
        }
        $editor_dep = wp_script_is('wp-editor', 'registered') ? 'wp-editor' : 'wp-edit-post';
        wp_enqueue_script(
            'wpd-sidebar',
            WPD_URL . 'assets/js/wpd-sidebar.js',
            ['jquery', 'wp-plugins', 'wp-edit-post', $editor_dep, 'wp-element', 'wp-components', 'wp-data'],
            WPD_VERSION,
            true
        );
    }

    public static function meta_box_html(WP_Post $post): void
    {
        wp_nonce_field(self::ACTION, self::NONCE);

        $p        = self::get($post->ID);
        $global   = WPD_Settings::get_global();
        // Подсказки панели — с учётом своего оформления страницы (2.4.0)
        $o        = self::apply_data($global, $p);
        $blocks   = self::blocks();
        $enabled  = WPD_Renderer::is_design_enabled($post->ID);
        $settings = WPD_Settings::url();
        $has_blocks = strpos((string) $post->post_content, '<!-- wp:' . WPD_Blocks::NS . '/') !== false;

        $notes = [
            'hero'      => 'заголовок, текст, кнопка, изображение',
            'countdown' => isset($p['ov']['features'])
                ? (empty($o['countdown_enabled']) || $o['countdown_date'] === '' ? 'выключен для этой страницы' : 'свои даты для этой страницы')
                : (empty($o['countdown_enabled']) || $o['countdown_date'] === ''
                    ? 'выключен в общих настройках'
                    : ($o['countdown_end'] !== '' ? 'начало и окончание из общих настроек' : 'дата из общих настроек')),
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
            'partners'  => count(array_filter($o['partners'], function ($r) { return empty($r['hidden']); }))
                ? count(array_filter($o['partners'], function ($r) { return empty($r['hidden']); })) . ' лог. из общих настроек' : 'нет логотипов в общих настройках',
        ];


        foreach (['stats', 'docs', 'faq'] as $part) {
            if (isset($p['ov'][$part])) {
                $notes[$part] = 'свой вид для этой страницы';
            }
        }
        foreach (['slider', 'buttons', 'cards', 'notice', 'news', 'contacts', 'partners'] as $part) {
            if (isset($p['ov'][$part])) {
                $notes[$part] = 'своё для этой страницы';
            }
        }

        printf('<div class="wpd-pe" data-wpd-pe data-post-id="%d">', (int) $post->ID);

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
            $bs = $p['bstyle'][$key] ?? [];
            printf(
                '<li class="wpd-pe__block%5$s%6$s" data-key="%1$s">'
                . '<span class="wpd-pe__handle dashicons dashicons-menu" aria-hidden="true" title="Перетащить"></span>'
                . '<input type="hidden" name="wpd_page[order][]" value="%1$s">'
                . '<label><input type="checkbox" name="wpd_page[show][%1$s]" value="1"%2$s> <strong>%3$s</strong></label>'
                . '<span class="wpd-pe__note">%4$s</span>'
                . '<span class="wpd-pe__move">'
                . '<button type="button" class="button-link" data-wpd-move="-1" aria-label="Выше: %3$s"><span class="dashicons dashicons-arrow-up-alt2"></span></button>'
                . '<button type="button" class="button-link" data-wpd-move="1" aria-label="Ниже: %3$s"><span class="dashicons dashicons-arrow-down-alt2"></span></button>'
                . '<button type="button" class="button-link wpd-pe__bs-btn" data-wpd-bs-toggle aria-expanded="false" aria-label="Вид блока: %3$s" title="Фон, отступы, ширина, показ">'
                . '<span class="dashicons dashicons-admin-appearance"></span></button>'
                . '</span>%7$s</li>',
                esc_attr($key),
                checked($shown, true, false),
                esc_html($blocks[$key]),
                esc_html($notes[$key]),
                $shown ? '' : ' is-hidden',
                $bs ? ' has-bstyle' : '',
                self::bstyle_html($key, $bs) // phpcs:ignore WordPress.Security.EscapeOutput -- экранировано внутри
            );
        }
        echo '</ul>';

        self::hero_panel($post, $p['hero'], $o);
        // Ссылка «Изменить в настройках» ведёт сразу на нужную вкладку
        self::list_panel('stats', 'Статистика', $p, $o, WPD_Settings::url('stats'));
        self::list_panel('docs', 'Документы', $p, $o, WPD_Settings::url('docs'));
        self::list_panel('faq', 'Частые вопросы', $p, $o, WPD_Settings::url('faq'));

        // 2.4.0: своё оформление страницы, вид блоков и свой CSS, инструменты
        echo '<h3 class="wpd-pe__h">Своё оформление и параметры страницы</h3>'
            . '<p class="description">Включите «Своё для этой страницы» у группы, чтобы её значения на этой странице отличались от общих настроек. '
            . 'Поля заполнены текущими общими значениями. Выключенная группа берётся из общих настроек.</p>';
        foreach (self::groups() as $group => $def) {
            self::group_panel($group, $def, $p, $global);
        }
        self::css_panel($p);
        self::tools_panel($post);

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
        $styles    = ['' => 'Как в общих настройках'] + WPD_Settings::hero_styles();
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

    /**
     * Вид блока в списке порядка: фон, отступы, ширина, показ (2.4.0).
     */
    private static function bstyle_html(string $key, array $b): string
    {
        $b = array_merge(['bg' => '', 'mt' => '', 'mb' => '', 'width' => '', 'hide' => ''], $b);
        $name = function (string $f) use ($key): string {
            return esc_attr('wpd_page[bstyle][' . $key . '][' . $f . ']');
        };
        $id = function (string $f) use ($key): string {
            return esc_attr('wpd_pe_bs_' . $key . '_' . $f);
        };
        $select = function (string $f, array $options) use ($b, $name, $id): string {
            $html = '<select id="' . $id($f) . '" name="' . $name($f) . '">';
            foreach ($options as $value => $label) {
                $html .= '<option value="' . esc_attr($value) . '"' . selected($b[$f], $value, false) . '>' . esc_html($label) . '</option>';
            }
            return $html . '</select>';
        };

        $html = '<div class="wpd-pe__bs" data-wpd-bs hidden>';
        if ($key !== 'hero') {
            $html .= '<span class="wpd-pe__bs-f"><label for="' . $id('bg') . '">Фон блока</label>'
                . '<input type="text" id="' . $id('bg') . '" name="' . $name('bg') . '" value="' . esc_attr($b['bg']) . '" class="wpd-color-opt" placeholder="#rrggbb" maxlength="7"></span>';
        }
        $html .= '<span class="wpd-pe__bs-f"><label for="' . $id('mt') . '">Отступ сверху, px</label>'
            . '<input type="number" id="' . $id('mt') . '" name="' . $name('mt') . '" value="' . esc_attr($b['mt']) . '" min="0" max="200" class="small-text" placeholder="авто"></span>'
            . '<span class="wpd-pe__bs-f"><label for="' . $id('mb') . '">Отступ снизу, px</label>'
            . '<input type="number" id="' . $id('mb') . '" name="' . $name('mb') . '" value="' . esc_attr($b['mb']) . '" min="0" max="200" class="small-text" placeholder="авто"></span>'
            . '<span class="wpd-pe__bs-f"><label for="' . $id('width') . '">Ширина</label>' . $select('width', self::BLOCK_WIDTHS) . '</span>'
            . '<span class="wpd-pe__bs-f"><label for="' . $id('hide') . '">Показ</label>' . $select('hide', self::BLOCK_HIDE) . '</span>';
        if ($key === 'hero') {
            $html .= '<span class="wpd-pe__bs-note description">Цвет фона главного блока — в группе «Главный блок: кнопки, изображение, вид».</span>';
        }
        return $html . '</div>';
    }

    /**
     * Группа общих настроек со своими значениями для страницы (2.4.0).
     */
    private static function group_panel(string $group, array $def, array $p, array $global): void
    {
        $on       = isset($p['ov'][$group]);
        $vals     = $on ? $p['ov'][$group] : [];
        $sections = WPD_Settings::sections();
        $map      = WPD_Settings::field_map();
        $tab      = $group === 'page' ? 'design' : $group;

        printf(
            '<details class="wpd-pe__panel wpd-pe__ovg%1$s" data-wpd-ovg-panel="%2$s"%3$s><summary>%4$s<span class="wpd-pe__badge" data-wpd-ovg-badge%5$s>своё</span></summary><div class="wpd-pe__body">',
            $on ? ' is-on' : '',
            esc_attr($group),
            $on ? ' open' : '',
            esc_html($def['title']),
            $on ? '' : ' hidden'
        );
        printf(
            '<p class="wpd-pe__ovg-switch"><label><input type="checkbox" name="wpd_page[ovg][%1$s]" value="1" data-wpd-ovg="%1$s"%2$s> <strong>Своё для этой страницы</strong></label></p>'
            . '<p class="description">%3$s Выключено — значения из <a href="%4$s">общих настроек</a>.</p>',
            esc_attr($group),
            checked($on, true, false),
            esc_html($def['desc']),
            esc_url(WPD_Settings::url(isset(WPD_Settings::tabs()[$tab]) ? $tab : ''))
        );

        printf('<div class="wpd-pe__ovg-body" data-wpd-ovg-body="%s"%s>', esc_attr($group), $on ? '' : ' hidden');
        $current_section = '';
        $open_table      = false;
        foreach (self::group_keys($group) as $key) {
            $field = $map[$key];
            if ($field['section'] !== $current_section && count($def['sections']) + count($def['extra']) > 1) {
                if ($open_table) {
                    echo '</tbody></table>';
                    $open_table = false;
                }
                $current_section = $field['section'];
                echo '<h4 class="wpd-pe__sub">' . esc_html($sections[$current_section] ?? '') . '</h4>';
            }
            if (!$open_table) {
                echo '<table class="form-table wpd-pe__table wpd-pe__ovt" role="presentation"><tbody>';
                $open_table = true;
            }
            $value = array_key_exists($key, $vals) ? $vals[$key] : $global[$key];
            $wide  = in_array($field['type'], ['repeater', 'palette'], true);
            if ($wide) {
                echo '<tr class="wpd-pe__wide-row"><td colspan="2"><p class="wpd-pe__wide-label"><strong>' . esc_html($field['label']) . '</strong></p>';
            } else {
                printf('<tr><th scope="row"><label for="%s">%s</label></th><td>', esc_attr('wpd_pe_ov_' . $key), esc_html($field['label']));
            }
            WPD_Settings::render_field_as($key, $value, 'wpd_page[ov]', 'wpd_pe_ov_');
            echo '</td></tr>';
        }
        if ($open_table) {
            echo '</tbody></table>';
        }
        echo '</div></div></details>';
    }

    /** Свой CSS страницы и пояснение про вид блоков (2.4.0). */
    private static function css_panel(array $p): void
    {
        $open = $p['page_css'] !== '' || $p['bstyle'];
        printf('<details class="wpd-pe__panel"%s><summary>Вид блоков и свой CSS</summary><div class="wpd-pe__body">', $open ? ' open' : '');
        echo '<p class="description">Фон, отступы, ширина и показ на телефоне или компьютере задаются у каждого блока кнопкой '
            . '<span class="dashicons dashicons-admin-appearance" aria-hidden="true"></span> в списке «Блоки и порядок».</p>';
        printf(
            '<p><label for="wpd_pe_page_css"><strong>Свой CSS для этой страницы</strong></label><br>'
            . '<textarea id="wpd_pe_page_css" name="wpd_page[page_css]" rows="6" class="large-text code" spellcheck="false" placeholder="%s">%s</textarea></p>'
            . '<p class="description">Подключается только на этой странице, после общего «Своего CSS». Корневой контейнер блоков — <code>.wpd-page</code>.</p>',
            esc_attr('.wpd-page .wpd-hero__title { letter-spacing: .02em; }'),
            esc_textarea($p['page_css'])
        );
        echo '</div></details>';
    }

    /** Копирование с другой страницы, сброс, экспорт и импорт (2.4.0). */
    private static function tools_panel(WP_Post $post): void
    {
        $sources = self::copy_sources((int) $post->ID);
        echo '<details class="wpd-pe__panel wpd-pe__tools" data-wpd-tools><summary>Инструменты: копирование, сброс, экспорт и импорт</summary><div class="wpd-pe__body">';

        echo '<p><label for="wpd_pe_copy_from"><strong>Скопировать настройки с другой страницы</strong></label><br>';
        echo '<select id="wpd_pe_copy_from" data-wpd-copy-from><option value="">— выберите страницу —</option>';
        foreach ($sources as $id => $title) {
            printf('<option value="%d">%s</option>', (int) $id, esc_html($title));
        }
        echo '</select> <button type="button" class="button" data-wpd-copy-page' . ($sources ? '' : ' disabled') . '>Скопировать</button></p>';
        echo '<p class="description">' . ($sources
            ? 'Порядок блоков, своё содержимое, своё оформление, вид блоков и CSS заменят текущие настройки этой страницы.'
            : 'Других страниц с настройками олимпиады пока нет.') . '</p>';

        echo '<p><strong>Сбросить</strong><br><button type="button" class="button button-link-delete" data-wpd-reset-page>Сбросить к общим настройкам</button></p>'
            . '<p class="description">Удаляет все настройки панели для этой страницы. Включённое оформление остаётся включённым.</p>';

        echo '<p><strong>Экспорт</strong><br><button type="button" class="button" data-wpd-export>Получить JSON</button> '
            . '<button type="button" class="button" data-wpd-export-copy hidden>Копировать</button> '
            . '<button type="button" class="button" data-wpd-export-download hidden>Скачать файл</button></p>'
            . '<textarea class="large-text code" rows="4" readonly data-wpd-export-out hidden aria-label="JSON настроек страницы"></textarea>'
            . '<p class="description">Экспортируются сохранённые настройки — сначала сохраните запись.</p>';

        echo '<p><label for="wpd_pe_import"><strong>Импорт</strong></label><br>'
            . '<textarea id="wpd_pe_import" class="large-text code" rows="4" data-wpd-import-in placeholder="{&quot;plugin&quot;:&quot;wp-olympiad-design&quot;, …}"></textarea></p>'
            . '<p><button type="button" class="button" data-wpd-import>Импортировать</button> <span class="wpd-pe__tools-msg" data-wpd-tools-msg role="status"></span></p>';
        echo '</div></details>';
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
                $global_count = count(WPD_Settings::faq_items($o['faq']));
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
            self::netfolder_box($p);
        }
        echo '</div></div></details>';
    }

    /** 2.8.0: импорт своего списка документов из сетевой папки. */
    private static function netfolder_box(array $p): void
    {
        if (!class_exists('WPD_Netfolder') || !WPD_Netfolder::enabled()) {
            return;
        }
        $nf = WPD_Netfolder::editor_data();
        echo '<div class="wpd-pe__nf" data-wpd-nf-page>';
        echo '<p class="wpd-pe__nf-title"><strong>Сетевая папка</strong></p>';
        printf('<p><label>Подпапка<br><input type="text" name="wpd_page[docs_nf]" value="%1$s" class="regular-text" placeholder="Пусто — корень папки" data-wpd-nf-sub></label><br>'
            . '<span class="description">Относительно %2$s, например <code>Итоги 2026</code>.</span></p>',
            esc_attr((string) $p['docs_nf']), esc_html($nf['root'] !== '' ? $nf['root'] : 'адреса из настроек'));
        printf('<p><label><input type="checkbox" name="wpd_page[docs_nf_auto]" value="1"%1$s data-wpd-nf-auto> Обновлять по расписанию</label>%2$s</p>',
            checked(!empty($p['docs_nf_auto']), true, false),
            $nf['auto'] ? ' <span class="description">(' . esc_html($nf['schedule']) . ')</span>' : ' <span class="description">— расписание выключено в настройках</span>');
        echo '<p><button type="button" class="button" data-wpd-nf-import>Импортировать сейчас</button> <span class="spinner" data-wpd-nf-spin></span></p>';
        echo '<p class="description" data-wpd-nf-msg aria-live="polite"></p></div>';
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

        // 2.7.0: дополнительные поля строки — под спойлером «Ещё»
        $check = function (string $key, string $label) use ($name, $val): string {
            return '<label class="wpd-pe__check"><input type="checkbox" name="' . $name($key) . '" value="1" data-wpd-field="' . esc_attr($key) . '"'
                . checked($val($key), '1', false) . '> ' . esc_html($label) . '</label>';
        };
        $text = function (string $key, string $placeholder, string $type = 'text') use ($name, $val): string {
            return '<input type="' . $type . '" name="' . $name($key) . '" value="' . esc_attr($type === 'datetime-local' ? str_replace(' ', 'T', $val($key)) : $val($key))
                . '" placeholder="' . esc_attr($placeholder) . '" aria-label="' . esc_attr($placeholder) . '" data-wpd-field="' . esc_attr($key) . '"'
                . ($type === 'text' ? ' class="wpd-pe__wide"' : '') . '>';
        };
        $color = function (string $key, string $label) use ($name, $val): string {
            return '<label class="wpd-pe__color">' . esc_html($label) . ' <input type="text" name="' . $name($key) . '" value="' . esc_attr($val($key))
                . '" class="wpd-pe__hex" placeholder="#rrggbb" maxlength="7" data-wpd-field="' . esc_attr($key) . '"></label>';
        };
        $icon = function (string $key) use ($name, $val): string {
            $opts = '<option value="">— без иконки —</option>';
            foreach (WPD_Settings::icons() as $k => $label) {
                $opts .= '<option value="' . esc_attr($k) . '"' . selected($val($key), $k, false) . '>' . esc_html($label) . '</option>';
            }
            return '<label class="wpd-pe__icon">Иконка <select name="' . $name($key) . '" data-wpd-field="' . esc_attr($key) . '">' . $opts . '</select></label>';
        };
        switch ($list) {
            case 'stats':
                $more = $icon('icon') . $color('color', 'Цвет карточки');
                break;
            case 'docs':
                $more = $text('desc', 'Подпись под названием') . $text('badge', 'Метка, например «Новое»') . $text('type', 'Текст значка (пусто — PDF/WEB)')
                    . $color('color', 'Цвет значка') . $check('new_tab', 'в новой вкладке') . $check('download', 'скачивать файл');
                break;
            default:
                $more = $check('open', 'ответ открыт сразу');
        }
        $more .= $check('hidden', 'скрыть')
            . '<span class="wpd-pe__dates">показывать с ' . $text('from', 'Показывать с', 'datetime-local')
            . ' до ' . $text('to', 'Показывать до', 'datetime-local') . '</span>';
        $flagged = $val('hidden') === '1' || $val('from') !== '' || $val('to') !== '';
        if ($list === 'docs') {
            // 2.8.0: строка из сетевой папки — путь к файлу и отметка «нет в папке»
            $fields .= '<input type="hidden" name="' . $name('src') . '" value="' . esc_attr($val('src')) . '" data-wpd-field="src">'
                . '<input type="hidden" name="' . $name('nf') . '" value="' . esc_attr($val('nf')) . '" data-wpd-field="nf">';
            if ($val('src') !== '') {
                $more = '<span class="wpd-pe__src">Из сетевой папки: ' . esc_html($val('src')) . ($val('nf') === '1' ? ' — <strong>нет в папке</strong>' : '') . '</span>' . $more;
            }
        }

        return '<div class="wpd-pe__row' . ($flagged ? ' is-flagged' : '') . '">' . $fields . $tools
            . '<details class="wpd-pe__more"><summary>Ещё</summary><div class="wpd-pe__more-body">' . $more . '</div></details></div>';
    }
}
