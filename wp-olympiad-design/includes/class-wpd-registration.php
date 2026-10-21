<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Регистрация участников (2.0.0): форма заявки с конструктором полей, аккаунты и личный кабинет,
 * письма участнику и организатору, защита от спама, сроки и лимит мест. Список заявок — WPD_Participants.
 */
class WPD_Registration
{
    const OPTION  = 'wpd_registration';
    const GROUP   = 'wpd_registration_group';
    const PAGE    = 'wpd-registration';
    const CPT     = 'wpd_application';
    const ROLE    = 'wpd_participant';
    const COUNTER = 'wpd_reg_counter';

    const STATUSES = ['new' => 'Новая', 'approved' => 'Подтверждена', 'rejected' => 'Отклонена'];

    const FIELD_TYPES = [
        'text'     => 'Строка',
        'textarea' => 'Текст в несколько строк',
        'tel'      => 'Телефон',
        'email'    => 'E-mail',
        'number'   => 'Число',
        'date'     => 'Дата',
        'select'   => 'Выпадающий список',
        'radio'    => 'Выбор одного варианта',
        'checkbox' => 'Флажок (да / нет)',
    ];

    /** Поля, которые есть всегда (их нет в конструкторе). */
    const SYSTEM_FIELDS = ['name', 'email'];

    const MAX_FIELDS  = 30;
    const MAX_OPTIONS = 50;
    /** Форма, отправленная быстрее, считается спамом. */
    const MIN_SECONDS = 3;
    /** Срок жизни метки времени формы (страница может быть закеширована). */
    const MAX_FORM_AGE = 7 * DAY_IN_SECONDS;

    private static $cache = null;

    /** Результат обработки формы в этом запросе: ['form' => 'register'|'edit', 'values' => [], 'errors' => [], 'notice' => '']. */
    private static $state = null;

    public static function init(): void
    {
        add_action('init', [__CLASS__, 'register_types']);
        add_action('init', [__CLASS__, 'register_blocks'], 20);
        add_action('admin_menu', [__CLASS__, 'add_menu'], 20);
        add_action('admin_init', [__CLASS__, 'register_setting']);
        add_action('admin_init', [__CLASS__, 'block_admin_for_participants'], 1);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin']);
        add_action('template_redirect', [__CLASS__, 'handle_post'], 5);
        add_filter('show_admin_bar', [__CLASS__, 'show_admin_bar']);
        add_filter('login_redirect', [__CLASS__, 'login_redirect'], 10, 3);
        add_filter('wpd_always_enqueue_assets', [__CLASS__, 'needs_assets']);
        add_filter('wp_privacy_personal_data_exporters', [__CLASS__, 'register_exporter']);
        add_filter('wp_privacy_personal_data_erasers', [__CLASS__, 'register_eraser']);

        add_shortcode('wpd_registration', [__CLASS__, 'sc_registration']);
        add_shortcode('wpd_cabinet', [__CLASS__, 'sc_cabinet']);

        add_action('add_option_' . self::OPTION, [__CLASS__, 'flush_cache']);
        add_action('update_option_' . self::OPTION, [__CLASS__, 'flush_cache']);
        add_action('delete_option_' . self::OPTION, [__CLASS__, 'flush_cache']);
    }

    public static function flush_cache(): void
    {
        self::$cache = null;
    }

    /* ---------------------------------------------------------------
     * Настройки
     * ------------------------------------------------------------- */

    public static function sections(): array
    {
        return [
            'reg_main'    => 'Приём заявок и личный кабинет',
            'reg_texts'   => 'Тексты формы',
            'reg_protect' => 'Защита от спама',
            'reg_fields'  => 'Поля формы',
            'reg_mail'    => 'Письма',
        ];
    }

    public static function tabs(): array
    {
        return [
            'form'   => ['title' => 'Приём заявок', 'sections' => ['reg_main', 'reg_texts', 'reg_protect']],
            'fields' => ['title' => 'Поля формы', 'sections' => ['reg_fields']],
            'mail'   => ['title' => 'Письма', 'sections' => ['reg_mail']],
        ];
    }

    public static function default_fields(): array
    {
        return [
            ['id' => 'phone', 'label' => 'Телефон', 'type' => 'tel', 'required' => 1, 'options' => '', 'placeholder' => '+7 900 000-00-00', 'in_list' => 0],
            ['id' => 'grade', 'label' => 'Класс', 'type' => 'select', 'required' => 1, 'options' => "5\n6\n7\n8\n9\n10\n11", 'placeholder' => '', 'in_list' => 1],
            ['id' => 'school', 'label' => 'Школа', 'type' => 'text', 'required' => 1, 'options' => '', 'placeholder' => 'Полное название и номер школы', 'in_list' => 1],
            ['id' => 'city', 'label' => 'Регион, город', 'type' => 'text', 'required' => 1, 'options' => '', 'placeholder' => '', 'in_list' => 0],
        ];
    }

    /**
     * Описание всех настроек регистрации — источник дефолтов, санитизации и формы.
     */
    public static function field_map(): array
    {
        $vars = '{name}, {email}, {number}, {status}, {fields}, {cabinet}, {site}';
        return [
            // Приём заявок
            'enabled'    => ['label' => 'Приём заявок открыт', 'type' => 'checkbox', 'section' => 'reg_main', 'default' => 1,
                             'desc' => 'Снимите флажок, чтобы закрыть форму вручную.'],
            'period'     => ['label' => 'Сроки приёма', 'type' => 'select', 'section' => 'reg_main', 'default' => 'always',
                             'options' => ['always' => 'Без ограничения по времени', 'dates' => 'С даты по дату', 'countdown' => 'До начала олимпиады (дата таймера)'],
                             'desc' => '«До начала олимпиады» берёт дату начала с вкладки «Таймер и эффекты».'],
            'start'      => ['label' => 'Начало приёма', 'type' => 'datetime', 'section' => 'reg_main', 'default' => '',
                             'desc' => 'Для «С даты по дату». Пусто — сразу. Часовой пояс — как у таймера.'],
            'end'        => ['label' => 'Окончание приёма', 'type' => 'datetime', 'section' => 'reg_main', 'default' => '',
                             'desc' => 'Для «С даты по дату». Пусто — без окончания.'],
            'limit'      => ['label' => 'Количество мест', 'type' => 'number', 'section' => 'reg_main', 'default' => 0, 'min' => 0, 'max' => 100000,
                             'desc' => '0 — без ограничения. Отклонённые заявки места не занимают.'],
            'show_left'  => ['label' => 'Показывать, сколько мест осталось', 'type' => 'checkbox', 'section' => 'reg_main', 'default' => 1],
            'accounts'   => ['label' => 'Создавать аккаунт участника', 'type' => 'checkbox', 'section' => 'reg_main', 'default' => 1,
                             'desc' => 'Участник получает письмо со ссылкой для установки пароля, входит в личный кабинет и видит статус заявки. Админка и верхняя панель WordPress участникам недоступны.'],
            'autologin'  => ['label' => 'Входить сразу после регистрации', 'type' => 'checkbox', 'section' => 'reg_main', 'default' => 1],
            'allow_edit' => ['label' => 'Участник может изменить заявку', 'type' => 'checkbox', 'section' => 'reg_main', 'default' => 1,
                             'desc' => 'В личном кабинете, пока заявка в статусе «Новая» и приём не закрыт.'],
            'form_page'  => ['label' => 'Страница с формой', 'type' => 'page', 'section' => 'reg_main', 'default' => 0,
                             'desc' => 'Страница с шорткодом [wpd_registration] или блоком «Олимпиада: регистрация» — для ссылок в кабинете.'],
            'cabinet_page' => ['label' => 'Страница личного кабинета', 'type' => 'page', 'section' => 'reg_main', 'default' => 0,
                             'desc' => 'Страница с шорткодом [wpd_cabinet] или блоком «Олимпиада: личный кабинет». После регистрации участник переходит туда, ссылка на неё — в письмах.'],
            'number_prefix' => ['label' => 'Префикс номера заявки', 'type' => 'text', 'section' => 'reg_main', 'default' => 'OL-',
                             'desc' => 'Номер — префикс и порядковый номер из 4 цифр, например OL-0001.'],

            // Тексты
            'title'      => ['label' => 'Заголовок формы', 'type' => 'text', 'section' => 'reg_texts', 'default' => 'Регистрация участников'],
            'intro'      => ['label' => 'Текст над формой', 'type' => 'textarea', 'section' => 'reg_texts',
                             'default' => 'Заполните форму. После проверки заявки мы пришлём подтверждение на e-mail.'],
            'button'     => ['label' => 'Текст кнопки', 'type' => 'text', 'section' => 'reg_texts', 'default' => 'Отправить заявку'],
            'success'    => ['label' => 'Сообщение после отправки', 'type' => 'textarea', 'section' => 'reg_texts',
                             'default' => 'Спасибо! Заявка {number} принята. Подтверждение отправлено на {email}.',
                             'desc' => 'Можно использовать {name}, {email}, {number}.'],
            'not_started' => ['label' => 'Приём ещё не начался', 'type' => 'text', 'section' => 'reg_texts', 'default' => 'Приём заявок начнётся {start}.',
                             'desc' => '{start} — дата начала.'],
            'closed'     => ['label' => 'Приём закрыт', 'type' => 'text', 'section' => 'reg_texts', 'default' => 'Приём заявок закрыт.'],
            'full'       => ['label' => 'Места закончились', 'type' => 'text', 'section' => 'reg_texts', 'default' => 'Все места заняты, приём заявок закрыт.'],
            'consent'    => ['label' => 'Текст согласия', 'type' => 'textarea', 'section' => 'reg_texts',
                             'default' => 'Я даю согласие на обработку моих персональных данных в соответствии с Федеральным законом № 152-ФЗ «О персональных данных».',
                             'desc' => 'Флажок согласия обязателен. Ссылка на политику выводится после текста.'],
            'privacy_url' => ['label' => 'Политика обработки персональных данных', 'type' => 'url', 'section' => 'reg_texts', 'default' => '',
                             'desc' => 'Пусто — страница политики конфиденциальности из «Настройки → Конфиденциальность», если она задана.'],

            // Защита
            'rate_limit' => ['label' => 'Заявок с одного IP-адреса в час', 'type' => 'number', 'section' => 'reg_protect', 'default' => 20, 'min' => 0, 'max' => 500,
                             'desc' => '0 — без ограничения. Учтите, что из одной школы участники могут заходить с одного адреса. Адрес хранится только в виде хеша и не дольше часа. Скрытое поле-ловушка и проверка времени заполнения работают всегда, без внешних сервисов.'],

            // Поля формы
            'fields'     => ['label' => 'Дополнительные поля', 'type' => 'fields', 'section' => 'reg_fields', 'default' => self::default_fields(),
                             'desc' => 'Поля «ФИО участника» и «E-mail» есть всегда и стоят первыми. Для списка и выбора одного варианта укажите варианты — по одному на строку. Отмеченные «В списке» поля выводятся колонками в «Участниках».'],

            // Письма
            'mail_user'  => ['label' => 'Письмо участнику о приёме заявки', 'type' => 'checkbox', 'section' => 'reg_mail', 'default' => 1],
            'mail_user_subject' => ['label' => 'Тема', 'type' => 'text', 'section' => 'reg_mail', 'default' => 'Заявка {number} принята'],
            'mail_user_body' => ['label' => 'Текст', 'type' => 'textarea', 'section' => 'reg_mail',
                             'default' => "Здравствуйте, {name}!\n\nВаша заявка {number} на участие в олимпиаде принята. Статус: {status}.\n\n{fields}\n\nЛичный кабинет: {cabinet}\n\n{site}",
                             'desc' => 'Подстановки: ' . $vars . '.'],
            'mail_status' => ['label' => 'Письмо участнику при смене статуса', 'type' => 'checkbox', 'section' => 'reg_mail', 'default' => 1,
                             'desc' => 'При смене статуса в «Участниках» письмо можно не отправлять — флажок есть рядом со статусом.'],
            'mail_status_subject' => ['label' => 'Тема', 'type' => 'text', 'section' => 'reg_mail', 'default' => 'Заявка {number}: {status}'],
            'mail_status_body' => ['label' => 'Текст', 'type' => 'textarea', 'section' => 'reg_mail',
                             'default' => "Здравствуйте, {name}!\n\nСтатус вашей заявки {number} изменён: {status}.\n\nЛичный кабинет: {cabinet}\n\n{site}",
                             'desc' => 'Подстановки: ' . $vars . '.'],
            'mail_admin' => ['label' => 'Уведомлять организатора о новых заявках', 'type' => 'checkbox', 'section' => 'reg_mail', 'default' => 1],
            'admin_email' => ['label' => 'E-mail организатора', 'type' => 'email', 'section' => 'reg_mail', 'default' => '',
                             'desc' => 'Пусто — адрес администратора сайта.'],
        ];
    }

    public static function defaults(): array
    {
        return array_map(function ($f) {
            return $f['default'];
        }, self::field_map());
    }

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
        if (!is_array($out['fields'])) {
            $out['fields'] = [];
        }
        self::$cache = $out;
        return $out;
    }

    /** Варианты списка: по одному на строку, без пустых и повторов. */
    public static function option_lines(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = sanitize_text_field($line);
            if ($line !== '' && !in_array($line, $out, true)) {
                $out[] = $line;
            }
            if (count($out) >= self::MAX_OPTIONS) {
                break;
            }
        }
        return $out;
    }

    /**
     * Очистка строк конструктора полей. Строка без названия отбрасывается. У каждого поля постоянный id:
     * он задаётся при первом сохранении и не меняется при переименовании, поэтому старые заявки не теряют значения.
     */
    public static function sanitize_fields($rows): array
    {
        if (!is_array($rows)) {
            return [];
        }
        $out  = [];
        $used = array_fill_keys(self::SYSTEM_FIELDS, true);
        foreach ($rows as $index => $row) {
            if (!is_array($row) || $index === '__i__' || $index === '_') {
                continue;
            }
            $label = sanitize_text_field(isset($row['label']) && is_scalar($row['label']) ? (string) $row['label'] : '');
            if ($label === '') {
                continue;
            }
            $type = isset($row['type']) && is_string($row['type']) && isset(self::FIELD_TYPES[$row['type']]) ? $row['type'] : 'text';
            $options = in_array($type, ['select', 'radio'], true)
                ? implode("\n", self::option_lines(isset($row['options']) && is_scalar($row['options']) ? (string) $row['options'] : ''))
                : '';
            if (in_array($type, ['select', 'radio'], true) && $options === '') {
                $type = 'text'; // список без вариантов — обычная строка
            }

            $id = sanitize_key(isset($row['id']) && is_scalar($row['id']) ? (string) $row['id'] : '');
            if ($id === '' || isset($used[$id]) || strpos($id, 'wpd') === 0) {
                $base = 'f_' . substr(md5($label . '|' . count($out)), 0, 6);
                $id = $base;
                for ($n = 2; isset($used[$id]); $n++) {
                    $id = $base . '_' . $n;
                }
            }
            $used[$id] = true;

            $out[] = [
                'id'          => $id,
                'label'       => $label,
                'type'        => $type,
                'required'    => !empty($row['required']) ? 1 : 0,
                'options'     => $options,
                'placeholder' => sanitize_text_field(isset($row['placeholder']) && is_scalar($row['placeholder']) ? (string) $row['placeholder'] : ''),
                'in_list'     => !empty($row['in_list']) ? 1 : 0,
            ];
            if (count($out) >= self::MAX_FIELDS) {
                break;
            }
        }
        return $out;
    }

    public static function sanitize($input): array
    {
        $input = is_array($input) ? $input : [];
        $tab = isset($input['_tab']) && is_string($input['_tab']) ? $input['_tab'] : '';
        $sections = isset(self::tabs()[$tab]) ? self::tabs()[$tab]['sections'] : null;
        $current = self::get();
        $out = [];

        foreach (self::field_map() as $key => $field) {
            if ($sections !== null && !in_array($field['section'], $sections, true)) {
                $out[$key] = $current[$key];
                continue;
            }
            $has = array_key_exists($key, $input);
            $raw = $has ? $input[$key] : null;

            if ($field['type'] === 'checkbox') {
                $out[$key] = empty($raw) ? 0 : 1;
                continue;
            }
            if ($field['type'] === 'fields') {
                // Форма всегда отправляет маркер «_», поэтому пустой список — это []
                $out[$key] = $has ? self::sanitize_fields($raw) : $field['default'];
                continue;
            }
            if (!$has) {
                $out[$key] = $field['default'];
                continue;
            }
            $raw = is_scalar($raw) ? (string) $raw : '';
            switch ($field['type']) {
                case 'number':
                    $out[$key] = min($field['max'], max($field['min'], (int) $raw));
                    break;
                case 'select':
                    $out[$key] = array_key_exists($raw, $field['options']) ? $raw : $field['default'];
                    break;
                case 'datetime':
                    $out[$key] = WPD_Settings::normalize_datetime($raw);
                    break;
                case 'url':
                    $out[$key] = esc_url_raw(trim($raw));
                    break;
                case 'email':
                    $out[$key] = sanitize_email($raw);
                    break;
                case 'page':
                    $id = absint($raw);
                    $out[$key] = ($id && get_post_type($id) === 'page') ? $id : 0;
                    break;
                case 'textarea':
                    $out[$key] = sanitize_textarea_field($raw);
                    break;
                default:
                    $out[$key] = sanitize_text_field($raw);
            }
        }

        if (($sections === null || in_array('reg_main', $sections, true))
            && $out['period'] === 'dates' && $out['start'] !== '' && $out['end'] !== ''
            && strcmp($out['end'], $out['start']) <= 0
            && function_exists('add_settings_error')
            && !in_array('wpd_reg_dates', array_column(get_settings_errors(self::OPTION), 'code'), true)) {
            add_settings_error(self::OPTION, 'wpd_reg_dates', 'Окончание приёма не позже начала — форма будет закрыта.', 'warning');
        }
        return $out;
    }

    public static function register_setting(): void
    {
        register_setting(self::GROUP, self::OPTION, [
            'type'              => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize'],
            'default'           => self::defaults(),
        ]);
    }

    public static function url(string $tab = ''): string
    {
        $url = admin_url('admin.php?page=' . self::PAGE);
        return ($tab !== '' && isset(self::tabs()[$tab])) ? $url . '&tab=' . $tab : $url;
    }

    public static function current_tab(): string
    {
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        return isset(self::tabs()[$tab]) ? $tab : (string) array_key_first(self::tabs());
    }

    public static function add_menu(): void
    {
        add_submenu_page(WPD_Settings::PAGE, 'Олимпиада — регистрация', 'Регистрация', 'manage_options', self::PAGE, [__CLASS__, 'render_page']);
    }

    public static function enqueue_admin(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification
        if (!isset($_GET['page']) || $_GET['page'] !== self::PAGE) {
            return;
        }
        // Повторяющиеся поля и их стили — общие со страницей настроек
        wp_enqueue_style('wpd-settings', WPD_URL . 'assets/css/wpd-settings.css', [], WPD_VERSION);
        wp_enqueue_script('wpd-settings', WPD_URL . 'assets/js/wpd-settings.js', ['jquery'], WPD_VERSION, true);
        wp_localize_script('wpd-settings', 'wpdSettings', ['remove' => 'Удалить поле?']);
        // «Варианты» нужны только списку и выбору одного варианта
        wp_add_inline_script('wpd-settings', "jQuery(function ($) { $(document).on('change', '[data-wpd-reg-type]', function () {"
            . " $(this).closest('[data-wpd-rep-row]').find('[data-wpd-reg-options]').prop('hidden', ['select', 'radio'].indexOf(this.value) < 0); }); });");
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $tabs = self::tabs();
        $current = self::current_tab();
        $o = self::get();
        $map = self::field_map();
        ?>
        <div class="wrap wpd-settings wpd-reg-settings">
            <h1>Регистрация участников</h1>
            <?php settings_errors(self::OPTION); ?>
            <?php settings_errors('general'); ?>
            <nav class="nav-tab-wrapper wp-clearfix" aria-label="Разделы настроек регистрации">
                <?php foreach ($tabs as $id => $tab) : ?>
                    <a href="<?php echo esc_url(self::url($id)); ?>" class="nav-tab<?php echo $id === $current ? ' nav-tab-active' : ''; ?>"
                       <?php echo $id === $current ? 'aria-current="page"' : ''; ?>><?php echo esc_html($tab['title']); ?></a>
                <?php endforeach; ?>
            </nav>
            <div class="wpd-reg-howto">
                <p><strong>Как подключить.</strong> Создайте страницу с формой — шорткод <code>[wpd_registration]</code> или блок
                    «Олимпиада: регистрация» — и страницу личного кабинета — <code>[wpd_cabinet]</code> или блок «Олимпиада: личный кабинет».
                    Выберите обе страницы на вкладке «Приём заявок». Заявки — в разделе
                    <a href="<?php echo esc_url(admin_url('edit.php?post_type=' . self::CPT)); ?>">«Участники»</a>.</p>
                <?php $st = self::status($o); ?>
                <p class="wpd-reg-state wpd-reg-state--<?php echo $st['open'] ? 'open' : 'closed'; ?>">
                    <?php echo esc_html($st['open'] ? 'Сейчас приём заявок открыт.' : 'Сейчас приём заявок закрыт: ' . self::reason_label($st['reason']) . '.'); ?>
                    <?php echo esc_html('Заявок: ' . self::count_applications() . ($o['limit'] ? ' из ' . (int) $o['limit'] . ' мест' : '') . '.'); ?>
                </p>
            </div>
            <form method="post" action="options.php">
                <?php
                settings_fields(self::GROUP);
                printf('<input type="hidden" name="%s[_tab]" value="%s">', esc_attr(self::OPTION), esc_attr($current));
                foreach ($tabs[$current]['sections'] as $section) {
                    echo '<h2>' . esc_html(self::sections()[$section]) . '</h2><table class="form-table" role="presentation">';
                    foreach ($map as $key => $field) {
                        if ($field['section'] !== $section) {
                            continue;
                        }
                        $for = in_array($field['type'], ['checkbox', 'fields'], true) ? '' : ' for="wpd_reg_' . esc_attr($key) . '"';
                        echo '<tr><th scope="row"><label' . $for . '>' . esc_html($field['label']) . '</label></th><td>'; // phpcs:ignore WordPress.Security.EscapeOutput
                        self::render_setting($key, $field, $o[$key]);
                        echo '</td></tr>';
                    }
                    echo '</table>';
                }
                submit_button('Сохранить');
                ?>
            </form>
        </div>
        <?php
    }

    private static function render_setting(string $key, array $field, $value): void
    {
        $name = self::OPTION . '[' . $key . ']';
        $id = 'wpd_reg_' . $key;
        switch ($field['type']) {
            case 'checkbox':
                printf('<label><input type="checkbox" name="%s" value="1"%s> %s</label>', esc_attr($name), checked((int) $value, 1, false), esc_html($field['label']));
                break;
            case 'select':
                echo '<select id="' . esc_attr($id) . '" name="' . esc_attr($name) . '">';
                foreach ($field['options'] as $k => $label) {
                    printf('<option value="%s"%s>%s</option>', esc_attr($k), selected((string) $value, (string) $k, false), esc_html($label));
                }
                echo '</select>';
                break;
            case 'number':
                printf('<input type="number" id="%s" name="%s" value="%d" min="%d" max="%d" class="small-text">', esc_attr($id), esc_attr($name), (int) $value, (int) $field['min'], (int) $field['max']);
                break;
            case 'datetime':
                printf('<input type="datetime-local" id="%s" name="%s" value="%s"> <span class="description">%s</span>', esc_attr($id), esc_attr($name),
                    esc_attr(str_replace(' ', 'T', (string) $value)), esc_html(WPD_Settings::countdown_tz_label()));
                break;
            case 'textarea':
                printf('<textarea id="%s" name="%s" rows="%d" class="large-text">%s</textarea>', esc_attr($id), esc_attr($name),
                    strpos($key, 'body') !== false ? 8 : 3, esc_textarea((string) $value));
                break;
            case 'page':
                $dropdown = wp_dropdown_pages([
                    'echo' => 0, 'name' => $name, 'id' => $id, 'selected' => (int) $value,
                    'show_option_none' => '— не выбрана —', 'option_none_value' => '0', 'post_status' => ['publish', 'private', 'draft'],
                ]);
                echo $dropdown ? $dropdown : '<em>Страниц пока нет.</em>'; // phpcs:ignore WordPress.Security.EscapeOutput
                if ((int) $value && get_post_status((int) $value)) {
                    printf(' <a href="%s" target="_blank">Открыть</a>', esc_url(get_permalink((int) $value)));
                }
                break;
            case 'fields':
                self::render_fields_builder(is_array($value) ? $value : []);
                break;
            default:
                printf('<input type="%s" id="%s" name="%s" value="%s" class="regular-text">', $field['type'] === 'url' ? 'url' : ($field['type'] === 'email' ? 'email' : 'text'),
                    esc_attr($id), esc_attr($name), esc_attr((string) $value));
        }
        if (!empty($field['desc'])) {
            echo '<p class="description">' . esc_html($field['desc']) . '</p>';
        }
    }

    /** Конструктор полей: та же разметка повторителя, что на странице настроек (wpd-settings.js). */
    private static function render_fields_builder(array $rows): void
    {
        echo '<div class="wpd-reg-system"><span class="dashicons dashicons-lock" aria-hidden="true"></span> ФИО участника · E-mail — обязательные поля, всегда первые</div>';
        printf('<div class="wpd-rep" data-wpd-rep="fields" data-max="%1$d" data-next="%2$d"><input type="hidden" name="%3$s[fields][_]" value=""><div class="wpd-rep__list" data-wpd-rep-list>',
            self::MAX_FIELDS, count($rows), esc_attr(self::OPTION));
        foreach (array_values($rows) as $i => $row) {
            echo self::builder_row((string) $i, is_array($row) ? $row : []); // phpcs:ignore WordPress.Security.EscapeOutput
        }
        echo '</div><template data-wpd-rep-tpl>' . self::builder_row('__i__', []) . '</template>'; // phpcs:ignore WordPress.Security.EscapeOutput
        printf('<p><button type="button" class="button" data-wpd-rep-add%2$s>+ Добавить поле</button> <span class="description">не больше %1$d</span></p></div>',
            self::MAX_FIELDS, count($rows) >= self::MAX_FIELDS ? ' disabled' : '');
    }

    private static function builder_row(string $i, array $row): string
    {
        $base = self::OPTION . '[fields][' . $i . ']';
        $v = function ($k) use ($row) {
            return isset($row[$k]) && is_scalar($row[$k]) ? (string) $row[$k] : '';
        };
        $id = function ($k) use ($i) {
            return 'wpd_reg_f_' . $i . '_' . $k;
        };
        $type = $v('type') !== '' ? $v('type') : 'text';
        $opts = '';
        foreach (self::FIELD_TYPES as $k => $label) {
            $opts .= sprintf('<option value="%s"%s>%s</option>', esc_attr($k), selected($type, $k, false), esc_html($label));
        }
        return '<div class="wpd-rep__row" data-wpd-rep-row>'
            . '<div class="wpd-rep__head"><strong class="wpd-rep__title">Поле <span data-wpd-rep-num>' . ($i === '__i__' ? '' : esc_html((string) ((int) $i + 1))) . '</span>'
            . ($v('label') !== '' ? ' — ' . esc_html($v('label')) : '') . '</strong>'
            . '<span class="wpd-rep__tools">'
            . '<button type="button" class="button-link" data-wpd-rep-move="-1" aria-label="Выше"><span class="dashicons dashicons-arrow-up-alt2"></span></button>'
            . '<button type="button" class="button-link" data-wpd-rep-move="1" aria-label="Ниже"><span class="dashicons dashicons-arrow-down-alt2"></span></button>'
            . '<button type="button" class="button-link button-link-delete" data-wpd-rep-remove aria-label="Удалить"><span class="dashicons dashicons-trash"></span></button>'
            . '</span></div><div class="wpd-rep__fields">'
            . sprintf('<input type="hidden" name="%s[id]" value="%s">', esc_attr($base), esc_attr($v('id')))
            . sprintf('<label class="wpd-rep__f" for="%1$s"><span>Название</span><input type="text" id="%1$s" name="%2$s[label]" value="%3$s" class="regular-text"></label>',
                esc_attr($id('label')), esc_attr($base), esc_attr($v('label')))
            . sprintf('<label class="wpd-rep__f" for="%1$s"><span>Тип</span><select id="%1$s" name="%2$s[type]" data-wpd-reg-type>%3$s</select></label>', esc_attr($id('type')), esc_attr($base), $opts)
            . sprintf('<label class="wpd-rep__f" for="%1$s"><span>Подсказка в поле</span><input type="text" id="%1$s" name="%2$s[placeholder]" value="%3$s" class="regular-text"></label>',
                esc_attr($id('placeholder')), esc_attr($base), esc_attr($v('placeholder')))
            . sprintf('<label class="wpd-rep__f wpd-rep__f--wide" for="%1$s" data-wpd-reg-options%4$s><span>Варианты (для списка и выбора одного варианта), по одному на строку</span><textarea id="%1$s" name="%2$s[options]" rows="3" class="large-text">%3$s</textarea></label>',
                esc_attr($id('options')), esc_attr($base), esc_textarea($v('options')), in_array($type, ['select', 'radio'], true) ? '' : ' hidden')
            . sprintf('<label class="wpd-rep__f wpd-rep__f--check"><input type="checkbox" name="%s[required]" value="1"%s> Обязательное</label>', esc_attr($base), checked($v('required'), '1', false))
            . sprintf('<label class="wpd-rep__f wpd-rep__f--check"><input type="checkbox" name="%s[in_list]" value="1"%s> В списке участников</label>', esc_attr($base), checked($v('in_list'), '1', false))
            . '</div></div>';
    }

    /* ---------------------------------------------------------------
     * Типы данных, роль, блоки
     * ------------------------------------------------------------- */

    public static function register_types(): void
    {
        if (!get_role(self::ROLE)) {
            add_role(self::ROLE, 'Участник олимпиады', ['read' => true]);
        }
        $cap = 'manage_options';
        register_post_type(self::CPT, [
            'labels' => [
                'name'               => 'Участники',
                'singular_name'      => 'Заявка',
                'menu_name'          => 'Участники',
                'all_items'          => 'Участники',
                'edit_item'          => 'Заявка участника',
                'view_item'          => 'Заявка участника',
                'search_items'       => 'Найти заявку',
                'not_found'          => 'Заявок пока нет',
                'not_found_in_trash' => 'В корзине заявок нет',
                'item_updated'       => 'Заявка сохранена.',
            ],
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => WPD_Settings::PAGE,
            'show_in_rest'        => false,
            'show_in_nav_menus'   => false,
            'show_in_admin_bar'   => false,
            'exclude_from_search' => true,
            'publicly_queryable'  => false,
            'has_archive'         => false,
            'rewrite'             => false,
            'query_var'           => false,
            'supports'            => ['title'],
            'delete_with_user'    => false,
            'map_meta_cap'        => true,
            'capability_type'     => 'post',
            'capabilities'        => [
                'edit_posts' => $cap, 'edit_others_posts' => $cap, 'edit_published_posts' => $cap, 'edit_private_posts' => $cap,
                'publish_posts' => $cap, 'read_private_posts' => $cap, 'delete_posts' => $cap, 'delete_others_posts' => $cap,
                'delete_published_posts' => $cap, 'delete_private_posts' => $cap, 'create_posts' => 'do_not_allow',
            ],
        ]);
    }

    public static function block_defs(): array
    {
        return [
            'registration' => [
                'title'       => 'Олимпиада: регистрация',
                'description' => 'Форма заявки на участие: поля из настроек регистрации, согласие на обработку данных, сроки и лимит мест.',
                'icon'        => 'id-alt',
                'keywords'    => ['регистрация', 'заявка', 'форма', 'участник'],
                'attributes'  => ['title' => ['type' => 'string'], 'intro' => ['type' => 'string'], 'button' => ['type' => 'string']],
                'render'      => [__CLASS__, 'render_registration_block'],
            ],
            'cabinet' => [
                'title'       => 'Олимпиада: личный кабинет',
                'description' => 'Вход для участника, статус и данные заявки, изменение заявки.',
                'icon'        => 'admin-users',
                'keywords'    => ['кабинет', 'вход', 'участник', 'статус'],
                'attributes'  => ['title' => ['type' => 'string']],
                'render'      => [__CLASS__, 'render_cabinet_block'],
            ],
        ];
    }

    public static function register_blocks(): void
    {
        if (!function_exists('register_block_type')) {
            return;
        }
        wp_register_script('wpd-reg-blocks', WPD_URL . 'assets/js/wpd-reg-blocks.js',
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render'], WPD_VERSION, true);
        if (!wp_style_is('wpd-editor', 'registered')) {
            wp_register_style('wpd-editor', WPD_URL . 'assets/css/wpd-editor.css', ['wpd-design', 'dashicons'], WPD_VERSION);
        }
        $client = [];
        $o = self::get();
        foreach (self::block_defs() as $slug => $def) {
            register_block_type(WPD_Blocks::NS . '/' . $slug, [
                'api_version'     => 3,
                'title'           => $def['title'],
                'category'        => WPD_Blocks::NS,
                'attributes'      => $def['attributes'],
                'supports'        => ['html' => false, 'align' => ['wide', 'full']],
                'render_callback' => $def['render'],
                'editor_script'   => 'wpd-reg-blocks',
                'editor_style'    => 'wpd-editor',
            ]);
            $client[WPD_Blocks::NS . '/' . $slug] = [
                'title' => $def['title'], 'description' => $def['description'], 'icon' => $def['icon'],
                'keywords' => $def['keywords'], 'attributes' => $def['attributes'],
            ];
        }
        wp_add_inline_script('wpd-reg-blocks', 'window.wpdReg = ' . wp_json_encode([
            'blocks'      => $client,
            'category'    => WPD_Blocks::NS,
            'settingsUrl' => self::url(),
            'listUrl'     => admin_url('edit.php?post_type=' . self::CPT),
            'defaults'    => [
                WPD_Blocks::NS . '/registration' => ['title' => $o['title'], 'intro' => $o['intro'], 'button' => $o['button']],
                WPD_Blocks::NS . '/cabinet'      => ['title' => 'Личный кабинет'],
            ],
        ]) . ';', 'before');
    }

    /* ---------------------------------------------------------------
     * Участник: роль, админка, вход
     * ------------------------------------------------------------- */

    /** Пользователь — только участник (без прав редактора и выше). */
    public static function is_participant($user = null): bool
    {
        $user = $user instanceof WP_User ? $user : wp_get_current_user();
        return $user && $user->exists() && in_array(self::ROLE, (array) $user->roles, true) && !user_can($user, 'edit_posts');
    }

    public static function show_admin_bar($show)
    {
        return self::is_participant() ? false : $show;
    }

    public static function block_admin_for_participants(): void
    {
        if (!self::is_participant() || wp_doing_ajax() || (defined('DOING_CRON') && DOING_CRON)) {
            return;
        }
        wp_safe_redirect(self::cabinet_url() ?: home_url('/'));
        exit;
    }

    public static function login_redirect($to, $requested, $user)
    {
        if ($user instanceof WP_User && self::is_participant($user)) {
            $requested_is_admin = $requested === '' || strpos((string) $requested, admin_url()) === 0;
            return $requested_is_admin ? (self::cabinet_url() ?: home_url('/')) : $to;
        }
        return $to;
    }

    public static function cabinet_url(): string
    {
        $id = (int) self::get()['cabinet_page'];
        return $id && get_post_status($id) ? (string) get_permalink($id) : '';
    }

    public static function form_url(): string
    {
        $id = (int) self::get()['form_page'];
        return $id && get_post_status($id) ? (string) get_permalink($id) : '';
    }

    /** Нужен ли CSS: на странице шорткод или блок регистрации / кабинета. */
    public static function needs_assets($needed)
    {
        if ($needed || !is_singular()) {
            return $needed;
        }
        $post = get_queried_object();
        if (!$post instanceof WP_Post) {
            return $needed;
        }
        return has_shortcode($post->post_content, 'wpd_registration') || has_shortcode($post->post_content, 'wpd_cabinet')
            || strpos($post->post_content, '<!-- wp:' . WPD_Blocks::NS . '/registration') !== false
            || strpos($post->post_content, '<!-- wp:' . WPD_Blocks::NS . '/cabinet') !== false;
    }

    /* ---------------------------------------------------------------
     * Приём заявок: состояние, номера, поиск
     * ------------------------------------------------------------- */

    public static function now(): string
    {
        return (new DateTime('now', WPD_Settings::countdown_timezone()))->format('Y-m-d H:i');
    }

    /**
     * Открыт ли приём: ['open' => bool, 'reason' => ''|disabled|not_started|ended|full, 'start' => '', 'end' => '', 'left' => int|null].
     */
    public static function status(?array $r = null): array
    {
        $r = $r ?? self::get();
        $now = self::now();
        $start = '';
        $end = '';
        if ($r['period'] === 'dates') {
            $start = (string) $r['start'];
            $end = (string) $r['end'];
        } elseif ($r['period'] === 'countdown') {
            $end = (string) WPD_Settings::get()['countdown_date'];
        }
        $left = null;
        if ((int) $r['limit'] > 0) {
            $left = max(0, (int) $r['limit'] - self::count_applications(true));
        }

        $reason = '';
        if (empty($r['enabled'])) {
            $reason = 'disabled';
        } elseif ($start !== '' && strcmp($now, $start) < 0) {
            $reason = 'not_started';
        } elseif ($end !== '' && strcmp($now, $end) >= 0) {
            $reason = 'ended';
        } elseif ($left === 0) {
            $reason = 'full';
        }
        return ['open' => $reason === '', 'reason' => $reason, 'start' => $start, 'end' => $end, 'left' => $left];
    }

    public static function reason_label(string $reason): string
    {
        $labels = ['disabled' => 'выключен в настройках', 'not_started' => 'ещё не начался', 'ended' => 'срок истёк', 'full' => 'места закончились'];
        return $labels[$reason] ?? '';
    }

    /** Число заявок; $taken — только занимающие место (без отклонённых). */
    public static function count_applications(bool $taken = false): int
    {
        $args = ['post_type' => self::CPT, 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => false,
                 'suppress_filters' => true];
        if ($taken) {
            $args['meta_query'] = [['key' => '_wpd_status', 'value' => 'rejected', 'compare' => '!=']];
        }
        $q = new WP_Query($args);
        return (int) $q->found_posts;
    }

    public static function count_by_status(string $status): int
    {
        $q = new WP_Query(['post_type' => self::CPT, 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids',
                           'no_found_rows' => false, 'suppress_filters' => true,
                           'meta_query' => [['key' => '_wpd_status', 'value' => $status]]]);
        return (int) $q->found_posts;
    }

    private static function find_one(string $key, string $value): ?WP_Post
    {
        if ($value === '' || $value === '0') {
            return null;
        }
        $posts = get_posts(['post_type' => self::CPT, 'post_status' => 'publish', 'numberposts' => 1, 'suppress_filters' => true,
                            'meta_query' => [['key' => $key, 'value' => $value]]]);
        return $posts ? $posts[0] : null;
    }

    public static function find_by_email(string $email): ?WP_Post
    {
        return self::find_one('_wpd_email', strtolower(trim($email)));
    }

    public static function user_application(int $user_id): ?WP_Post
    {
        return $user_id ? self::find_one('_wpd_user', (string) $user_id) : null;
    }

    /** Следующий номер заявки: префикс и порядковый номер. Счётчик увеличивается одним запросом. */
    public static function next_number(): string
    {
        global $wpdb;
        if (get_option(self::COUNTER, null) === null) {
            add_option(self::COUNTER, 0, '', 'no');
        }
        // Сравнение с обменом: номер занят, только если счётчик не успел измениться другим запросом.
        // Две одновременные заявки не получат один номер ни в MySQL, ни в SQLite.
        $n = 0;
        for ($try = 0; $try < 25; $try++) {
            $cur = (int) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::COUNTER));
            $won = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                (string) ($cur + 1), self::COUNTER, (string) $cur));
            if ($won === 1) {
                $n = $cur + 1;
                break;
            }
            usleep(random_int(1000, 20000));
        }
        wp_cache_delete(self::COUNTER, 'options');
        wp_cache_delete('notoptions', 'options');
        if ($n < 1) { // счётчик недоступен — не теряем заявку
            $n = max(1, (int) get_option(self::COUNTER, 0) + 1);
            update_option(self::COUNTER, $n, false);
        }
        return self::get()['number_prefix'] . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    /** Все поля формы: ФИО и e-mail, затем поля из конструктора. */
    public static function form_fields(?array $r = null): array
    {
        $r = $r ?? self::get();
        $list = [
            ['id' => 'name', 'label' => 'ФИО участника', 'type' => 'text', 'required' => 1, 'options' => '', 'placeholder' => 'Фамилия Имя Отчество', 'in_list' => 1, 'system' => 1],
            ['id' => 'email', 'label' => 'E-mail', 'type' => 'email', 'required' => 1, 'options' => '', 'placeholder' => 'name@example.ru', 'in_list' => 1, 'system' => 1],
        ];
        foreach ((array) $r['fields'] as $f) {
            if (is_array($f) && !empty($f['id']) && !in_array($f['id'], self::SYSTEM_FIELDS, true)) {
                $list[] = $f + ['system' => 0];
            }
        }
        return $list;
    }

    /**
     * Проверка значений формы. Возвращает [$values, $errors]: значения очищены, ошибки — по id поля.
     */
    public static function validate(array $input, array $fields): array
    {
        $values = [];
        $errors = [];
        foreach ($fields as $f) {
            $id = $f['id'];
            $raw = isset($input[$id]) && is_scalar($input[$id]) ? trim((string) $input[$id]) : '';
            $error = '';
            switch ($f['type']) {
                case 'email':
                    $v = strtolower(sanitize_email($raw));
                    if ($raw !== '' && !is_email($v)) {
                        $error = 'Укажите корректный e-mail, например name@example.ru.';
                        $v = mb_substr(sanitize_text_field($raw), 0, 200); // показать введённое, чтобы исправить; с ошибкой не сохраняется
                    }
                    break;
                case 'tel':
                    $v = sanitize_text_field($raw);
                    $digits = preg_replace('/\D/', '', $v);
                    if ($v !== '' && (preg_match('/[^0-9+\-\s().]/', $v) || strlen($digits) < 10 || strlen($digits) > 15)) {
                        $error = 'Укажите номер телефона, например +7 900 000-00-00.';
                    }
                    break;
                case 'number':
                    $v = str_replace(',', '.', sanitize_text_field($raw));
                    if ($v !== '' && !is_numeric($v)) {
                        $error = 'Укажите число.';
                    }
                    break;
                case 'date':
                    $v = sanitize_text_field($raw);
                    $d = $v !== '' ? DateTime::createFromFormat('!Y-m-d', $v) : false;
                    if ($v !== '' && (!$d || $d->format('Y-m-d') !== $v)) {
                        $error = 'Укажите дату.';
                    }
                    break;
                case 'select':
                case 'radio':
                    $v = sanitize_text_field($raw);
                    if ($v !== '' && !in_array($v, self::option_lines((string) $f['options']), true)) {
                        $error = 'Выберите вариант из списка.';
                        $v = '';
                    }
                    break;
                case 'checkbox':
                    $v = ($raw !== '' && $raw !== '0') ? '1' : '';
                    break;
                case 'textarea':
                    $v = mb_substr(sanitize_textarea_field($raw), 0, 2000);
                    break;
                default:
                    $v = mb_substr(sanitize_text_field($raw), 0, 200);
            }
            if ($error === '' && !empty($f['required']) && $v === '') {
                $error = $f['type'] === 'checkbox' ? 'Отметьте этот пункт.'
                    : (in_array($f['type'], ['select', 'radio'], true) ? 'Выберите вариант.' : 'Заполните это поле.');
            }
            if ($id === 'name' && $error === '' && !preg_match('/\p{L}/u', $v)) {
                $error = 'Укажите фамилию и имя.';
            }
            $values[$id] = $v;
            if ($error !== '') {
                $errors[$id] = $error;
            }
        }
        return [$values, $errors];
    }

    /* ---------------------------------------------------------------
     * Защита от спама
     * ------------------------------------------------------------- */

    public static function form_token(?int $time = null): string
    {
        $time = $time ?? time();
        return $time . '.' . substr(wp_hash('wpd_reg|' . $time), 0, 16);
    }

    /** Метка времени формы: подпись верна, с момента показа прошло не меньше MIN_SECONDS и не больше MAX_FORM_AGE. */
    public static function check_token(string $token): bool
    {
        if (!preg_match('/^(\d{9,11})\.([a-f0-9]{16})$/', $token, $m)) {
            return false;
        }
        $age = time() - (int) $m[1];
        return hash_equals(substr(wp_hash('wpd_reg|' . $m[1]), 0, 16), $m[2])
            && $age >= (int) apply_filters('wpd_reg_min_seconds', self::MIN_SECONDS) && $age <= self::MAX_FORM_AGE;
    }

    private static function rate_key(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        return 'wpd_reg_rl_' . substr(wp_hash('ip|' . $ip), 0, 20);
    }

    public static function rate_limited(): bool
    {
        $limit = (int) self::get()['rate_limit'];
        return $limit > 0 && (int) get_transient(self::rate_key()) >= $limit;
    }

    private static function rate_hit(): void
    {
        if ((int) self::get()['rate_limit'] > 0) {
            set_transient(self::rate_key(), (int) get_transient(self::rate_key()) + 1, HOUR_IN_SECONDS);
        }
    }

    /* ---------------------------------------------------------------
     * Обработка форм (до вывода страницы, чтобы можно было войти и перенаправить)
     * ------------------------------------------------------------- */

    public static function handle_post(): void
    {
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['wpd_reg_action'])) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }
        $action = sanitize_key(wp_unslash($_POST['wpd_reg_action'])); // phpcs:ignore WordPress.Security.NonceVerification
        if ($action === 'register') {
            self::process_register();
        } elseif ($action === 'edit') {
            self::process_edit();
        }
    }

    private static function input(): array
    {
        // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- значения очищаются в validate()
        $raw = isset($_POST['wpd_reg']) && is_array($_POST['wpd_reg']) ? wp_unslash($_POST['wpd_reg']) : [];
        return is_array($raw) ? $raw : [];
    }

    /** Адрес текущей страницы без служебных параметров регистрации. */
    private static function current_url(): string
    {
        $url = is_singular() ? (string) get_permalink(get_queried_object_id()) : home_url(add_query_arg([]));
        return remove_query_arg(['wpd_reg', 't', 'wpd_edit'], $url);
    }

    public static function process_register(): void
    {
        $r = self::get();
        $st = self::status($r);
        self::$state = ['form' => 'register', 'values' => [], 'errors' => [], 'notice' => ''];
        $input = self::input();
        self::$state['values'] = array_map(function ($v) {
            return is_scalar($v) ? sanitize_textarea_field((string) $v) : '';
        }, $input);

        if (!$st['open']) {
            self::$state['notice'] = self::closed_message($st, $r);
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification -- форма для гостей: защита — ловушка, метка времени и лимит
        $hp = isset($_POST['wpd_hp']) ? (string) wp_unslash($_POST['wpd_hp']) : '';
        $ts = isset($_POST['wpd_ts']) ? (string) wp_unslash($_POST['wpd_ts']) : '';
        $consent = !empty($_POST['wpd_consent']);
        // phpcs:enable
        if ($hp !== '' || !self::check_token($ts)) {
            self::$state['errors']['_form'] = 'Не удалось отправить заявку: форма заполнена слишком быстро или устарела. Обновите страницу и попробуйте ещё раз.';
            return;
        }
        if (self::rate_limited()) {
            self::$state['errors']['_form'] = 'Слишком много заявок с вашего адреса. Попробуйте через час.';
            return;
        }

        $user = wp_get_current_user();
        $logged = $user->exists();
        if ($logged && self::user_application($user->ID)) {
            return; // форма покажет «Вы уже подали заявку»
        }

        [$values, $errors] = self::validate($input, self::form_fields($r));
        if ($logged && $r['accounts']) {
            $values['email'] = strtolower($user->user_email);
            unset($errors['email']);
        }
        if (!$consent) {
            $errors['_consent'] = 'Нужно согласие на обработку персональных данных.';
        }
        if (!isset($errors['email']) && $values['email'] !== '') {
            if (self::find_by_email($values['email'])) {
                $errors['email'] = 'Заявка с этим e-mail уже подана.' . ($r['accounts'] ? ' Войдите в личный кабинет, чтобы посмотреть её.' : '');
            } elseif ($r['accounts'] && !$logged && email_exists($values['email'])) {
                $errors['email'] = 'На сайте уже есть пользователь с этим e-mail. Войдите, чтобы подать заявку.';
            }
        }
        if ($errors) {
            self::$state['values'] = $values;
            self::$state['errors'] = $errors;
            return;
        }

        $uid = 0;
        $new_user = false;
        if ($r['accounts']) {
            if ($logged) {
                $uid = (int) $user->ID;
            } else {
                $created = self::create_user($values);
                if (is_wp_error($created)) {
                    self::$state['values'] = $values;
                    self::$state['errors']['_form'] = 'Не удалось создать аккаунт: ' . $created->get_error_message();
                    return;
                }
                $uid = (int) $created;
                $new_user = true;
            }
        }

        $post_id = self::create_application($values, $uid);
        if (!$post_id) {
            self::$state['values'] = $values;
            self::$state['errors']['_form'] = 'Не удалось сохранить заявку. Попробуйте ещё раз.';
            return;
        }
        self::rate_hit();

        $app = get_post($post_id);
        if ($r['mail_user']) {
            self::send_mail($app, 'user');
        }
        if ($r['mail_admin']) {
            self::send_mail($app, 'admin');
        }
        if ($new_user) {
            wp_new_user_notification($uid, null, 'user'); // письмо WordPress со ссылкой для установки пароля
            if ($r['autologin']) {
                wp_set_current_user($uid);
                wp_set_auth_cookie($uid, true);
            }
        }
        do_action('wpd_reg_submitted', $post_id, $values, $uid);

        $token = wp_generate_password(20, false);
        set_transient('wpd_reg_done_' . $token, $post_id, 30 * MINUTE_IN_SECONDS);
        $target = ($r['accounts'] && self::cabinet_url()) ? self::cabinet_url() : self::current_url();
        wp_safe_redirect(add_query_arg(['wpd_reg' => 'done', 't' => $token], $target) . '#' . (($r['accounts'] && self::cabinet_url()) ? 'wpd-cabinet' : 'wpd-reg'));
        exit;
    }

    private static function create_user(array $values)
    {
        $base = sanitize_user((string) strstr($values['email'], '@', true), true);
        $base = $base !== '' ? substr($base, 0, 50) : 'participant';
        $login = $base;
        for ($i = 2; username_exists($login); $i++) {
            $login = $base . $i;
        }
        $parts = preg_split('/\s+/u', trim($values['name']));
        return wp_insert_user([
            'user_login'   => $login,
            'user_email'   => $values['email'],
            'user_pass'    => wp_generate_password(24),
            'display_name' => $values['name'],
            'nickname'     => $values['name'],
            'last_name'    => $parts[0] ?? '',
            'first_name'   => $parts[1] ?? '',
            'role'         => self::ROLE,
            'show_admin_bar_front' => 'false',
        ]);
    }

    /** Текст для поиска в «Участниках»: номер, e-mail и значения полей. */
    private static function search_text(string $number, array $values): string
    {
        return mb_substr(trim($number . ' ' . implode(' ', array_filter(array_map('strval', $values)))), 0, 2000);
    }

    public static function create_application(array $values, int $uid = 0): int
    {
        $number = self::next_number();
        $extra = array_diff_key($values, array_flip(self::SYSTEM_FIELDS));
        $id = wp_insert_post([
            'post_type'    => self::CPT,
            'post_status'  => 'publish',
            'post_title'   => $values['name'],
            'post_author'  => $uid,
            'post_excerpt' => self::search_text($number, $values),
        ]);
        if (!$id || is_wp_error($id)) {
            return 0;
        }
        update_post_meta($id, '_wpd_number', $number);
        update_post_meta($id, '_wpd_status', 'new');
        update_post_meta($id, '_wpd_email', strtolower($values['email']));
        update_post_meta($id, '_wpd_fields', $extra);
        update_post_meta($id, '_wpd_user', $uid);
        return (int) $id;
    }

    /** Обновление данных заявки (кабинет участника или админка). */
    public static function update_application(int $id, array $values, bool $change_email = false): void
    {
        $number = (string) get_post_meta($id, '_wpd_number', true);
        if ($change_email && !empty($values['email'])) {
            update_post_meta($id, '_wpd_email', strtolower($values['email']));
        }
        $values['email'] = (string) get_post_meta($id, '_wpd_email', true);
        update_post_meta($id, '_wpd_fields', array_diff_key($values, array_flip(self::SYSTEM_FIELDS)));
        wp_update_post(['ID' => $id, 'post_title' => $values['name'], 'post_excerpt' => self::search_text($number, $values)]);
    }

    public static function app_status(WP_Post $app): string
    {
        $s = (string) get_post_meta($app->ID, '_wpd_status', true);
        return isset(self::STATUSES[$s]) ? $s : 'new';
    }

    /** Смена статуса; письмо участнику — если включено в настройках и $notify. */
    public static function set_status(int $id, string $status, bool $notify = true): bool
    {
        $app = get_post($id);
        if (!$app || $app->post_type !== self::CPT || !isset(self::STATUSES[$status]) || self::app_status($app) === $status) {
            return false;
        }
        $old = self::app_status($app);
        update_post_meta($id, '_wpd_status', $status);
        if ($notify && self::get()['mail_status']) {
            self::send_mail($app, 'status');
        }
        do_action('wpd_reg_status_changed', $id, $status, $old);
        return true;
    }

    /** Значения заявки по id полей (включая ФИО и e-mail). */
    public static function app_values(WP_Post $app): array
    {
        $fields = get_post_meta($app->ID, '_wpd_fields', true);
        return array_merge(is_array($fields) ? $fields : [], [
            'name'  => $app->post_title,
            'email' => (string) get_post_meta($app->ID, '_wpd_email', true),
        ]);
    }

    public static function can_edit(WP_Post $app): bool
    {
        $r = self::get();
        $st = self::status($r);
        return !empty($r['allow_edit']) && self::app_status($app) === 'new' && ($st['open'] || $st['reason'] === 'full');
    }

    public static function process_edit(): void
    {
        $user = wp_get_current_user();
        self::$state = ['form' => 'edit', 'values' => [], 'errors' => [], 'notice' => ''];
        if (!$user->exists()) {
            return;
        }
        $nonce = isset($_POST['_wpd_nonce']) ? sanitize_text_field(wp_unslash($_POST['_wpd_nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'wpd_reg_edit')) {
            self::$state['errors']['_form'] = 'Сессия устарела. Обновите страницу и попробуйте ещё раз.';
            return;
        }
        $app = self::user_application((int) $user->ID);
        if (!$app || !self::can_edit($app)) {
            self::$state['notice'] = 'Заявку уже нельзя изменить.';
            return;
        }
        $fields = self::form_fields();
        [$values, $errors] = self::validate(self::input(), $fields);
        $values['email'] = (string) get_post_meta($app->ID, '_wpd_email', true);
        unset($errors['email']);
        if ($errors) {
            self::$state['values'] = $values;
            self::$state['errors'] = $errors;
            return;
        }
        self::update_application((int) $app->ID, $values);
        wp_update_user(['ID' => $user->ID, 'display_name' => $values['name']]);
        do_action('wpd_reg_updated', $app->ID, $values);
        wp_safe_redirect(add_query_arg('wpd_reg', 'saved', self::current_url()) . '#wpd-cabinet');
        exit;
    }

    /* ---------------------------------------------------------------
     * Письма
     * ------------------------------------------------------------- */

    public static function fields_text(WP_Post $app): string
    {
        $values = self::app_values($app);
        $lines = [];
        foreach (self::form_fields() as $f) {
            $v = $values[$f['id']] ?? '';
            if ($f['type'] === 'checkbox') {
                $v = $v === '1' ? 'да' : 'нет';
            }
            $lines[] = $f['label'] . ': ' . ($v !== '' ? $v : '—');
        }
        return implode("\n", $lines);
    }

    public static function mail_vars(WP_Post $app): array
    {
        $values = self::app_values($app);
        return [
            '{name}'    => $values['name'],
            '{email}'   => $values['email'],
            '{number}'  => (string) get_post_meta($app->ID, '_wpd_number', true),
            '{status}'  => self::STATUSES[self::app_status($app)],
            '{fields}'  => self::fields_text($app),
            '{cabinet}' => self::cabinet_url() ?: home_url('/'),
            '{site}'    => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES) . ' — ' . home_url('/'),
        ];
    }

    /** $kind: user — заявка принята, status — смена статуса, admin — уведомление организатору. */
    public static function send_mail(WP_Post $app, string $kind): bool
    {
        $r = self::get();
        $vars = self::mail_vars($app);
        if ($kind === 'admin') {
            $to = is_email($r['admin_email']) ? $r['admin_email'] : get_option('admin_email');
            $subject = 'Новая заявка ' . $vars['{number}'] . ': ' . $vars['{name}'];
            $body = "Новая заявка на участие в олимпиаде.\n\n" . $vars['{fields}'] . "\n\nОткрыть заявку: "
                . admin_url('post.php?post=' . $app->ID . '&action=edit') . "\nВсе участники: " . admin_url('edit.php?post_type=' . self::CPT);
            $headers = is_email($vars['{email}']) ? ['Reply-To: ' . $vars['{email}']] : [];
        } else {
            $to = $vars['{email}'];
            $subject = strtr($kind === 'status' ? $r['mail_status_subject'] : $r['mail_user_subject'], $vars);
            $body = strtr($kind === 'status' ? $r['mail_status_body'] : $r['mail_user_body'], $vars);
            $headers = [];
        }
        if (!is_email($to)) {
            return false;
        }
        $subject = trim(preg_replace('/[\r\n]+/', ' ', $subject));
        return (bool) wp_mail($to, $subject, $body, $headers);
    }

    /* ---------------------------------------------------------------
     * Вывод: форма и личный кабинет
     * ------------------------------------------------------------- */

    public static function sc_registration($atts = []): string
    {
        $atts = shortcode_atts(['title' => null, 'intro' => null, 'button' => null], is_array($atts) ? $atts : [], 'wpd_registration');
        return self::render_form($atts);
    }

    public static function sc_cabinet($atts = []): string
    {
        $atts = shortcode_atts(['title' => null], is_array($atts) ? $atts : [], 'wpd_cabinet');
        return self::render_cabinet($atts);
    }

    public static function render_registration_block($a): string
    {
        $a = is_array($a) ? $a : [];
        $html = self::render_form([
            'title'  => isset($a['title']) && $a['title'] !== '' ? (string) $a['title'] : null,
            'intro'  => isset($a['intro']) && $a['intro'] !== '' ? (string) $a['intro'] : null,
            'button' => isset($a['button']) && $a['button'] !== '' ? (string) $a['button'] : null,
        ]);
        return self::align($html, $a);
    }

    public static function render_cabinet_block($a): string
    {
        $a = is_array($a) ? $a : [];
        $html = self::render_cabinet(['title' => isset($a['title']) && $a['title'] !== '' ? (string) $a['title'] : null]);
        return self::align($html, $a);
    }

    private static function align(string $html, array $a): string
    {
        if (!empty($a['align']) && in_array($a['align'], ['wide', 'full'], true)) {
            $html = preg_replace('/^<div class="wpd-page/', '<div class="align' . $a['align'] . ' wpd-page', $html, 1);
        }
        return $html;
    }

    private static function closed_message(array $st, array $r): string
    {
        switch ($st['reason']) {
            case 'not_started':
                $d = DateTime::createFromFormat('Y-m-d H:i', $st['start'], WPD_Settings::countdown_timezone());
                $when = $d ? self::human_date($d->getTimestamp(), WPD_Settings::countdown_timezone()) . ' ' . WPD_Settings::countdown_tz_label() : $st['start'];
                return str_replace('{start}', trim($when), (string) $r['not_started']);
            case 'full':
                return (string) $r['full'];
            default:
                return (string) $r['closed'];
        }
    }

    private static function done_app(): ?WP_Post
    {
        // phpcs:disable WordPress.Security.NonceVerification
        if (!isset($_GET['wpd_reg'], $_GET['t']) || $_GET['wpd_reg'] !== 'done') {
            return null;
        }
        $id = (int) get_transient('wpd_reg_done_' . sanitize_key(wp_unslash($_GET['t'])));
        // phpcs:enable
        $app = $id ? get_post($id) : null;
        return ($app && $app->post_type === self::CPT) ? $app : null;
    }

    private static function alert(string $text, string $kind = 'info', bool $html = false): string
    {
        $role = $kind === 'error' ? ' role="alert"' : ' role="status"';
        return '<div class="wpd-reg__alert wpd-reg__alert--' . esc_attr($kind) . '"' . $role . '>' . ($html ? $text : esc_html($text)) . '</div>';
    }

    private static function status_badge(string $status): string
    {
        return '<span class="wpd-status wpd-status--' . esc_attr($status) . '">' . esc_html(self::STATUSES[$status]) . '</span>';
    }

    private static function privacy_url(array $r): string
    {
        if ($r['privacy_url'] !== '') {
            return (string) $r['privacy_url'];
        }
        return function_exists('get_privacy_policy_url') ? (string) get_privacy_policy_url() : '';
    }

    public static function render_form(array $args = []): string
    {
        $r = self::get();
        $title = $args['title'] ?? $r['title'];
        $intro = $args['intro'] ?? $r['intro'];
        $button = $args['button'] ?? $r['button'];
        $html = '<section class="wpd-reg" id="wpd-reg">' . ($title !== '' ? '<h2 class="wpd-h2">' . esc_html($title) . '</h2>' : '');

        $done = self::done_app();
        if ($done) {
            $vars = self::mail_vars($done);
            $msg = esc_html(strtr((string) $r['success'], ['{name}' => $vars['{name}'], '{email}' => $vars['{email}'], '{number}' => $vars['{number}']]));
            if ($r['accounts'] && self::cabinet_url()) {
                $msg .= ' <a href="' . esc_url(self::cabinet_url()) . '">Личный кабинет</a>';
            }
            return WPD_Renderer::wrap($html . self::alert($msg, 'success', true) . '</section>', 'wpd-page--reg');
        }

        $state = (self::$state && self::$state['form'] === 'register') ? self::$state : ['values' => [], 'errors' => [], 'notice' => ''];
        $user = wp_get_current_user();
        $existing = $user->exists() ? self::user_application((int) $user->ID) : null;
        if ($existing) {
            $text = 'Вы уже подали заявку ' . get_post_meta($existing->ID, '_wpd_number', true) . '. Статус: ' . self::STATUSES[self::app_status($existing)] . '.';
            $link = self::cabinet_url() ? ' <a href="' . esc_url(self::cabinet_url()) . '">Перейти в личный кабинет</a>' : '';
            return WPD_Renderer::wrap($html . self::alert(esc_html($text) . $link, 'info', true) . '</section>', 'wpd-page--reg');
        }

        $st = self::status($r);
        if ($state['notice'] !== '' || !$st['open']) {
            $notice = $state['notice'] !== '' ? $state['notice'] : self::closed_message($st, $r);
            return WPD_Renderer::wrap($html . self::alert($notice, 'closed') . '</section>', 'wpd-page--reg');
        }

        if ($intro !== '') {
            $html .= '<p class="wpd-reg__intro">' . nl2br(esc_html($intro)) . '</p>';
        }
        if ($st['left'] !== null && $r['show_left']) {
            $html .= '<p class="wpd-reg__left">Осталось мест: <strong>' . (int) $st['left'] . '</strong></p>';
        }
        if ($r['accounts'] && !$user->exists()) {
            $html .= '<p class="wpd-reg__login">Уже подавали заявку? <a href="' . esc_url(wp_login_url(self::cabinet_url() ?: self::current_url())) . '">Войдите</a>.</p>';
        }

        $errors = $state['errors'];
        $values = $state['values'];
        if ($user->exists()) {
            $values += ['name' => $user->display_name, 'email' => $user->user_email];
        }
        $html .= '<form class="wpd-reg__form" method="post" action="' . esc_url(self::current_url()) . '#wpd-reg">'
            . '<input type="hidden" name="wpd_reg_action" value="register">'
            . '<input type="hidden" name="wpd_ts" value="' . esc_attr(self::form_token()) . '">';
        if ($errors) {
            $count = count($errors);
            $html .= self::alert(isset($errors['_form']) ? $errors['_form'] : 'Проверьте форму: ' . $count . ' ' . self::plural($count, 'поле заполнено', 'поля заполнены', 'полей заполнено') . ' неверно.', 'error');
        }
        $html .= '<div class="wpd-reg__grid">';
        foreach (self::form_fields($r) as $f) {
            $locked = $f['id'] === 'email' && $user->exists() && $r['accounts'];
            $html .= self::field_html($f, (string) ($values[$f['id']] ?? ''), $errors[$f['id']] ?? '', $locked);
        }
        $html .= '</div>';

        // Ловушка для ботов: поле скрыто от людей и вспомогательных технологий
        $html .= '<div class="wpd-reg__hp" aria-hidden="true"><label for="wpd-reg-hp">Оставьте это поле пустым</label>'
            . '<input type="text" id="wpd-reg-hp" name="wpd_hp" value="" tabindex="-1" autocomplete="off"></div>';

        $privacy = self::privacy_url($r);
        $cerr = $errors['_consent'] ?? '';
        $html .= '<div class="wpd-reg__field wpd-reg__field--wide wpd-reg__consent' . ($cerr ? ' wpd-reg__field--error' : '') . '">'
            . '<label class="wpd-reg__check"><input type="checkbox" name="wpd_consent" value="1" required'
            . ($cerr ? ' aria-invalid="true" aria-describedby="wpd-reg-consent-err"' : '') . '> <span>' . esc_html($r['consent'])
            . ($privacy ? ' <a href="' . esc_url($privacy) . '" target="_blank" rel="noopener">Политика обработки персональных данных</a>' : '')
            . '<span class="wpd-reg__req" aria-hidden="true">*</span></span></label>'
            . ($cerr ? '<p class="wpd-reg__error" id="wpd-reg-consent-err">' . esc_html($cerr) . '</p>' : '') . '</div>';

        $html .= '<p class="wpd-reg__submit"><button type="submit" class="wpd-btn">' . esc_html($button) . '</button></p>'
            . '<p class="wpd-reg__note"><span class="wpd-reg__req" aria-hidden="true">*</span> — обязательные поля</p></form></section>';
        return WPD_Renderer::wrap($html, 'wpd-page--reg');
    }

    /** Дата «1 марта 2030, 09:00» — с русскими месяцами при любом языке сайта. */
    public static function human_date(int $ts, ?DateTimeZone $tz = null): string
    {
        static $months = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
        $d = (new DateTime('@' . $ts))->setTimezone($tz ?: wp_timezone());
        return $d->format('j') . ' ' . $months[(int) $d->format('n') - 1] . ' ' . $d->format('Y, H:i');
    }

    private static function plural(int $n, string $one, string $few, string $many): string
    {
        $n10 = $n % 10;
        $n100 = $n % 100;
        if ($n10 === 1 && $n100 !== 11) {
            return $one;
        }
        return ($n10 >= 2 && $n10 <= 4 && ($n100 < 10 || $n100 >= 20)) ? $few : $many;
    }

    /** Поле формы с подписью, подсказкой и ошибкой. */
    public static function field_html(array $f, string $value, string $error, bool $locked = false): string
    {
        $id = 'wpd-reg-' . $f['id'];
        $name = 'wpd_reg[' . $f['id'] . ']';
        $req = !empty($f['required']);
        $star = $req ? '<span class="wpd-reg__req" aria-hidden="true">*</span>' : '';
        $aria = ($req ? ' required' : '') . ($error !== '' ? ' aria-invalid="true" aria-describedby="' . esc_attr($id) . '-err"' : '');
        $ph = $f['placeholder'] !== '' ? ' placeholder="' . esc_attr($f['placeholder']) . '"' : '';
        $wide = in_array($f['type'], ['textarea', 'radio', 'checkbox'], true);
        $html = '<div class="wpd-reg__field' . ($wide ? ' wpd-reg__field--wide' : '') . ($error !== '' ? ' wpd-reg__field--error' : '') . '">';

        switch ($f['type']) {
            case 'textarea':
                $html .= '<label for="' . esc_attr($id) . '">' . esc_html($f['label']) . $star . '</label>'
                    . '<textarea id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" rows="4" maxlength="2000"' . $ph . $aria . '>' . esc_textarea($value) . '</textarea>';
                break;
            case 'select':
                $html .= '<label for="' . esc_attr($id) . '">' . esc_html($f['label']) . $star . '</label>'
                    . '<select id="' . esc_attr($id) . '" name="' . esc_attr($name) . '"' . $aria . '><option value="">— выберите —</option>';
                foreach (self::option_lines((string) $f['options']) as $opt) {
                    $html .= '<option value="' . esc_attr($opt) . '"' . selected($value, $opt, false) . '>' . esc_html($opt) . '</option>';
                }
                $html .= '</select>';
                break;
            case 'radio':
                $html .= '<fieldset' . ($error !== '' ? ' aria-describedby="' . esc_attr($id) . '-err"' : '') . '><legend>' . esc_html($f['label']) . $star . '</legend><div class="wpd-reg__options">';
                foreach (self::option_lines((string) $f['options']) as $n => $opt) {
                    $html .= '<label class="wpd-reg__check"><input type="radio" name="' . esc_attr($name) . '" value="' . esc_attr($opt) . '"'
                        . checked($value, $opt, false) . ($req && $n === 0 ? ' required' : '') . '> <span>' . esc_html($opt) . '</span></label>';
                }
                $html .= '</div></fieldset>';
                break;
            case 'checkbox':
                $html .= '<label class="wpd-reg__check"><input type="checkbox" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="1"'
                    . checked($value, '1', false) . $aria . '> <span>' . esc_html($f['label']) . $star . '</span></label>';
                break;
            default:
                $types = ['email' => 'email', 'tel' => 'tel', 'number' => 'number', 'date' => 'date'];
                $auto = ['name' => 'name', 'email' => 'email'];
                $autocomplete = $auto[$f['id']] ?? ($f['type'] === 'tel' ? 'tel' : '');
                $html .= '<label for="' . esc_attr($id) . '">' . esc_html($f['label']) . $star . '</label>'
                    . '<input type="' . ($types[$f['type']] ?? 'text') . '" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"'
                    . ($f['type'] === 'text' ? ' maxlength="200"' : '') . ($f['type'] === 'number' ? ' step="any"' : '')
                    . ($autocomplete ? ' autocomplete="' . $autocomplete . '"' : '') . ($locked ? ' readonly' : '') . $ph . $aria . '>';
                if ($locked) {
                    $html .= '<p class="wpd-reg__hint">E-mail вашего аккаунта.</p>';
                }
        }
        if ($error !== '') {
            $html .= '<p class="wpd-reg__error" id="' . esc_attr($id) . '-err">' . esc_html($error) . '</p>';
        }
        return $html . '</div>';
    }

    public static function render_cabinet(array $args = []): string
    {
        $r = self::get();
        $title = $args['title'] ?? 'Личный кабинет';
        $html = '<section class="wpd-cabinet" id="wpd-cabinet">' . ($title !== '' ? '<h2 class="wpd-h2">' . esc_html($title) . '</h2>' : '');
        $here = self::cabinet_url() ?: self::current_url();

        $done = self::done_app();
        if ($done) {
            $vars = self::mail_vars($done);
            $msg = strtr((string) $r['success'], ['{name}' => $vars['{name}'], '{email}' => $vars['{email}'], '{number}' => $vars['{number}']]);
            if (!is_user_logged_in()) {
                $msg .= ' Ссылка для установки пароля отправлена на ' . $vars['{email}'] . '.';
            }
            $html .= self::alert($msg, 'success');
        }

        if (!is_user_logged_in()) {
            $html .= '<p class="wpd-cabinet__intro">Войдите, чтобы посмотреть статус заявки.</p>'
                . '<div class="wpd-cabinet__login">' . wp_login_form([
                    'echo' => false, 'redirect' => $here, 'form_id' => 'wpd-login', 'id_username' => 'wpd-login-user', 'id_password' => 'wpd-login-pass',
                    'id_remember' => 'wpd-login-remember', 'id_submit' => 'wpd-login-submit',
                    'label_username' => 'E-mail или логин', 'label_password' => 'Пароль', 'label_remember' => 'Запомнить меня', 'label_log_in' => 'Войти',
                ]) . '</div><p class="wpd-cabinet__links"><a href="' . esc_url(wp_lostpassword_url($here)) . '">Забыли пароль или не получили его?</a>'
                . (self::form_url() ? ' · <a href="' . esc_url(self::form_url()) . '">Подать заявку</a>' : '') . '</p></section>';
            return WPD_Renderer::wrap($html, 'wpd-page--reg');
        }

        $user = wp_get_current_user();
        $app = self::user_application((int) $user->ID);
        $logout = '<p class="wpd-cabinet__links">' . esc_html($user->display_name) . ' · <a href="' . esc_url(wp_logout_url($here)) . '">Выйти</a></p>';
        if (!$app) {
            $html .= self::alert('Заявки пока нет.', 'info')
                . (self::form_url() && self::status($r)['open'] ? '<p><a class="wpd-btn" href="' . esc_url(self::form_url()) . '">Подать заявку</a></p>' : '')
                . $logout . '</section>';
            return WPD_Renderer::wrap($html, 'wpd-page--reg');
        }

        // phpcs:ignore WordPress.Security.NonceVerification
        if (isset($_GET['wpd_reg']) && $_GET['wpd_reg'] === 'saved') {
            $html .= self::alert('Изменения сохранены.', 'success');
        }
        $state = (self::$state && self::$state['form'] === 'edit') ? self::$state : null;
        if ($state && $state['notice'] !== '') {
            $html .= self::alert($state['notice'], 'closed');
        }

        $status = self::app_status($app);
        $messages = [
            'new'      => 'Заявка на проверке. Мы сообщим о решении по e-mail.',
            'approved' => 'Заявка подтверждена. До встречи на олимпиаде!',
            'rejected' => 'Заявка отклонена. Если это ошибка, свяжитесь с организаторами.',
        ];
        $html .= '<div class="wpd-cabinet__card"><div class="wpd-cabinet__head"><div><span class="wpd-cabinet__label">Заявка</span>'
            . '<strong class="wpd-cabinet__number">' . esc_html((string) get_post_meta($app->ID, '_wpd_number', true)) . '</strong></div>'
            . self::status_badge($status) . '</div>'
            . '<p class="wpd-cabinet__status">' . esc_html($messages[$status]) . '</p>'
            . '<p class="wpd-cabinet__date">Подана ' . esc_html(self::human_date(get_post_timestamp($app) ?: time())) . '</p>';

        // phpcs:ignore WordPress.Security.NonceVerification
        $editing = self::can_edit($app) && ((isset($_GET['wpd_edit']) && $_GET['wpd_edit'] === '1') || ($state && $state['errors']));
        $values = self::app_values($app);
        if ($editing) {
            $errors = $state ? $state['errors'] : [];
            if ($state && $state['values']) {
                $values = array_merge($values, $state['values']);
            }
            $html .= '<form class="wpd-reg__form" method="post" action="' . esc_url(remove_query_arg('wpd_edit', $here)) . '#wpd-cabinet">'
                . '<input type="hidden" name="wpd_reg_action" value="edit">' . wp_nonce_field('wpd_reg_edit', '_wpd_nonce', false, false);
            if ($errors) {
                $html .= self::alert($errors['_form'] ?? 'Проверьте поля формы.', 'error');
            }
            $html .= '<div class="wpd-reg__grid">';
            foreach (self::form_fields($r) as $f) {
                $html .= self::field_html($f, (string) ($values[$f['id']] ?? ''), $errors[$f['id']] ?? '', $f['id'] === 'email');
            }
            $html .= '</div><p class="wpd-reg__submit"><button type="submit" class="wpd-btn">Сохранить</button> '
                . '<a class="wpd-btn wpd-btn--ghost" href="' . esc_url(remove_query_arg('wpd_edit', $here)) . '#wpd-cabinet">Отмена</a></p></form>';
        } else {
            $html .= '<dl class="wpd-cabinet__data">';
            foreach (self::form_fields($r) as $f) {
                $v = (string) ($values[$f['id']] ?? '');
                if ($f['type'] === 'checkbox') {
                    $v = $v === '1' ? 'Да' : 'Нет';
                }
                $html .= '<div><dt>' . esc_html($f['label']) . '</dt><dd>' . ($v !== '' ? nl2br(esc_html($v)) : '—') . '</dd></div>';
            }
            $html .= '</dl>';
            if (self::can_edit($app)) {
                $html .= '<p><a class="wpd-btn wpd-btn--ghost" href="' . esc_url(add_query_arg('wpd_edit', '1', $here)) . '#wpd-cabinet">Изменить заявку</a></p>';
            }
        }
        $html .= '</div>' . $logout . '</section>';
        return WPD_Renderer::wrap($html, 'wpd-page--reg');
    }

    /* ---------------------------------------------------------------
     * Персональные данные: экспорт и удаление по запросу (Инструменты → Экспорт/Удаление персональных данных)
     * ------------------------------------------------------------- */

    public static function register_exporter(array $exporters): array
    {
        $exporters['wpd-registration'] = ['exporter_friendly_name' => 'Заявки на олимпиаду', 'callback' => [__CLASS__, 'privacy_export']];
        return $exporters;
    }

    public static function register_eraser(array $erasers): array
    {
        $erasers['wpd-registration'] = ['eraser_friendly_name' => 'Заявки на олимпиаду', 'callback' => [__CLASS__, 'privacy_erase']];
        return $erasers;
    }

    public static function privacy_export($email, $page = 1): array
    {
        $data = [];
        $app = self::find_by_email((string) $email);
        if ($app) {
            $values = self::app_values($app);
            $items = [
                ['name' => 'Номер заявки', 'value' => (string) get_post_meta($app->ID, '_wpd_number', true)],
                ['name' => 'Статус', 'value' => self::STATUSES[self::app_status($app)]],
                ['name' => 'Дата подачи', 'value' => $app->post_date],
            ];
            foreach (self::form_fields() as $f) {
                $items[] = ['name' => $f['label'], 'value' => (string) ($values[$f['id']] ?? '')];
            }
            $data[] = ['group_id' => 'wpd-applications', 'group_label' => 'Заявки на олимпиаду', 'item_id' => 'wpd-app-' . $app->ID, 'data' => $items];
        }
        return ['data' => $data, 'done' => true];
    }

    public static function privacy_erase($email, $page = 1): array
    {
        $removed = false;
        for ($i = 0; $i < 50 && ($app = self::find_by_email((string) $email)); $i++) {
            if (!wp_delete_post($app->ID, true)) {
                break;
            }
            $removed = true;
        }
        return ['items_removed' => $removed, 'items_retained' => false, 'messages' => [], 'done' => true];
    }
}
