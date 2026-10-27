/*
 * WP Olympiad Design — боковая колонка «Оформление олимпиады» в блочном редакторе (2.4.0).
 * Основное оформление страницы: включение оформления, своё оформление, стиль, палитра, цвета, шрифт заголовков,
 * размеры, кнопки, тени, стиль главного блока. Колонка не хранит данных сама: она меняет поля панели
 * «Блоки олимпиады» под текстом, поэтому всё сохраняется вместе с записью и всегда совпадает с панелью.
 * Изменения сразу видны в блоках «Олимпиада: …» на холсте (предпросмотр делает wpd-admin.js).
 */
(function (wp, $) {
    'use strict';

    if (!wp || !wp.plugins || !wp.element || !wp.components || !$) {
        return;
    }
    var Panel = (wp.editor && wp.editor.PluginDocumentSettingPanel) || (wp.editPost && wp.editPost.PluginDocumentSettingPanel);
    if (!Panel) {
        return;
    }

    var el = wp.element.createElement;
    var useState = wp.element.useState;
    var useEffect = wp.element.useEffect;
    var C = wp.components;

    var OV = 'wpd_page[ov]';
    var GROUP = 'wpd_page[ovg][page]';
    var HERO_STYLE = 'wpd_page[hero][style]';
    var ENABLED = 'wpd_enabled';

    function nodes(name) {
        return document.querySelectorAll('[name="' + name + '"]');
    }

    function exists(name) {
        return nodes(name).length > 0;
    }

    function read(name) {
        var list = nodes(name);
        if (!list.length) {
            return null;
        }
        var first = list[0];
        if (first.type === 'radio') {
            for (var i = 0; i < list.length; i++) {
                if (list[i].checked) {
                    return list[i].value;
                }
            }
            return '';
        }
        if (first.type === 'checkbox') {
            return first.checked;
        }
        return first.value;
    }

    function write(name, value) {
        var list = nodes(name);
        if (!list.length) {
            return;
        }
        var first = list[0];
        var target = first;
        if (first.type === 'radio') {
            for (var i = 0; i < list.length; i++) {
                if (list[i].value === value) {
                    target = list[i];
                }
            }
            target.checked = true;
        } else if (first.type === 'checkbox') {
            first.checked = !!value;
        } else {
            first.value = value;
        }
        // jQuery-событие: на него подписаны палитры (wpd-settings.js), предпросмотр и отметка «изменено» (wpd-admin.js)
        $(target).trigger('change');
    }

    function options(name) {
        var node = nodes(name)[0];
        if (!node || !node.options) {
            return [];
        }
        return Array.prototype.map.call(node.options, function (o) {
            return { value: o.value, label: o.textContent };
        });
    }

    function label(name, fallback) {
        var node = nodes(name)[0];
        if (node && node.id) {
            var l = document.querySelector('label[for="' + node.id + '"]');
            if (l && l.textContent.trim()) {
                return l.textContent.trim();
            }
        }
        return fallback;
    }

    function SelectField(props) {
        var name = props.name;
        if (!exists(name)) {
            return null;
        }
        return el(C.SelectControl, {
            label: props.label || label(name, ''),
            value: read(name) || '',
            options: options(name),
            onChange: function (v) { write(name, v); props.bump(); },
            __nextHasNoMarginBottom: true
        });
    }

    function NumberField(props) {
        var name = props.name;
        var node = nodes(name)[0];
        if (!node) {
            return null;
        }
        var min = parseInt(node.getAttribute('min'), 10);
        var max = parseInt(node.getAttribute('max'), 10);
        return el(C.RangeControl, {
            label: label(name, ''),
            value: parseInt(read(name), 10) || 0,
            min: isNaN(min) ? 0 : min,
            max: isNaN(max) ? 100 : max,
            onChange: function (v) { write(name, String(v === undefined ? '' : v)); props.bump(); },
            __nextHasNoMarginBottom: true
        });
    }

    function TextField(props) {
        var name = props.name;
        if (!exists(name)) {
            return null;
        }
        return el(C.TextControl, {
            label: label(name, ''),
            value: read(name) || '',
            onChange: function (v) { write(name, v); props.bump(); },
            help: props.help,
            __nextHasNoMarginBottom: true
        });
    }

    function ToggleField(props) {
        var name = props.name;
        if (!exists(name)) {
            return null;
        }
        return el(C.ToggleControl, {
            label: props.label || label(name, ''),
            help: props.help,
            checked: !!read(name),
            onChange: function (v) { write(name, v); props.bump(); },
            __nextHasNoMarginBottom: true
        });
    }

    function ColorField(props) {
        var name = props.name;
        if (!exists(name)) {
            return null;
        }
        var id = 'wpd-side-' + name.replace(/[^a-z0-9]+/gi, '-');
        return el('div', { className: 'wpd-side__color' },
            el('input', {
                type: 'color',
                id: id,
                value: read(name) || '#000000',
                onChange: function (e) { write(name, e.target.value); props.bump(); }
            }),
            el('label', { htmlFor: id }, label(name, ''))
        );
    }

    function Palettes(props) {
        var name = OV + '[palette]';
        var list = nodes(name);
        if (!list.length) {
            return null;
        }
        var current = read(name);
        return el('div', { className: 'wpd-side__palettes', role: 'radiogroup', 'aria-label': 'Палитра' },
            Array.prototype.map.call(list, function (radio) {
                var colors = {};
                try {
                    colors = JSON.parse(radio.getAttribute('data-colors') || '{}');
                } catch (e) { /* ничего */ }
                var title = (radio.closest('label') && radio.closest('label').querySelector('.wpd-palette__name'));
                var swatches = Object.keys(colors).map(function (k) {
                    return el('span', { key: k, style: { background: colors[k] } });
                });
                return el('button', {
                    key: radio.value,
                    type: 'button',
                    role: 'radio',
                    'aria-checked': current === radio.value ? 'true' : 'false',
                    className: 'wpd-side__palette' + (current === radio.value ? ' is-selected' : ''),
                    onClick: function () { write(name, radio.value); props.bump(); }
                },
                el('span', { className: 'wpd-side__sw' }, swatches.length ? swatches : '✎'),
                el('span', { className: 'wpd-side__pname' }, title ? title.textContent : radio.value));
            })
        );
    }

    function scrollToPanel() {
        var box = document.getElementById('wpd_page_blocks');
        if (box) {
            box.scrollIntoView({ behavior: 'smooth', block: 'start' });
            if (box.classList.contains('closed')) {
                var toggle = box.querySelector('.handlediv, .postbox-header button');
                if (toggle) {
                    toggle.click();
                }
            }
        }
    }

    function Sidebar() {
        var state = useState(0);
        var bump = function () { state[1](function (n) { return n + 1; }); };

        useEffect(function () {
            var handler = function () { bump(); };
            $(document).on('change.wpdside', '[name^="wpd_page"], [name="wpd_enabled"]', handler);
            $(document).on('wpd:preview.wpdside', handler);
            // Мета-боксы появляются в документе позже колонки
            var tries = 0;
            var timer = window.setInterval(function () {
                tries++;
                if (exists(GROUP) || tries > 20) {
                    window.clearInterval(timer);
                    bump();
                }
            }, 500);
            return function () {
                $(document).off('.wpdside');
                window.clearInterval(timer);
            };
        }, []);

        if (!exists(GROUP)) {
            return el(Panel, { name: 'wpd-design', title: 'Оформление олимпиады', className: 'wpd-side' },
                el('p', null, 'Панель «Блоки олимпиады» скрыта или ещё загружается. Включите её в меню «Параметры» → «Настройки» → «Панели».'));
        }

        var own = !!read(GROUP);
        var o = function (key) { return OV + '[' + key + ']'; };

        return el(Panel, { name: 'wpd-design', title: 'Оформление олимпиады', className: 'wpd-side' },
            el(ToggleField, { name: ENABLED, label: 'Оформление в стиле олимпиады', bump: bump }),
            el(SelectField, { name: HERO_STYLE, label: 'Стиль главного блока', bump: bump }),
            el(ToggleField, {
                name: GROUP,
                label: 'Своё оформление для этой страницы',
                help: own ? 'Значения ниже действуют только на этой странице.' : 'Сейчас — как в общих настройках.',
                bump: bump
            }),
            own ? el('div', { className: 'wpd-side__group' },
                el(SelectField, { name: o('design_style'), bump: bump }),
                el('p', { className: 'wpd-side__label' }, 'Палитра'),
                el(Palettes, { bump: bump }),
                el('div', { className: 'wpd-side__colors' },
                    el(ColorField, { name: o('primary'), bump: bump }),
                    el(ColorField, { name: o('accent'), bump: bump }),
                    el(ColorField, { name: o('bg'), bump: bump }),
                    el(ColorField, { name: o('text'), bump: bump }),
                    el(ColorField, { name: o('dark'), bump: bump })
                ),
                el(NumberField, { name: o('radius'), bump: bump }),
                el(TextField, { name: o('font_heading'), bump: bump, help: 'Например: Unbounded, sans-serif. Пусто — основной шрифт.' }),
                el(SelectField, { name: o('heading_weight'), bump: bump }),
                el(NumberField, { name: o('font_size'), bump: bump }),
                el(NumberField, { name: o('content_width'), bump: bump }),
                el(NumberField, { name: o('block_gap'), bump: bump }),
                el(SelectField, { name: o('button_shape'), bump: bump }),
                el(SelectField, { name: o('button_style'), bump: bump }),
                el(SelectField, { name: o('card_shadow'), bump: bump }),
                el(ToggleField, { name: o('hide_title'), bump: bump })
            ) : null,
            el('p', { className: 'wpd-side__note' }, 'Изменения сразу видны в блоках «Олимпиада: …» и сохраняются кнопкой «Сохранить» / «Обновить».'),
            el(C.Button, { variant: 'secondary', onClick: scrollToPanel }, 'Все параметры страницы')
        );
    }

    wp.plugins.registerPlugin('wpd-design-sidebar', { render: Sidebar, icon: 'awards' });
})(window.wp, window.jQuery);
