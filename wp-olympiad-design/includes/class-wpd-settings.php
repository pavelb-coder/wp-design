<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Настройки плагина: описание полей, сохранение через Settings API и парсеры списков.
 */
class WPD_Settings
{
    const OPTION = 'wpd_settings';
    const GROUP  = 'wpd_settings_group';
    const PAGE   = 'wpd-settings';

    /** Иконка пункта меню «Олимпиада». */
    const MENU_ICON = 'dashicons-awards';

    /** Кеш настроек на время запроса. Сбрасывается при изменении опции. */
    private static $cache = null;

    public static function init(): void
    {
        add_action('admin_menu', [__CLASS__, 'add_menu']);
        add_action('admin_init', [__CLASS__, 'register']);
        add_action('admin_init', [__CLASS__, 'redirect_old_url'], 1);
        add_filter('plugin_action_links_' . plugin_basename(WPD_DIR . 'wp-olympiad-design.php'), [__CLASS__, 'action_links']);

        // Сброс кеша при любом изменении опции в этом же запросе.
        add_action('add_option_' . self::OPTION, [__CLASS__, 'flush_cache']);
        add_action('update_option_' . self::OPTION, [__CLASS__, 'flush_cache']);
        add_action('delete_option_' . self::OPTION, [__CLASS__, 'flush_cache']);
    }

    public static function flush_cache(): void
    {
        self::$cache = null;
    }

    /**
     * Секции админки.
     */
    public static function sections(): array
    {
        return [
            'colors'   => 'Цвета и шрифт',
            'hero'     => 'Главный блок',
            'features' => 'Возможности',
            'stats'    => 'Статистика',
            'docs'     => 'Документы',
            'faq'      => 'Частые вопросы',
            'behavior' => 'Поведение',
        ];
    }

    /**
     * Вкладки страницы настроек и секции, которые на них выводятся. Вкладка без секций — справка.
     */
    public static function tabs(): array
    {
        return [
            'design'   => ['title' => 'Оформление', 'sections' => ['colors', 'behavior']],
            'hero'     => ['title' => 'Главный блок', 'sections' => ['hero']],
            'features' => ['title' => 'Таймер и эффекты', 'sections' => ['features']],
            'stats'    => ['title' => 'Статистика', 'sections' => ['stats']],
            'docs'     => ['title' => 'Документы', 'sections' => ['docs']],
            'faq'      => ['title' => 'Частые вопросы', 'sections' => ['faq']],
            'help'     => ['title' => 'Справка', 'sections' => []],
        ];
    }

    /**
     * Адрес страницы настроек (или её вкладки).
     */
    public static function url(string $tab = ''): string
    {
        $url = admin_url('admin.php?page=' . self::PAGE);
        if ($tab !== '' && isset(self::tabs()[$tab])) {
            $url .= '&tab=' . $tab;
        }
        return $url;
    }

    /**
     * Текущая вкладка из адреса. Неизвестная — первая.
     */
    public static function current_tab(): string
    {
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        return isset(self::tabs()[$tab]) ? $tab : (string) array_key_first(self::tabs());
    }

    /**
     * Описание всех полей. Это единственный источник правды для дефолтов, санитизации и формы.
     */
    public static function field_map(): array
    {
        return [
            // Цвета и шрифт
            'primary'  => ['label' => 'Основной цвет', 'type' => 'color', 'section' => 'colors', 'default' => '#1d4ed8',
                           'desc' => 'Рамки карточек, иконки документов, открытые вопросы.'],
            'accent'   => ['label' => 'Акцентный цвет', 'type' => 'color', 'section' => 'colors', 'default' => '#f97316',
                           'desc' => 'Цифры статистики, кнопка и метка в главном блоке.'],
            'dark'     => ['label' => 'Тёмный фон главного блока', 'type' => 'color', 'section' => 'colors', 'default' => '#0b1220'],
            'text'     => ['label' => 'Цвет текста', 'type' => 'color', 'section' => 'colors', 'default' => '#1f2937'],
            'bg'       => ['label' => 'Фон страницы', 'type' => 'color', 'section' => 'colors', 'default' => '#f1f5f9'],
            'radius'   => ['label' => 'Скругление углов, px', 'type' => 'number', 'section' => 'colors', 'default' => 16,
                           'min' => 0, 'max' => 40],
            'font'     => ['label' => 'Шрифт (CSS font-family)', 'type' => 'font', 'section' => 'colors',
                           'default' => '-apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
                           'desc' => 'Например: Montserrat, Arial, sans-serif. Если шрифт нужен из Google Fonts, подключите его в теме.'],

            // Главный блок
            'badge'            => ['label' => 'Метка над заголовком', 'type' => 'text', 'section' => 'hero', 'default' => 'Олимпиада 2026'],
            'hero_title'       => ['label' => 'Заголовок (для шорткода [wpd_hero])', 'type' => 'text', 'section' => 'hero',
                                   'default' => 'Всероссийская олимпиада по техническому английскому'],
            'hero_text'        => ['label' => 'Текст под заголовком', 'type' => 'textarea', 'section' => 'hero',
                                   'default' => 'Краткое описание мероприятия: кто может участвовать, как проходит и где узнать результаты.'],
            'hero_button_text' => ['label' => 'Текст кнопки', 'type' => 'text', 'section' => 'hero', 'default' => 'Положение'],
            'hero_button_url'  => ['label' => 'Ссылка кнопки', 'type' => 'url', 'section' => 'hero', 'default' => ''],
            'hero_image_url'   => ['label' => 'Изображение главного блока (URL)', 'type' => 'url', 'section' => 'hero', 'default' => '',
                                   'desc' => 'Если у страницы есть «Запись изображение», используется оно.'],

            'hero_style' => ['label' => 'Стиль главного блока', 'type' => 'select', 'section' => 'hero', 'default' => 'dark',
                             'options' => ['dark' => 'Тёмный', 'gradient' => 'Градиент основного цвета', 'light' => 'Светлый'],
                             'desc' => 'Светлый вариант подходит для страниц, где много текста и мало иллюстраций.'],

            // Возможности
            'countdown_enabled' => ['label' => 'Обратный отсчёт', 'type' => 'checkbox', 'section' => 'features', 'default' => 0,
                                    'desc' => 'Показывает таймер до даты события под главным блоком и через шорткод [wpd_countdown].'],
            'countdown_date'    => ['label' => 'Дата и время события', 'type' => 'datetime', 'section' => 'features', 'default' => '',
                                    'desc' => 'Часовой пояс — из настроек сайта (Настройки → Общие).'],
            'countdown_label'   => ['label' => 'Подпись к таймеру', 'type' => 'text', 'section' => 'features', 'default' => 'До начала олимпиады'],
            'countdown_done'    => ['label' => 'Текст после окончания отсчёта', 'type' => 'text', 'section' => 'features', 'default' => 'Олимпиада уже прошла'],
            'faq_search'        => ['label' => 'Поиск по вопросам FAQ', 'type' => 'checkbox', 'section' => 'features', 'default' => 1,
                                    'desc' => 'Поле поиска появляется, когда вопросов четыре и больше.'],
            'stats_animate'     => ['label' => 'Анимация чисел статистики', 'type' => 'checkbox', 'section' => 'features', 'default' => 1,
                                    'desc' => 'Числа вида 300 или 300+ плавно растут при появлении в экране. Отключается для пользователей с настройкой «уменьшить движение».'],

            // Статистика
            'stats' => ['label' => 'Карточки статистики', 'type' => 'textarea', 'section' => 'stats',
                        'default' => "12–18|возраст участников\n300+|школьников в прошлом году\n20|регионов России\n2|этапа: онлайн и очный финал",
                        'desc' => 'Каждая строка: число|подпись. Пустое поле — блок не выводится.'],

            // Документы
            'docs_title' => ['label' => 'Заголовок блока документов', 'type' => 'text', 'section' => 'docs', 'default' => 'Документы'],
            'docs'       => ['label' => 'Документы', 'type' => 'textarea', 'section' => 'docs', 'default' => '',
                             'desc' => 'Каждая строка: название|ссылка (PDF или страница). Пустое поле — блок не выводится.'],

            // FAQ
            'faq_title' => ['label' => 'Заголовок блока FAQ', 'type' => 'text', 'section' => 'faq', 'default' => 'Часто задаваемые вопросы'],
            'faq'       => ['label' => 'Вопросы и ответы', 'type' => 'textarea', 'section' => 'faq',
                            'default' => "Как проходит олимпиада?\nВ два этапа: онлайн-отбор и очный финал. Лучшие участники отбора приглашаются на финал.\n\nУчастие бесплатное?\nДа, участие в отборочном этапе бесплатное.",
                            'desc' => 'Первая строка блока — вопрос, остальные строки — ответ. Блоки разделяются пустой строкой. Разрешены теги a, strong, em, br.'],

            // Поведение
            'hide_title' => ['label' => 'Скрывать заголовок темы', 'type' => 'checkbox', 'section' => 'behavior', 'default' => 1,
                             'desc' => 'Для страниц с включённым оформлением прячет стандартный заголовок темы: его заменяет главный блок.'],
        ];
    }

    /**
     * Дефолтные значения (для обратной совместимости и для get()).
     */
    public static function defaults(): array
    {
        $out = [];
        foreach (self::field_map() as $key => $field) {
            $out[$key] = $field['default'];
        }
        return $out;
    }

    /**
     * Текущие настройки с подстановкой дефолтов.
     */
    public static function get(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $saved = get_option(self::OPTION, []);
        $saved = is_array($saved) ? $saved : [];
        $out = [];
        foreach (self::field_map() as $key => $field) {
            $out[$key] = array_key_exists($key, $saved) ? $saved[$key] : $field['default'];
        }

        self::$cache = $out;
        return $out;
    }

    /**
     * Санитизация при сохранении. Вызывается Settings API.
     */
    public static function sanitize($input): array
    {
        $input = is_array($input) ? $input : [];
        $out = [];

        // Форма отправляет поля только одной вкладки (_tab). Поля других вкладок сохраняют текущие значения,
        // иначе сохранение «Статистики» сбросило бы цвета и снятые флажки других вкладок.
        $tab      = isset($input['_tab']) && is_string($input['_tab']) ? $input['_tab'] : '';
        $sections = isset(self::tabs()[$tab]) ? self::tabs()[$tab]['sections'] : null;
        $current  = $sections !== null ? self::get() : [];

        foreach (self::field_map() as $key => $field) {
            if ($sections !== null && !in_array($field['section'], $sections, true)) {
                $out[$key] = $current[$key];
                continue;
            }

            $has = array_key_exists($key, $input);
            $raw = $has ? $input[$key] : null;

            // Чекбокс не отправляется, когда снят — это и есть значение 0.
            if ($field['type'] === 'checkbox') {
                $out[$key] = empty($raw) ? 0 : 1;
                continue;
            }
            if (!$has) {
                $out[$key] = $field['default'];
                continue;
            }

            $raw = is_scalar($raw) ? (string) $raw : '';

            switch ($field['type']) {
                case 'color':
                    $value = sanitize_hex_color($raw);
                    $out[$key] = $value ? $value : $field['default'];
                    break;

                case 'number':
                    $out[$key] = min($field['max'], max($field['min'], (int) $raw));
                    break;

                case 'url':
                    $out[$key] = esc_url_raw(trim($raw));
                    break;

                case 'select':
                    $out[$key] = array_key_exists($raw, $field['options']) ? $raw : $field['default'];
                    break;

                case 'datetime':
                    $out[$key] = self::normalize_datetime($raw);
                    break;

                case 'font':
                    $value = trim((string) preg_replace('/[^A-Za-z0-9 ,\'"\-]/', '', $raw));
                    $out[$key] = $value !== '' ? $value : $field['default'];
                    break;

                case 'textarea':
                    $out[$key] = ($key === 'faq')
                        ? wp_kses($raw, self::allowed_html())
                        : sanitize_textarea_field($raw);
                    break;

                default:
                    $out[$key] = sanitize_text_field($raw);
            }
        }

        return $out;
    }

    /**
     * Приводит дату из поля datetime-local к виду «ГГГГ-ММ-ДД ЧЧ:ММ». Некорректное значение даёт пустую строку.
     */
    public static function normalize_datetime(string $raw): string
    {
        $raw = trim($raw);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})$/', $raw, $m)) {
            return '';
        }
        [, $y, $mo, $d, $h, $i] = $m;
        if (!checkdate((int) $mo, (int) $d, (int) $y) || (int) $h > 23 || (int) $i > 59) {
            return '';
        }
        return sprintf('%s-%s-%s %s:%s', $y, $mo, $d, $h, $i);
    }

    public static function allowed_html(): array
    {
        return [
            'a'      => ['href' => [], 'target' => [], 'rel' => []],
            'strong' => [],
            'em'     => [],
            'br'     => [],
        ];
    }

    /**
     * Разбор строк вида «левое|правое».
     */
    public static function parse_pairs(string $text): array
    {
        $items = [];
        foreach (preg_split('/\R/u', $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts  = array_map('trim', explode('|', $line, 2));
            $items[] = [
                'left'  => $parts[0],
                'right' => isset($parts[1]) ? $parts[1] : '',
            ];
        }
        return $items;
    }

    /**
     * Карточки статистики из текстового поля: [['num' => ..., 'label' => ...]].
     */
    public static function stats_items(string $text): array
    {
        return array_map(function ($p) {
            return ['num' => $p['left'], 'label' => $p['right']];
        }, self::parse_pairs($text));
    }

    /**
     * Документы из текстового поля: [['title' => ..., 'url' => ...]].
     */
    public static function docs_items(string $text): array
    {
        return array_map(function ($p) {
            return ['title' => $p['left'], 'url' => $p['right']];
        }, self::parse_pairs($text));
    }

    /**
     * Разбор FAQ: блоки разделены пустой строкой, в блоке первая строка — вопрос.
     */
    public static function parse_faq(string $text): array
    {
        $items = [];
        foreach (preg_split('/\R\s*\R/u', trim($text)) as $block) {
            $lines    = preg_split('/\R/u', trim($block));
            $question = trim((string) array_shift($lines));
            if ($question === '') {
                continue;
            }
            $items[] = [
                'q' => $question,
                'a' => trim(implode("\n", $lines)),
            ];
        }
        return $items;
    }

    /* ---------------------------------------------------------------
     * Админка
     * ------------------------------------------------------------- */

    public static function add_menu(): void
    {
        add_menu_page(
            'Олимпиада — настройки',
            'Олимпиада',
            'manage_options',
            self::PAGE,
            [__CLASS__, 'render_page'],
            self::MENU_ICON,
            61
        );

        // Подпункты: по одному на вкладку, первый совпадает с пунктом меню
        foreach (self::tabs() as $id => $tab) {
            $is_first = $id === array_key_first(self::tabs());
            add_submenu_page(
                self::PAGE,
                'Олимпиада — ' . $tab['title'],
                $tab['title'],
                'manage_options',
                $is_first ? self::PAGE : self::PAGE . '&tab=' . $id,
                // Обработчик нужен и у подпунктов вкладок: без него WordPress строит относительную ссылку
                // «wpd-settings&tab=…» вместо «admin.php?page=wpd-settings&tab=…». Открывается всё равно render_page пункта меню.
                [__CLASS__, 'render_page']
            );
        }
    }

    /**
     * Подсветка активного подпункта меню для текущей вкладки.
     */
    public static function submenu_file($submenu_file)
    {
        // phpcs:ignore WordPress.Security.NonceVerification
        if (isset($_GET['page']) && $_GET['page'] === self::PAGE) {
            $tab = self::current_tab();
            return $tab === array_key_first(self::tabs()) ? self::PAGE : self::PAGE . '&tab=' . $tab;
        }
        return $submenu_file;
    }

    /**
     * Старый адрес «Настройки → Дизайн олимпиады» (до 1.4.0) ведёт на новую страницу.
     */
    public static function redirect_old_url(): void
    {
        global $pagenow;
        // phpcs:ignore WordPress.Security.NonceVerification
        if ($pagenow === 'options-general.php' && isset($_GET['page']) && $_GET['page'] === self::PAGE) {
            wp_safe_redirect(self::url());
            exit;
        }
    }

    public static function action_links(array $links): array
    {
        array_unshift($links, '<a href="' . esc_url(self::url()) . '">Настройки</a>');
        return $links;
    }

    public static function register(): void
    {
        register_setting(self::GROUP, self::OPTION, [
            'type'              => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize'],
            'default'           => self::defaults(),
        ]);

        add_filter('submenu_file', [__CLASS__, 'submenu_file']);

        foreach (self::sections() as $id => $title) {
            add_settings_section('wpd_' . $id, $title, '__return_false', self::PAGE);
        }

        foreach (self::field_map() as $key => $field) {
            add_settings_field(
                'wpd_' . $key,
                $field['label'],
                [__CLASS__, 'render_field'],
                self::PAGE,
                'wpd_' . $field['section'],
                ['key' => $key, 'label_for' => 'wpd_' . $key]
            );
        }
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $tabs    = self::tabs();
        $current = self::current_tab();
        ?>
        <div class="wrap wpd-settings">
            <h1>Олимпиада</h1>
            <?php settings_errors(); ?>

            <nav class="nav-tab-wrapper wp-clearfix" aria-label="Разделы настроек">
                <?php foreach ($tabs as $id => $tab) : ?>
                    <a href="<?php echo esc_url(self::url($id)); ?>"
                       class="nav-tab<?php echo $id === $current ? ' nav-tab-active' : ''; ?>"
                       <?php echo $id === $current ? 'aria-current="page"' : ''; ?>><?php echo esc_html($tab['title']); ?></a>
                <?php endforeach; ?>
            </nav>

            <?php if ($tabs[$current]['sections']) : ?>
                <p class="description" style="margin-top:12px">
                    Здесь задаются <strong>общие</strong> значения: их используют все страницы, пока для страницы не задано своё
                    (панель «Блоки олимпиады» в редакторе страницы или блоки «Олимпиада: …»).
                    <a href="<?php echo esc_url(self::url('help')); ?>">Подробнее</a>.
                </p>
                <form method="post" action="options.php">
                    <?php
                    settings_fields(self::GROUP);
                    printf('<input type="hidden" name="%s[_tab]" value="%s">', esc_attr(self::OPTION), esc_attr($current));
                    self::render_sections($tabs[$current]['sections']);
                    submit_button('Сохранить');
                    ?>
                </form>
            <?php else : ?>
                <?php self::render_help(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Вывод только секций текущей вкладки (do_settings_sections выводит все секции страницы).
     */
    private static function render_sections(array $sections): void
    {
        $all = self::sections();
        foreach ($sections as $id) {
            if (count($sections) > 1) {
                echo '<h2>' . esc_html($all[$id]) . '</h2>';
            } else {
                // Заголовок секции нужен и при одной секции — для доступности и узнаваемости
                echo '<h2 class="screen-reader-text">' . esc_html($all[$id]) . '</h2>';
            }
            echo '<table class="form-table" role="presentation">';
            do_settings_fields(self::PAGE, 'wpd_' . $id);
            echo '</table>';
        }
    }

    private static function render_help(): void
    {
        ?>
        <div class="wpd-help" style="max-width:860px">
            <h2>Где редактируются страницы и блоки</h2>
            <ol>
                <li><strong>Общие настройки</strong> — вкладки на этой странице: цвета, шрифт и содержимое блоков по умолчанию.
                    Изменения сразу применяются ко всем страницам, где для блока не задано своё содержимое.</li>
                <li><strong>Панель «Блоки олимпиады»</strong> в редакторе страницы (блочный и классический редактор).
                    Включите «Оформление в стиле олимпиады» в боковой панели, затем меняйте порядок блоков, скрывайте ненужные
                    и задавайте для страницы свой главный блок, статистику, документы и FAQ.
                    В блочном редакторе панель находится в области <em>Meta Boxes</em> внизу экрана.</li>
                <li><strong>Блоки редактора</strong> в категории «Олимпиада»: главный блок, обратный отсчёт, статистика, документы, FAQ.
                    Текст правится прямо на странице. Готовый шаблон — «Паттерны → Олимпиада → Страница олимпиады».</li>
            </ol>

            <h2>Шорткоды</h2>
            <p>Выводят блоки с общим содержимым — для виджетов, шаблонов и классического редактора.</p>
            <table class="widefat striped" style="max-width:640px">
                <tbody>
                    <tr><td><code>[wpd_page]</code></td><td>все блоки сразу: главный блок, таймер, статистика, документы, FAQ</td></tr>
                    <tr><td><code>[wpd_hero]</code></td><td>главный блок</td></tr>
                    <tr><td><code>[wpd_countdown]</code></td><td>обратный отсчёт (если включён и задана дата)</td></tr>
                    <tr><td><code>[wpd_stats]</code></td><td>статистика</td></tr>
                    <tr><td><code>[wpd_docs]</code></td><td>документы</td></tr>
                    <tr><td><code>[wpd_faq]</code></td><td>частые вопросы</td></tr>
                </tbody>
            </table>

            <h2>Форматы списков</h2>
            <ul style="list-style:disc;margin-left:20px">
                <li><strong>Статистика:</strong> одна карточка на строку, <code>число|подпись</code>.</li>
                <li><strong>Документы:</strong> одна ссылка на строку, <code>название|ссылка</code>.</li>
                <li><strong>FAQ:</strong> блоки через пустую строку; первая строка — вопрос, остальные — ответ. Разрешены теги a, strong, em, br.</li>
            </ul>
        </div>
        <?php
    }

    public static function render_field(array $args): void
    {
        $key    = $args['key'];
        $field  = self::field_map()[$key];
        $value  = self::get()[$key];
        $id     = 'wpd_' . $key;
        $name   = self::OPTION . '[' . $key . ']';

        switch ($field['type']) {
            case 'color':
                printf('<input type="color" id="%1$s" name="%2$s" value="%3$s">',
                    esc_attr($id), esc_attr($name), esc_attr($value));
                break;

            case 'number':
                printf('<input type="number" id="%1$s" name="%2$s" value="%3$s" min="%4$d" max="%5$d" class="small-text">',
                    esc_attr($id), esc_attr($name), esc_attr((string) $value), (int) $field['min'], (int) $field['max']);
                break;

            case 'checkbox':
                printf('<label><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s> Включено</label>',
                    esc_attr($id), esc_attr($name), checked((int) $value, 1, false));
                break;

            case 'textarea':
                $rows = $key === 'faq' ? 10 : 5;
                printf('<textarea id="%1$s" name="%2$s" rows="%3$d" class="large-text code">%4$s</textarea>',
                    esc_attr($id), esc_attr($name), $rows, esc_textarea($value));
                break;

            case 'url':
                printf('<input type="url" id="%1$s" name="%2$s" value="%3$s" class="regular-text code">',
                    esc_attr($id), esc_attr($name), esc_attr($value));
                break;

            case 'select':
                printf('<select id="%1$s" name="%2$s">', esc_attr($id), esc_attr($name));
                foreach ($field['options'] as $opt_value => $opt_label) {
                    printf('<option value="%1$s" %2$s>%3$s</option>',
                        esc_attr($opt_value), selected($value, $opt_value, false), esc_html($opt_label));
                }
                echo '</select>';
                break;

            case 'datetime':
                // datetime-local ожидает формат «ГГГГ-ММ-ДДTЧЧ:ММ»
                $dt_value = $value !== '' ? str_replace(' ', 'T', $value) : '';
                printf('<input type="datetime-local" id="%1$s" name="%2$s" value="%3$s" class="regular-text">',
                    esc_attr($id), esc_attr($name), esc_attr($dt_value));
                break;

            default:
                printf('<input type="text" id="%1$s" name="%2$s" value="%3$s" class="regular-text">',
                    esc_attr($id), esc_attr($name), esc_attr($value));
        }

        if (!empty($field['desc'])) {
            printf('<p class="description">%s</p>', esc_html($field['desc']));
        }
    }
}
