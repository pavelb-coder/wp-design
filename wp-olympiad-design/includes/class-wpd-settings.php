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

    /** Кеш настроек на время запроса. Сбрасывается при изменении опции. */
    private static $cache = null;

    public static function init(): void
    {
        add_action('admin_menu', [__CLASS__, 'add_menu']);
        add_action('admin_init', [__CLASS__, 'register']);

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
     * Группы страницы настроек: «Страница» (оформление всего сайта) и «Блоки» (содержимое блоков).
     * Каждый раздел входит ровно в одну группу.
     */
    public static function groups(): array
    {
        return [
            'page' => [
                'title'    => 'Страница',
                'desc'     => 'Цвета, шрифт и поведение оформленных страниц. Действуют на все блоки плагина.',
                'sections' => ['colors', 'behavior'],
            ],
            'blocks' => [
                'title'    => 'Блоки',
                'desc'     => 'Содержимое и возможности блоков: главный блок, таймер, статистика, документы и FAQ.',
                'sections' => ['hero', 'features', 'stats', 'docs', 'faq'],
            ],
        ];
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

        foreach (self::field_map() as $key => $field) {
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
        add_options_page(
            'Дизайн олимпиады',
            'Дизайн олимпиады',
            'manage_options',
            self::PAGE,
            [__CLASS__, 'render_page']
        );
    }

    public static function register(): void
    {
        register_setting(self::GROUP, self::OPTION, [
            'type'              => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize'],
            'default'           => self::defaults(),
        ]);

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
                ['key' => $key]
            );
        }
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>Дизайн олимпиады</h1>
            <p>Настройте оформление и содержимое блоков. Чтобы вывести блоки на любой странице, используйте шорткоды:</p>
            <ul style="list-style:disc;margin-left:20px">
                <li><code>[wpd_page]</code> — все блоки сразу: главный блок, статистика, документы, FAQ;</li>
                <li><code>[wpd_hero]</code>, <code>[wpd_stats]</code>, <code>[wpd_docs]</code>, <code>[wpd_faq]</code> — отдельные блоки.</li>
            </ul>
            <p>Чтобы оформить целую страницу, откройте её редактор и включите галочку «Оформление в стиле олимпиады» в правой колонке.</p>
            <form method="post" action="options.php">
                <?php
                settings_fields(self::GROUP);
                foreach (self::groups() as $group) {
                    echo '<div class="postbox wpd-group"><h2 class="hndle"><span>' . esc_html($group['title']) . '</span></h2>';
                    echo '<div class="inside"><p class="description">' . esc_html($group['desc']) . '</p>';
                    foreach ($group['sections'] as $section) {
                        $title = self::sections()[$section];
                        echo '<h3 class="wpd-group__section">' . esc_html($title) . '</h3>';
                        echo '<table class="form-table" role="presentation">';
                        do_settings_fields(self::PAGE, 'wpd_' . $section);
                        echo '</table>';
                    }
                    echo '</div></div>';
                }
                submit_button('Сохранить');
                ?>
            </form>
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
