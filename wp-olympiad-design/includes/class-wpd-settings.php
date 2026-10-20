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

    /**
     * Часовые пояса таймера (1.6.1): ключ — идентификатор пояса, значение — [подпись в списке, короткое обозначение].
     * «site» — пояс из настроек сайта (Настройки → Общие).
     */
    const TIMEZONES = [
        'Europe/Kaliningrad' => ['Калининград (МСК−1, UTC+2)', 'МСК−1'],
        'Europe/Moscow'      => ['Москва (МСК, UTC+3)', 'МСК'],
        'Europe/Samara'      => ['Самара (МСК+1, UTC+4)', 'МСК+1'],
        'Asia/Yekaterinburg' => ['Екатеринбург (МСК+2, UTC+5)', 'МСК+2'],
        'Asia/Omsk'          => ['Омск (МСК+3, UTC+6)', 'МСК+3'],
        'Asia/Krasnoyarsk'   => ['Красноярск (МСК+4, UTC+7)', 'МСК+4'],
        'Asia/Irkutsk'       => ['Иркутск (МСК+5, UTC+8)', 'МСК+5'],
        'Asia/Yakutsk'       => ['Якутск (МСК+6, UTC+9)', 'МСК+6'],
        'Asia/Vladivostok'   => ['Владивосток (МСК+7, UTC+10)', 'МСК+7'],
        'Asia/Magadan'       => ['Магадан (МСК+8, UTC+11)', 'МСК+8'],
        'Asia/Kamchatka'     => ['Петропавловск-Камчатский (МСК+9, UTC+12)', 'МСК+9'],
        'site'               => ['Как в настройках сайта (Настройки → Общие)', ''],
    ];

    /** Кеш настроек на время запроса. Сбрасывается при изменении опции. */
    private static $cache = null;

    public static function init(): void
    {
        add_action('admin_menu', [__CLASS__, 'add_menu']);
        add_action('admin_init', [__CLASS__, 'register']);
        add_action('admin_init', [__CLASS__, 'redirect_old_url'], 1);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);
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
            'slider'   => 'Слайдер',
            'buttons'  => 'Кнопки-ссылки',
            'cards'    => 'Карточки',
            'notice'   => 'Объявление',
            'news'     => 'Новости',
            'contacts' => 'Контакты',
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
            'slider'   => ['title' => 'Слайдер', 'sections' => ['slider']],
            'buttons'  => ['title' => 'Кнопки-ссылки', 'sections' => ['buttons']],
            'cards'    => ['title' => 'Карточки', 'sections' => ['cards']],
            'notice'   => ['title' => 'Объявление', 'sections' => ['notice']],
            'news'     => ['title' => 'Новости', 'sections' => ['news']],
            'contacts' => ['title' => 'Контакты', 'sections' => ['contacts']],
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
            'palette'  => ['label' => 'Палитра', 'type' => 'palette', 'section' => 'colors', 'default' => 'custom',
                           'desc' => 'Готовый набор цветов. При сохранении цвета ниже заменяются цветами палитры; «Свои цвета» — значения из полей ниже.'],
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
                                    'desc' => 'Показывает таймер до начала (и до окончания) под главным блоком и через шорткод [wpd_countdown].'],
            'countdown_tz'      => ['label' => 'Часовой пояс таймера', 'type' => 'select', 'section' => 'features', 'default' => 'Europe/Moscow',
                                    'options' => array_map(function ($tz) { return $tz[0]; }, self::TIMEZONES),
                                    'desc' => 'Даты начала и окончания (здесь и в блоках редактора) указываются по этому поясу. Посетители из любого города видят правильный остаток времени.'],
            'countdown_date'    => ['label' => 'Дата и время начала', 'type' => 'datetime', 'section' => 'features', 'default' => '',
                                    'desc' => 'По часовому поясу таймера (поле выше).'],
            'countdown_label'   => ['label' => 'Подпись до начала', 'type' => 'text', 'section' => 'features', 'default' => 'До начала олимпиады'],
            'countdown_end'     => ['label' => 'Дата и время окончания', 'type' => 'datetime', 'section' => 'features', 'default' => '',
                                    'desc' => 'Необязательно. С наступлением начала тот же блок считает время до окончания. Пусто — один таймер, как раньше.'],
            'countdown_end_label' => ['label' => 'Подпись до окончания', 'type' => 'text', 'section' => 'features', 'default' => 'До окончания олимпиады'],
            'countdown_done'    => ['label' => 'Текст после окончания', 'type' => 'text', 'section' => 'features', 'default' => 'Олимпиада уже прошла',
                                    'desc' => 'Показывается после даты окончания, а если она не задана — после даты начала.'],
            'countdown_link_text' => ['label' => 'Текст кнопки', 'type' => 'text', 'section' => 'features', 'default' => 'Перейти к заданиям'],
            'countdown_link_url'  => ['label' => 'Ссылка кнопки', 'type' => 'url', 'section' => 'features', 'default' => '',
                                      'desc' => 'Например, платформа с заданиями. Кнопка видна только между началом и окончанием (без даты окончания — после начала). Пусто — кнопки нет.'],
            'countdown_link_new_tab' => ['label' => 'Кнопка в новой вкладке', 'type' => 'checkbox', 'section' => 'features', 'default' => 1],
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

            // Слайдер
            'slides' => ['label' => 'Слайды', 'type' => 'repeater', 'section' => 'slider', 'default' => [], 'max' => 10,
                         'item' => 'Слайд', 'add' => 'Добавить слайд',
                         'fields' => [
                             'image'   => ['label' => 'Изображение', 'type' => 'image'],
                             'heading' => ['label' => 'Заголовок', 'type' => 'text'],
                             'text'    => ['label' => 'Текст', 'type' => 'textarea'],
                             'url'     => ['label' => 'Ссылка', 'type' => 'url'],
                             'new_tab' => ['label' => 'Открывать в новой вкладке', 'type' => 'checkbox'],
                         ],
                         'desc' => 'До 10 слайдов. Слайд без изображения, заголовка, текста и ссылки не выводится. Нет слайдов — блок не выводится.'],
            'slider_height'          => ['label' => 'Высота слайдера, px', 'type' => 'number', 'section' => 'slider', 'default' => 420,
                                         'min' => 200, 'max' => 900],
            'slider_autoplay'        => ['label' => 'Автопрокрутка', 'type' => 'checkbox', 'section' => 'slider', 'default' => 1,
                                         'desc' => 'Слайды меняются каждые 6 секунд, при наведении — пауза. Отключается для пользователей с настройкой «уменьшить движение».'],
            'slider_arrows'          => ['label' => 'Стрелки', 'type' => 'checkbox', 'section' => 'slider', 'default' => 1],
            'slider_dots'            => ['label' => 'Точки-переключатели', 'type' => 'checkbox', 'section' => 'slider', 'default' => 1],
            'slider_mobile'          => ['label' => 'Показывать на телефонах', 'type' => 'checkbox', 'section' => 'slider', 'default' => 1],
            'slider_mobile_headings' => ['label' => 'Заголовки слайдов на телефонах', 'type' => 'checkbox', 'section' => 'slider', 'default' => 1],
            'slider_mobile_texts'    => ['label' => 'Тексты слайдов на телефонах', 'type' => 'checkbox', 'section' => 'slider', 'default' => 0],

            // Кнопки-ссылки
            'buttons' => ['label' => 'Кнопки', 'type' => 'repeater', 'section' => 'buttons', 'max' => 12,
                          'item' => 'Кнопка', 'add' => 'Добавить кнопку',
                          'default' => [
                              ['icon' => 'media-document', 'text' => 'Положение', 'url' => '', 'new_tab' => 0, 'small' => 0],
                              ['icon' => 'edit', 'text' => 'Регистрация', 'url' => '', 'new_tab' => 0, 'small' => 0],
                              ['icon' => 'awards', 'text' => 'Результаты', 'url' => '', 'new_tab' => 0, 'small' => 0],
                              ['icon' => 'email-alt', 'text' => 'Контакты', 'url' => '', 'new_tab' => 0, 'small' => 0],
                          ],
                          'fields' => [
                              'icon'    => ['label' => 'Иконка', 'type' => 'icon'],
                              'text'    => ['label' => 'Текст', 'type' => 'text'],
                              'url'     => ['label' => 'Ссылка', 'type' => 'url'],
                              'new_tab' => ['label' => 'Открывать в новой вкладке', 'type' => 'checkbox'],
                              'small'   => ['label' => 'Мелкий текст (для длинных подписей)', 'type' => 'checkbox'],
                          ],
                          'desc' => 'Плитки с иконками для быстрых переходов: положение, регистрация, расписание и т. п. Кнопка без ссылки выводится как подпись.'],

            // Карточки
            'cards_title' => ['label' => 'Заголовок блока карточек', 'type' => 'text', 'section' => 'cards', 'default' => '',
                              'desc' => 'Пусто — без заголовка.'],
            'cards'       => ['label' => 'Карточки', 'type' => 'repeater', 'section' => 'cards', 'max' => 8,
                              'item' => 'Карточка', 'add' => 'Добавить карточку',
                              'default' => [
                                  ['title' => 'Участникам', 'text' => 'Положение, сроки и правила участия.', 'image' => '', 'color' => '', 'url' => '', 'new_tab' => 0],
                                  ['title' => 'Учителям', 'text' => 'Методические материалы и регистрация команд.', 'image' => '', 'color' => '', 'url' => '', 'new_tab' => 0],
                                  ['title' => 'Результаты', 'text' => 'Списки победителей и призёров.', 'image' => '', 'color' => '', 'url' => '', 'new_tab' => 0],
                              ],
                              'fields' => [
                                  'title'   => ['label' => 'Заголовок', 'type' => 'text'],
                                  'text'    => ['label' => 'Текст', 'type' => 'textarea'],
                                  'image'   => ['label' => 'Изображение', 'type' => 'image'],
                                  'color'   => ['label' => 'Цвет', 'type' => 'color_opt'],
                                  'url'     => ['label' => 'Ссылка', 'type' => 'url'],
                                  'new_tab' => ['label' => 'Открывать в новой вкладке', 'type' => 'checkbox'],
                              ],
                              'desc' => 'Цветные плитки-разделы. Пустой цвет — цвета палитры по очереди (основной, акцентный, тёмный).'],

            // Объявление
            'notice_layout'    => ['label' => 'Вид', 'type' => 'select', 'section' => 'notice', 'default' => 'image_text',
                                   'options' => ['image_text' => 'Изображение и текст', 'text' => 'Только текст', 'image' => 'Только изображение'],
                                   'desc' => 'Без изображения вид «Изображение и текст» выводится как «Только текст».'],
            'notice_pre_title' => ['label' => 'Текст над заголовком', 'type' => 'text', 'section' => 'notice', 'default' => ''],
            'notice_title'     => ['label' => 'Заголовок', 'type' => 'text', 'section' => 'notice', 'default' => ''],
            'notice_content'   => ['label' => 'Текст объявления', 'type' => 'html', 'section' => 'notice', 'default' => ''],
            'notice_image'     => ['label' => 'Изображение', 'type' => 'image', 'section' => 'notice', 'default' => ''],
            'notice_color'     => ['label' => 'Цвет фона', 'type' => 'color_opt', 'section' => 'notice', 'default' => '',
                                   'desc' => 'Пусто — светлый оттенок основного цвета.'],
            'notice_url'       => ['label' => 'Ссылка', 'type' => 'url', 'section' => 'notice', 'default' => '',
                                   'desc' => 'Ссылкой становятся заголовок и изображение.'],
            'notice_new_tab'   => ['label' => 'Открывать ссылку в новой вкладке', 'type' => 'checkbox', 'section' => 'notice', 'default' => 0],

            // Новости
            'news_title'    => ['label' => 'Заголовок блока', 'type' => 'text', 'section' => 'news', 'default' => 'Новости'],
            'news_category' => ['label' => 'Рубрика', 'type' => 'category', 'section' => 'news', 'default' => 0],
            'news_count'    => ['label' => 'Количество записей', 'type' => 'number', 'section' => 'news', 'default' => 6, 'min' => 1, 'max' => 24],
            'news_layout'   => ['label' => 'Вид', 'type' => 'select', 'section' => 'news', 'default' => 'carousel',
                                'options' => ['carousel' => 'Карусель (листается стрелками)', 'grid' => 'Сетка']],
            'news_more'     => ['label' => 'Текст ссылки на все новости', 'type' => 'text', 'section' => 'news', 'default' => 'Все новости',
                                'desc' => 'Ссылка ведёт на рубрику, а если рубрика не выбрана — на страницу записей. Пусто — без ссылки.'],

            // Контакты
            'contacts_title'  => ['label' => 'Заголовок блока', 'type' => 'text', 'section' => 'contacts', 'default' => 'Контакты'],
            'contact_address' => ['label' => 'Адрес', 'type' => 'text', 'section' => 'contacts', 'default' => ''],
            'contact_city'    => ['label' => 'Индекс и город', 'type' => 'text', 'section' => 'contacts', 'default' => ''],
            'contact_phone'   => ['label' => 'Телефон', 'type' => 'text', 'section' => 'contacts', 'default' => ''],
            'contact_email'   => ['label' => 'E-mail', 'type' => 'email', 'section' => 'contacts', 'default' => ''],
            'contact_map'     => ['label' => 'Ссылка на карту', 'type' => 'url', 'section' => 'contacts', 'default' => '',
                                  'desc' => 'Адрес станет ссылкой на карту (Яндекс Карты, 2ГИС и т. п.).'],
            'contact_page'    => ['label' => 'Страница «Контакты»', 'type' => 'url', 'section' => 'contacts', 'default' => '',
                                  'desc' => 'Кнопка «Связаться». Пусто — кнопка ведёт на e-mail, а без e-mail не выводится.'],
            'contact_code'    => ['label' => 'Код организации', 'type' => 'text', 'section' => 'contacts', 'default' => '',
                                  'desc' => 'Необязательно: ИНН, код образовательной организации и т. п.'],
        ];
    }

    /**
     * Готовые палитры: основной, акцентный, тёмный фон, текст, фон страницы.
     */
    public static function palettes(): array
    {
        return [
            'olympiad' => ['title' => 'Олимпиада', 'colors' => ['#1d4ed8', '#f97316', '#0b1220', '#1f2937', '#f1f5f9']],
            'sea'      => ['title' => 'Море', 'colors' => ['#0e7490', '#f59e0b', '#083344', '#1e293b', '#ecfeff']],
            'forest'   => ['title' => 'Лес', 'colors' => ['#15803d', '#eab308', '#052e16', '#1c2a1f', '#f0fdf4']],
            'cherry'   => ['title' => 'Вишня', 'colors' => ['#be123c', '#f59e0b', '#2a0a12', '#292524', '#fff1f2']],
            'violet'   => ['title' => 'Фиалка', 'colors' => ['#6d28d9', '#ec4899', '#1e1033', '#1f1b2e', '#f5f3ff']],
            'graphite' => ['title' => 'Графит', 'colors' => ['#334155', '#0ea5e9', '#0f172a', '#1e293b', '#f8fafc']],
        ];
    }

    /** Поля цветов в порядке значений палитры. */
    const PALETTE_KEYS = ['primary', 'accent', 'dark', 'text', 'bg'];

    /**
     * Иконки для кнопок-ссылок (Dashicons из ядра WordPress, подписи для выбора).
     */
    public static function icons(): array
    {
        return [
            'media-document'     => 'Документ',
            'edit'               => 'Карандаш',
            'awards'             => 'Награда',
            'email-alt'          => 'Конверт',
            'welcome-learn-more' => 'Академическая шапка',
            'book'               => 'Книга',
            'calendar-alt'       => 'Календарь',
            'clock'              => 'Часы',
            'groups'             => 'Группа людей',
            'admin-users'        => 'Человек',
            'businessperson'     => 'Преподаватель',
            'star-filled'        => 'Звезда',
            'flag'               => 'Флаг',
            'megaphone'          => 'Рупор',
            'portfolio'          => 'Портфель',
            'clipboard'          => 'Планшет',
            'welcome-write-blog' => 'Блокнот',
            'format-chat'        => 'Чат',
            'phone'              => 'Телефон',
            'location-alt'       => 'Метка на карте',
            'admin-site-alt3'    => 'Глобус',
            'admin-links'        => 'Ссылка',
            'download'           => 'Скачать',
            'video-alt3'         => 'Видео',
            'format-gallery'     => 'Галерея',
            'camera'             => 'Камера',
            'art'                => 'Палитра',
            'lightbulb'          => 'Лампочка',
            'chart-bar'          => 'Диаграмма',
            'info-outline'       => 'Информация',
            'editor-help'        => 'Вопрос',
            'yes-alt'            => 'Галочка',
            'warning'            => 'Внимание',
            'heart'              => 'Сердце',
            'building'           => 'Здание',
            'admin-home'         => 'Дом',
            'laptop'             => 'Ноутбук',
            'smartphone'         => 'Смартфон',
            'tickets-alt'        => 'Билет',
            'money-alt'          => 'Оплата',
            'shield'             => 'Щит',
            'search'             => 'Поиск',
            'translation'        => 'Перевод',
            'microphone'         => 'Микрофон',
            'id'                 => 'Удостоверение',
            'external'           => 'Внешняя ссылка',
        ];
    }

    /**
     * Очистка строк повторяющегося поля (слайды, кнопки, карточки). Пустые строки отбрасываются,
     * лишние сверх max — тоже. Используется и настройками, и блоками редактора.
     */
    public static function sanitize_rows($rows, string $key): array
    {
        $field = self::field_map()[$key] ?? null;
        if (!$field || $field['type'] !== 'repeater' || !is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $index => $row) {
            // Строка-шаблон повторителя (__i__) и мусор не сохраняются
            if (!is_array($row) || $index === '__i__') {
                continue;
            }
            $clean = [];
            $empty = true;
            foreach ($field['fields'] as $sub => $def) {
                $raw = isset($row[$sub]) && is_scalar($row[$sub]) ? (string) $row[$sub] : '';
                switch ($def['type']) {
                    case 'checkbox':
                        $clean[$sub] = ($raw !== '' && $raw !== '0' && $raw !== 'false') ? 1 : 0;
                        continue 2;
                    case 'url':
                    case 'image':
                        $clean[$sub] = esc_url_raw(trim($raw));
                        break;
                    case 'color_opt':
                        $clean[$sub] = (string) sanitize_hex_color(trim($raw));
                        continue 2;
                    case 'icon':
                        $clean[$sub] = isset(self::icons()[$raw]) ? $raw : (string) array_key_first(self::icons());
                        continue 2;
                    case 'textarea':
                        $clean[$sub] = sanitize_textarea_field($raw);
                        break;
                    default:
                        $clean[$sub] = sanitize_text_field($raw);
                }
                if ($clean[$sub] !== '') {
                    $empty = false;
                }
            }
            if (!$empty) {
                $out[] = $clean;
            }
            if (count($out) >= (int) $field['max']) {
                break;
            }
        }
        return $out;
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
            // Повторяющееся поле: форма всегда отправляет маркер «_», поэтому пустой список — это []
            if ($field['type'] === 'repeater') {
                $out[$key] = $has ? self::sanitize_rows($raw, $key) : $field['default'];
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

                case 'color_opt':
                    $out[$key] = (string) sanitize_hex_color(trim($raw));
                    break;

                case 'image':
                    $out[$key] = esc_url_raw(trim($raw));
                    break;

                case 'email':
                    $out[$key] = sanitize_email($raw);
                    break;

                case 'html':
                    $out[$key] = trim(wp_kses_post($raw));
                    break;

                case 'category':
                    $id = absint($raw);
                    $out[$key] = ($id && term_exists($id, 'category')) ? $id : 0;
                    break;

                case 'palette':
                    $out[$key] = ($raw === 'custom' || isset(self::palettes()[$raw])) ? $raw : 'custom';
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

        // Дата окончания должна быть позже даты начала, иначе второй таймер не показывается
        if (($sections === null || in_array('features', $sections, true))
            && $out['countdown_date'] !== '' && $out['countdown_end'] !== ''
            && strcmp($out['countdown_end'], $out['countdown_date']) <= 0
            && function_exists('add_settings_error')
            && !in_array('wpd_countdown_end', array_column(get_settings_errors('wpd_settings'), 'code'), true)) {
            add_settings_error('wpd_settings', 'wpd_countdown_end', 'Дата окончания таймера не позже даты начала — второй таймер и кнопка не будут показаны.', 'warning');
        }

        // Выбранная палитра задаёт цвета (только когда сохраняется вкладка с цветами или все поля сразу)
        if (($sections === null || in_array('colors', $sections, true)) && isset(self::palettes()[$out['palette']])) {
            foreach (self::PALETTE_KEYS as $i => $color_key) {
                $out[$color_key] = self::palettes()[$out['palette']]['colors'][$i];
            }
        }

        return $out;
    }

    /**
     * Часовой пояс таймера. Неизвестное значение или «site» — пояс сайта.
     */
    public static function countdown_timezone(?array $o = null): DateTimeZone
    {
        $o   = $o ?? self::get();
        $key = (string) ($o['countdown_tz'] ?? 'Europe/Moscow');
        if ($key !== 'site' && isset(self::TIMEZONES[$key])) {
            try {
                return new DateTimeZone($key);
            } catch (Exception $e) {
                // нет данных о поясе — берём пояс сайта
            }
        }
        return wp_timezone();
    }

    /**
     * Короткое обозначение пояса таймера: «МСК», «МСК+4»… Для пояса сайта вне списка — «UTC+5:30» и т. п.
     */
    public static function countdown_tz_label(?array $o = null): string
    {
        $o   = $o ?? self::get();
        $key = (string) ($o['countdown_tz'] ?? 'Europe/Moscow');
        if ($key === 'site' || !isset(self::TIMEZONES[$key])) {
            $tz = wp_timezone();
            if (isset(self::TIMEZONES[$tz->getName()])) {
                return self::TIMEZONES[$tz->getName()][1];
            }
            $off  = $tz->getOffset(new DateTime('now', $tz));
            $sign = $off < 0 ? '−' : '+';
            $off  = abs($off);
            $h    = intdiv($off, 3600);
            $m    = intdiv($off % 3600, 60);
            return 'UTC' . $sign . $h . ($m ? sprintf(':%02d', $m) : '');
        }
        return self::TIMEZONES[$key][1];
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
                in_array($field['type'], ['repeater', 'palette', 'checkbox'], true)
                    ? ['key' => $key]
                    : ['key' => $key, 'label_for' => $field['type'] === 'html' ? 'wpd' . str_replace('_', '', $key) : 'wpd_' . $key]
            );
        }
    }

    /**
     * Скрипты страницы настроек: повторяющиеся поля, медиатека, выбор цвета, палитры.
     */
    public static function enqueue(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification
        if (!isset($_GET['page']) || $_GET['page'] !== self::PAGE) {
            return;
        }
        wp_enqueue_media();
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_style('wpd-settings', WPD_URL . 'assets/css/wpd-settings.css', ['wp-color-picker'], WPD_VERSION);
        wp_enqueue_script('wpd-settings', WPD_URL . 'assets/js/wpd-settings.js', ['jquery', 'wp-color-picker'], WPD_VERSION, true);
        wp_localize_script('wpd-settings', 'wpdSettings', [
            'chooseImage' => 'Выбор изображения',
            'use'         => 'Выбрать',
            'remove'      => 'Удалить?',
        ]);
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
                <li><strong>Блоки редактора</strong> в категории «Олимпиада»: главный блок, обратный отсчёт, статистика, документы, FAQ,
                    слайдер, кнопки-ссылки, карточки, объявление, новости, контакты.
                    Текст правится прямо на странице. Готовый шаблон — «Паттерны → Олимпиада → Страница олимпиады».</li>
            </ol>

            <h2>Шорткоды</h2>
            <p>Выводят блоки с общим содержимым — для виджетов, шаблонов и классического редактора.</p>
            <table class="widefat striped" style="max-width:640px">
                <tbody>
                    <tr><td><code>[wpd_page]</code></td><td>все блоки сразу: главный блок, таймер, статистика, документы, FAQ</td></tr>
                    <tr><td><code>[wpd_hero]</code></td><td>главный блок</td></tr>
                    <tr><td><code>[wpd_countdown]</code></td><td>обратный отсчёт до начала и до окончания, кнопка на время этапа (если включён и задана дата); даты — по часовому поясу таймера, по умолчанию московскому</td></tr>
                    <tr><td><code>[wpd_stats]</code></td><td>статистика</td></tr>
                    <tr><td><code>[wpd_docs]</code></td><td>документы</td></tr>
                    <tr><td><code>[wpd_faq]</code></td><td>частые вопросы</td></tr>
                    <tr><td><code>[wpd_slider]</code></td><td>слайдер</td></tr>
                    <tr><td><code>[wpd_buttons]</code></td><td>кнопки-ссылки с иконками</td></tr>
                    <tr><td><code>[wpd_cards]</code></td><td>цветные карточки-разделы</td></tr>
                    <tr><td><code>[wpd_notice]</code></td><td>объявление</td></tr>
                    <tr><td><code>[wpd_news]</code></td><td>последние новости из рубрики</td></tr>
                    <tr><td><code>[wpd_contacts]</code></td><td>контакты: адрес, телефон, e-mail</td></tr>
                </tbody>
            </table>

            <h2>Главная страница из компонентов</h2>
            <p>Слайдер, кнопки-ссылки, карточки, объявление, новости и контакты удобно собрать на главной странице сайта:</p>
            <ol>
                <li>Заполните вкладки «Слайдер», «Кнопки-ссылки», «Карточки», «Объявление», «Новости» и «Контакты».</li>
                <li>Вставьте шаблон <strong>«Паттерны → Олимпиада → Главная страница»</strong> в редакторе блоков — или включите
                    оформление страницы и отметьте нужные компоненты в панели «Блоки олимпиады».</li>
                <li>Назначьте страницу главной: <em>Настройки → Чтение → Статическая страница</em>.</li>
            </ol>
            <p>На уже оформленных страницах новые компоненты скрыты, пока вы не отметите их в панели «Блоки олимпиады».</p>

            <h2>Форматы списков</h2>
            <ul style="list-style:disc;margin-left:20px">
                <li><strong>Статистика:</strong> одна карточка на строку, <code>число|подпись</code>.</li>
                <li><strong>Документы:</strong> одна ссылка на строку, <code>название|ссылка</code>.</li>
                <li><strong>FAQ:</strong> блоки через пустую строку; первая строка — вопрос, остальные — ответ. Разрешены теги a, strong, em, br.</li>
                <li><strong>Слайды, кнопки, карточки:</strong> строки добавляются кнопкой «+ Добавить…», переставляются стрелками; изображение можно выбрать из медиатеки.</li>
            </ul>
        </div>
        <?php
    }

    /**
     * Палитры: карточки с образцами цветов, радиокнопки работают и без JavaScript.
     */
    private static function render_palette(string $name, string $value): void
    {
        echo '<fieldset class="wpd-palettes"><legend class="screen-reader-text">Палитра</legend>';
        $all = self::palettes() + ['custom' => ['title' => 'Свои цвета', 'colors' => []]];
        foreach ($all as $id => $palette) {
            $swatches = '';
            foreach ($palette['colors'] as $color) {
                $swatches .= '<span style="background:' . esc_attr($color) . '"></span>';
            }
            if ($id === 'custom') {
                $swatches = '<span class="wpd-palette__custom" aria-hidden="true">✎</span>';
            }
            printf(
                '<label class="wpd-palette"><input type="radio" name="%1$s" value="%2$s" data-colors="%3$s"%4$s>'
                . '<span class="wpd-palette__sw">%5$s</span><span class="wpd-palette__name">%6$s</span></label>',
                esc_attr($name),
                esc_attr($id),
                esc_attr((string) wp_json_encode(array_combine(
                    array_slice(self::PALETTE_KEYS, 0, count($palette['colors'])),
                    $palette['colors']
                ) ?: new stdClass())),
                checked($value, $id, false),
                $swatches, // phpcs:ignore WordPress.Security.EscapeOutput -- собрано выше с esc_attr
                esc_html($palette['title'])
            );
        }
        echo '</fieldset>';
    }

    /**
     * Поле изображения: адрес + выбор из медиатеки + превью.
     */
    private static function image_input(string $id, string $name, string $value): string
    {
        return sprintf(
            '<span class="wpd-img" data-wpd-s-media>'
            . '<img src="%3$s" alt="" data-wpd-s-preview%4$s>'
            . '<input type="url" id="%1$s" name="%2$s" value="%3$s" class="regular-text code" placeholder="https://…" data-wpd-s-url>'
            . ' <button type="button" class="button" data-wpd-s-pick>Медиатека…</button>'
            . '</span>',
            esc_attr($id),
            esc_attr($name),
            esc_attr($value),
            $value === '' ? ' hidden' : ''
        );
    }

    /**
     * Повторяющееся поле: список строк, шаблон новой строки и кнопка добавления.
     * Скрытый маркер «_» отправляется всегда, чтобы пустой список сохранялся как пустой.
     */
    private static function render_repeater(string $key, array $field, array $rows): void
    {
        printf(
            '<div class="wpd-rep" data-wpd-rep="%1$s" data-max="%2$d" data-next="%3$d">'
            . '<input type="hidden" name="%4$s[%1$s][_]" value="">'
            . '<div class="wpd-rep__list" data-wpd-rep-list>',
            esc_attr($key),
            (int) $field['max'],
            count($rows),
            esc_attr(self::OPTION)
        );
        foreach (array_values($rows) as $i => $row) {
            echo self::repeater_row($key, $field, (string) $i, is_array($row) ? $row : []); // phpcs:ignore WordPress.Security.EscapeOutput
        }
        echo '</div>';
        echo '<template data-wpd-rep-tpl>' . self::repeater_row($key, $field, '__i__', []) . '</template>'; // phpcs:ignore WordPress.Security.EscapeOutput
        printf(
            '<p><button type="button" class="button" data-wpd-rep-add%3$s>+ %1$s</button> <span class="description">не больше %2$d</span></p></div>',
            esc_html($field['add']),
            (int) $field['max'],
            count($rows) >= (int) $field['max'] ? ' disabled' : ''
        );
    }

    private static function repeater_row(string $key, array $field, string $i, array $row): string
    {
        $base  = self::OPTION . '[' . $key . '][' . $i . ']';
        $html  = '<div class="wpd-rep__row" data-wpd-rep-row>';
        $html .= '<div class="wpd-rep__head"><strong class="wpd-rep__title">' . esc_html($field['item']) . ' <span data-wpd-rep-num>'
            . ($i === '__i__' ? '' : esc_html((string) ((int) $i + 1))) . '</span></strong>'
            . '<span class="wpd-rep__tools">'
            . '<button type="button" class="button-link" data-wpd-rep-move="-1" aria-label="Выше"><span class="dashicons dashicons-arrow-up-alt2"></span></button>'
            . '<button type="button" class="button-link" data-wpd-rep-move="1" aria-label="Ниже"><span class="dashicons dashicons-arrow-down-alt2"></span></button>'
            . '<button type="button" class="button-link button-link-delete" data-wpd-rep-remove aria-label="Удалить"><span class="dashicons dashicons-trash"></span></button>'
            . '</span></div><div class="wpd-rep__fields">';

        foreach ($field['fields'] as $sub => $def) {
            $name  = $base . '[' . $sub . ']';
            $value = isset($row[$sub]) && is_scalar($row[$sub]) ? (string) $row[$sub] : '';
            $id    = 'wpd_' . $key . '_' . $i . '_' . $sub;
            $label = esc_html($def['label']);

            switch ($def['type']) {
                case 'checkbox':
                    $html .= sprintf('<label class="wpd-rep__f wpd-rep__f--check"><input type="checkbox" name="%s" value="1"%s> %s</label>',
                        esc_attr($name), checked($value, '1', false), $label);
                    break;
                case 'textarea':
                    $html .= sprintf('<label class="wpd-rep__f wpd-rep__f--wide" for="%1$s"><span>%2$s</span><textarea id="%1$s" name="%3$s" rows="2" class="large-text">%4$s</textarea></label>',
                        esc_attr($id), $label, esc_attr($name), esc_textarea($value));
                    break;
                case 'image':
                    $html .= '<div class="wpd-rep__f wpd-rep__f--wide"><label for="' . esc_attr($id) . '">' . $label . '</label>'
                        . self::image_input($id, $name, $value) . '</div>';
                    break;
                case 'color_opt':
                    $html .= sprintf('<div class="wpd-rep__f"><label for="%1$s">%2$s</label><input type="text" id="%1$s" name="%3$s" value="%4$s" class="wpd-color-opt" placeholder="#rrggbb" maxlength="7"></div>',
                        esc_attr($id), $label, esc_attr($name), esc_attr($value));
                    break;
                case 'icon':
                    $current = isset(self::icons()[$value]) ? $value : (string) array_key_first(self::icons());
                    $opts = '';
                    foreach (self::icons() as $icon => $icon_label) {
                        $opts .= sprintf('<option value="%s"%s>%s</option>', esc_attr($icon), selected($current, $icon, false), esc_html($icon_label));
                    }
                    $html .= sprintf('<label class="wpd-rep__f" for="%1$s"><span>%2$s</span><span class="wpd-icon-pick">'
                        . '<span class="dashicons dashicons-%3$s" data-wpd-icon-preview aria-hidden="true"></span>'
                        . '<select id="%1$s" name="%4$s" data-wpd-icon>%5$s</select></span></label>',
                        esc_attr($id), $label, esc_attr($current), esc_attr($name), $opts);
                    break;
                default:
                    $html .= sprintf('<label class="wpd-rep__f" for="%1$s"><span>%2$s</span><input type="%5$s" id="%1$s" name="%3$s" value="%4$s" class="regular-text"%6$s></label>',
                        esc_attr($id), $label, esc_attr($name), esc_attr($value), $def['type'] === 'url' ? 'url' : 'text',
                        $def['type'] === 'url' ? ' placeholder="https://…"' : '');
            }
        }
        return $html . '</div></div>';
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

            case 'repeater':
                self::render_repeater($key, $field, is_array($value) ? $value : []);
                break;

            case 'palette':
                self::render_palette($name, (string) $value);
                break;

            case 'image':
                echo self::image_input($id, $name, (string) $value); // phpcs:ignore WordPress.Security.EscapeOutput -- экранировано внутри
                break;

            case 'color_opt':
                printf('<input type="text" id="%1$s" name="%2$s" value="%3$s" class="wpd-color-opt" placeholder="#rrggbb" maxlength="7">',
                    esc_attr($id), esc_attr($name), esc_attr((string) $value));
                break;

            case 'email':
                printf('<input type="email" id="%1$s" name="%2$s" value="%3$s" class="regular-text">',
                    esc_attr($id), esc_attr($name), esc_attr((string) $value));
                break;

            case 'html':
                wp_editor((string) $value, 'wpd' . str_replace('_', '', $key), [
                    'textarea_name' => $name,
                    'textarea_rows' => 6,
                    'media_buttons' => false,
                    'teeny'         => true,
                ]);
                break;

            case 'category':
                wp_dropdown_categories([
                    'show_option_all' => 'Все рубрики',
                    'name'            => $name,
                    'id'              => $id,
                    'selected'        => (int) $value,
                    'hide_empty'      => 0,
                    'hierarchical'    => 1,
                    'orderby'         => 'name',
                ]);
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
