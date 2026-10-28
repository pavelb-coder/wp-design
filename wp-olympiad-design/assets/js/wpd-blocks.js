/*
 * WP Olympiad Design — блоки редактора «Олимпиада: …».
 * Без сборки: используются глобальные пакеты wp.*. Блоки динамические (save → null),
 * разметку на сайте строит PHP, а в редакторе показывается та же разметка с полями для правки.
 */
(function (wp, data) {
    'use strict';

    if (!wp || !wp.blocks || !wp.element || !data || !data.blocks) {
        return;
    }

    var el = wp.element.createElement;
    var Fragment = wp.element.Fragment;
    var be = wp.blockEditor || wp.editor;
    var c = wp.components;

    /* ---------- Вспомогательные функции ---------- */

    function clone(value) {
        return JSON.parse(JSON.stringify(value));
    }

    function defaults(name) {
        var out = {};
        var attrs = data.blocks[name].attributes;
        Object.keys(attrs).forEach(function (key) {
            out[key] = clone(attrs[key]['default']);
        });
        return out;
    }

    function list(value) {
        return Array.isArray(value) ? value : [];
    }

    function updateItem(items, index, patch) {
        var next = items.slice();
        next[index] = Object.assign({}, next[index], patch);
        return next;
    }

    function moveItem(items, index, dir) {
        var j = index + dir;
        if (j < 0 || j >= items.length) {
            return items;
        }
        var next = items.slice();
        var tmp = next[index];
        next[index] = next[j];
        next[j] = tmp;
        return next;
    }

    function removeItem(items, index) {
        return items.filter(function (_, k) {
            return k !== index;
        });
    }

    function singleLine(value) {
        return String(value || '').replace(/[\r\n]+/g, ' ');
    }

    function isPdf(url) {
        return /\.pdf(\?|#|$)/i.test(url || '');
    }

    function formatDate(value) {
        var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})$/.exec(value || '');
        // С обозначением часового пояса таймера («МСК» и т. п.), как на сайте
        return m ? m[3] + '.' + m[2] + '.' + m[1] + ', ' + m[4] + ':' + m[5] + (data.countdownTz ? ' ' + data.countdownTz : '') : '';
    }

    /*
     * Размер иконок из атрибута блока (2.5.0): CSS-переменная только для своего значения.
     * 0 и значение, совпадающее со значением по умолчанию атрибута (оно не сохраняется в разметку блока),
     * означают «как в настройках» — тогда действует переменная страницы или общих настроек из data.vars.
     */
    function iconVars(slug, a) {
        var d = data.iconSizes && data.iconSizes[slug];
        if (!d || !a) {
            return {};
        }
        var v = parseInt(a[d.attr], 10);
        var def = data.blocks['wpd/' + slug] && data.blocks['wpd/' + slug].attributes[d.attr]
            ? parseInt(data.blocks['wpd/' + slug].attributes[d.attr]['default'], 10) : 0;
        if (!(v > 0) || v === def) {
            return {};
        }
        var out = {};
        out[d['var']] = Math.max(d.min, Math.min(d.max, v)) + 'px';
        return out;
    }

    /* Поле «Размер иконок» в боковой панели блока */
    function IconSizeControl(slug, a, set, label) {
        var d = data.iconSizes && data.iconSizes[slug];
        if (!d) {
            return null;
        }
        var current = d.setting > 0 ? d.setting : d['default'];
        return el(c.RangeControl, {
            label: label || 'Размер иконок, px',
            className: 'wpd-ed-icon-size',
            value: parseInt(a[d.attr], 10) || 0,
            min: 0, max: d.max, step: 1,
            help: '0 — как в настройках (' + current + ' px). От ' + d.min + ' до ' + d.max + ' px.',
            onChange: function (v) { var o = {}; o[d.attr] = typeof v === 'number' ? v : 0; set(o); }
        });
    }

    /*
     * Корневой контейнер с CSS-переменными из общих настроек. Второй аргумент — className или
     * { className, attributes } (для блоков со своим размером иконок).
     */
    function Wrapper(slug, opts) {
        var className = opts && typeof opts === 'object' ? opts.className : opts;
        var own = opts && typeof opts === 'object' ? iconVars(slug, opts.attributes) : {};
        var ownStyle = Object.keys(own).map(function (k) { return k + ':' + own[k] + ';'; }).join('');
        var style = Object.assign({}, data.vars, own);
        var cls = 'wpd-page wpd-page--block wpd-page--editor wpd-page--' + slug + (data.pageClass ? ' ' + data.pageClass : '');
        var base = { className: cls, style: style };
        if (ownStyle) {
            // Предпросмотр своего оформления страницы (wpd-admin.js) заменяет style контейнера и добавляет это значение
            base['data-wpd-style'] = ownStyle;
        }
        var props = be.useBlockProps
            ? be.useBlockProps(base)
            : Object.assign({}, base, { className: cls + (className ? ' ' + className : '') });
        var children = Array.prototype.slice.call(arguments, 2);
        return el.apply(null, ['div', props].concat(children));
    }

    /* Многострочный текст прямо на странице (наследует оформление родителя) */
    function Plain(p) {
        return el(be.PlainText, {
            className: 'wpd-ed-plain',
            value: p.value || '',
            placeholder: p.placeholder,
            'aria-label': p.label || p.placeholder,
            onChange: function (v) {
                p.onChange(p.single ? singleLine(v) : v);
            }
        });
    }

    /* Короткий текст в строчном элементе (метка, кнопка): ширина по содержимому */
    function Inline(p) {
        var value = p.value || '';
        return el('input', {
            type: 'text',
            className: 'wpd-ed-plain wpd-ed-inline',
            value: value,
            placeholder: p.placeholder,
            'aria-label': p.label || p.placeholder,
            size: Math.max(4, (value || p.placeholder || '').length + 1),
            onChange: function (e) {
                p.onChange(e.target.value);
            }
        });
    }

    function Tools(p) {
        var i = p.index;
        var items = p.items;
        return el('div', { className: 'wpd-ed-tools' },
            el(c.Button, {
                icon: 'arrow-up-alt2', label: 'Выше', disabled: i === 0,
                onClick: function () { p.onChange(moveItem(items, i, -1)); }
            }),
            el(c.Button, {
                icon: 'arrow-down-alt2', label: 'Ниже', disabled: i === items.length - 1,
                onClick: function () { p.onChange(moveItem(items, i, 1)); }
            }),
            el(c.Button, {
                icon: 'trash', label: 'Удалить', isDestructive: true,
                onClick: function () { p.onChange(removeItem(items, i)); }
            })
        );
    }

    function AddButton(p) {
        return el('div', { className: 'wpd-ed-add' },
            el(c.Button, { variant: 'secondary', icon: 'plus', onClick: p.onClick }, p.label)
        );
    }

    function Empty(text) {
        return el('p', { className: 'wpd-ed-empty' }, text);
    }

    function ResetPanel(name, set) {
        return el(c.PanelBody, { title: 'Значения по умолчанию', initialOpen: false },
            el('p', null, 'Поля, которые вы не меняли, берутся из общих настроек плагина и обновляются вместе с ними.'),
            el(c.Button, {
                variant: 'secondary',
                onClick: function () { set(defaults(name)); }
            }, 'Вернуть значения из настроек'),
            el('p', { style: { marginTop: '12px' } },
                el('a', { href: data.settingsUrl, target: '_blank', rel: 'noopener noreferrer' }, 'Открыть общие настройки ↗')
            )
        );
    }

    function Media(p) {
        if (!be.MediaUpload) {
            return null;
        }
        var Check = be.MediaUploadCheck || Fragment;
        return el(Check, null, el(be.MediaUpload, {
            onSelect: p.onSelect,
            allowedTypes: p.allowedTypes,
            value: p.value,
            render: function (o) {
                return el(c.Button, {
                    variant: p.variant || 'secondary',
                    icon: p.icon,
                    label: p.iconOnly ? p.label : undefined,
                    onClick: o.open
                }, p.iconOnly ? null : p.label);
            }
        }));
    }

    /* ---------- Главный блок ---------- */

    // Светлый ли цвет #rrggbb — та же формула, что WPD_Renderer::is_light_color()
    function isLight(hex) {
        var m = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(hex || '');
        if (!m) { return false; }
        var h = m[1].length === 3 ? m[1].replace(/(.)/g, '$1$1') : m[1];
        var lin = function (c) { c /= 255; return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); };
        var l = 0.2126 * lin(parseInt(h.substr(0, 2), 16)) + 0.7152 * lin(parseInt(h.substr(2, 2), 16)) + 0.0722 * lin(parseInt(h.substr(4, 2), 16));
        return l > 0.18;
    }

    function pick(value, opts, def) {
        return opts && Object.prototype.hasOwnProperty.call(opts, value) ? value : def;
    }

    function HeroEdit(props) {
        var a = props.attributes;
        var set = props.setAttributes;
        var sel = props.isSelected;
        var ho = data.heroOptions || {};
        var variant = data.heroStyles[a.variant] ? a.variant : 'dark';
        var position = pick(a.imagePosition, ho.imagePosition, 'right');
        var height = pick(a.height, ho.height, 'normal');
        var align = pick(a.textAlign, ho.textAlign, 'left');
        var tsize = pick(a.titleSize, ho.titleSize, 'normal');
        var bg = /^#[0-9a-f]{6}$/i.test(a.bgColor || '') ? a.bgColor : '';
        var overlay = typeof a.overlay === 'number' ? Math.max(0, Math.min(90, a.overlay)) : 55;
        var isBgImage = !!a.imageUrl && position === 'background';
        // 1.9.0
        var hex = function (v) { return /^#[0-9a-f]{6}$/i.test(v || '') ? v : ''; };
        var num = function (v, max) { return typeof v === 'number' ? Math.max(0, Math.min(max, v)) : 0; };
        var fit = pick(a.imageFit, ho.imageFit, 'cover');
        var istyle = pick(a.imageStyle, ho.imageStyle, 'rounded');
        var valign = pick(a.verticalAlign, ho.verticalAlign, 'center');
        var minh = num(a.minHeight, 900);
        var pad = num(a.padding, 120);
        var twidth = num(a.textWidth, 1200);
        var icons = data.icons || {};
        var iconOptions = [{ value: '', label: 'Без иконки' }].concat(Object.keys(icons).map(function (k) { return { value: k, label: icons[k] }; }));

        var cls = ['wpd-hero', 'wpd-hero--' + variant];
        // 2.3.0: «Мягкий градиент» без изображения — встроенная иллюстрация городов, как на сайте
        var orb = !a.imageUrl && variant === 'soft' && !!data.softOrb;
        if (a.imageUrl) { cls.push('wpd-hero--img-' + position); }
        if (orb) { cls.push('wpd-hero--orb', 'wpd-hero--img-' + (position === 'left' ? 'left' : 'right')); }
        if (height !== 'normal') { cls.push('wpd-hero--height-' + height); }
        if (align !== 'left') { cls.push('wpd-hero--align-' + align); }
        if (tsize !== 'normal') { cls.push('wpd-hero--title-' + tsize); }
        var style = {};
        if (bg) {
            cls.push('wpd-hero--custom-bg', isLight(bg) ? 'wpd-hero--fg-dark' : 'wpd-hero--fg-light');
            style['--wpd-hero-bg'] = bg;
        }
        if (isBgImage) {
            style['--wpd-hero-img'] = 'url("' + String(a.imageUrl).replace(/["'()\\\s]/g, encodeURIComponent) + '")';
            style['--wpd-hero-overlay'] = overlay + '%';
            if (hex(a.overlayColor)) { style['--wpd-hero-overlay-color'] = a.overlayColor; }
        }
        if (a.imageUrl && !isBgImage) {
            if (fit !== 'cover') { cls.push('wpd-hero--fit-' + fit); }
            if (istyle !== 'rounded') { cls.push('wpd-hero--imgstyle-' + istyle); }
        }
        if (a.imageUrl && a.imageMobile === false) { cls.push('wpd-hero--img-hide-mobile'); }
        if (valign !== 'center') { cls.push('wpd-hero--valign-' + valign); }
        if (hex(a.badgeColor)) {
            cls.push('wpd-hero--c-badge');
            style['--wpd-hero-badge'] = a.badgeColor;
            style['--wpd-hero-badge-fg'] = isLight(a.badgeColor) ? '#0f172a' : '#fff';
        }
        if (hex(a.titleColor)) { cls.push('wpd-hero--c-title'); style['--wpd-hero-title'] = a.titleColor; }
        if (hex(a.textColor)) { cls.push('wpd-hero--c-text'); style['--wpd-hero-text'] = a.textColor; }
        if (minh) { cls.push('wpd-hero--minh'); style['--wpd-hero-minh'] = minh + 'px'; }
        if (pad) { cls.push('wpd-hero--pad'); style['--wpd-hero-pad'] = pad + 'px'; }
        if (twidth) { cls.push('wpd-hero--text-w'); style['--wpd-hero-text-w'] = twidth + 'px'; }

        // Таймер внутри блока: предпросмотр по датам из настроек (как блок «Обратный отсчёт» по умолчанию)
        var cd = data.blocks['wpd/countdown'] ? defaults('wpd/countdown') : {};
        var timerPreview = a.showCountdown
            ? el('div', { className: 'wpd-hero__countdown' },
                cd.date
                    ? el('div', { className: 'wpd-countdown' },
                        el('div', { className: 'wpd-countdown__main' },
                            el('p', { className: 'wpd-countdown__label' }, cd.label || ''),
                            el('p', { className: 'wpd-countdown__value' }, formatDate(cd.date))
                        ))
                    : (sel ? el('p', { className: 'wpd-ed-hint' }, 'Дата таймера не задана — укажите её в общих настройках, вкладка «Таймер и эффекты».') : null))
            : null;

        var opts = function (o) { return Object.keys(o || {}).map(function (k) { return { value: k, label: o[k] }; }); };
        var styleOptions = Object.keys(data.heroStyles).map(function (key) {
            return { value: key, label: data.heroStyles[key] };
        });
        var Toggle = c.ToggleControl || c.CheckboxControl;

        function pickImage(m) {
            var url = (m.sizes && m.sizes.large && m.sizes.large.url) || m.url;
            set({ imageUrl: url, imageId: m.id || 0, imageAlt: a.imageAlt || m.alt || '' });
        }

        var mediaButton = el(Media, {
            label: a.imageUrl ? 'Заменить изображение' : 'Выбрать изображение',
            allowedTypes: ['image'],
            value: a.imageId,
            onSelect: pickImage
        });

        function button(textKey, urlKey, klass, placeholder, arrow) {
            var iconKey = textKey === 'buttonText' ? 'buttonIcon' : 'button2Icon';
            return (sel || a[textKey]) ? el('span', { className: klass + (a[textKey] ? '' : ' is-empty') },
                icons[a[iconKey]] ? el('span', { className: 'wpd-btn__icon dashicons dashicons-' + a[iconKey], 'aria-hidden': 'true' }) : null,
                el(Inline, { value: a[textKey], placeholder: placeholder, label: placeholder, onChange: function (v) { var o = {}; o[textKey] = v; set(o); } }),
                arrow ? ' →' : null
            ) : null;
        }

        function colorField(key, label, help) {
            var value = hex(a[key]);
            var update = function (v) { var o = {}; o[key] = v || ''; set(o); };
            return c.ColorPalette ? el(c.BaseControl, { label: label, help: help, id: 'wpd-hero-' + key },
                el(c.ColorPalette, {
                    colors: (data.palette || []).map(function (col, i) { return { name: 'Цвет ' + (i + 1), color: col }; }),
                    value: value || undefined, clearable: true, disableCustomColors: false,
                    onChange: update
                })
            ) : el(c.TextControl, {
                label: label + ' (#rrggbb)', value: a[key] || '', placeholder: '#rrggbb', help: help,
                onChange: update
            });
        }
        var colorControl = colorField('bgColor', 'Свой цвет фона', 'Заменяет фон стиля, цвет текста подбирается сам. Пусто — фон стиля.');
        function range(key, label, max, step, help) {
            return el(c.RangeControl, {
                label: label, value: num(a[key], max), min: 0, max: max, step: step, help: help,
                onChange: function (v) { var o = {}; o[key] = typeof v === 'number' ? v : 0; set(o); }
            });
        }

        return el(Fragment, null,
            el(be.InspectorControls, null,
                el(c.PanelBody, { title: 'Главный блок' },
                    el(c.SelectControl, {
                        label: 'Стиль', value: variant, options: styleOptions,
                        onChange: function (v) { set({ variant: v }); }
                    }),
                    colorControl,
                    el(c.SelectControl, {
                        label: 'Высота', value: height, options: opts(ho.height),
                        onChange: function (v) { set({ height: v }); }
                    }),
                    el(c.SelectControl, {
                        label: 'Выравнивание текста', value: align, options: opts(ho.textAlign),
                        onChange: function (v) { set({ textAlign: v }); }
                    }),
                    el(c.SelectControl, {
                        label: 'Размер заголовка', value: tsize, options: opts(ho.titleSize),
                        onChange: function (v) { set({ titleSize: v }); }
                    }),
                    el(c.SelectControl, {
                        label: 'Вертикальное выравнивание', value: valign, options: opts(ho.verticalAlign),
                        onChange: function (v) { set({ verticalAlign: v }); }
                    })
                ),
                el(c.PanelBody, { title: 'Размеры', initialOpen: false },
                    range('minHeight', 'Минимальная высота, px', 900, 10, '0 — по высоте блока.'),
                    range('padding', 'Внутренний отступ, px', 120, 4, '0 — по высоте блока.'),
                    range('textWidth', 'Максимальная ширина текста, px', 1200, 20, '0 — автоматически.')
                ),
                el(c.PanelBody, { title: 'Цвета текста', initialOpen: false },
                    colorField('badgeColor', 'Цвет метки', 'Фон метки; цвет её текста подбирается сам. Пусто — акцентный цвет.'),
                    colorField('titleColor', 'Цвет заголовка', 'Пусто — по стилю и фону.'),
                    colorField('textColor', 'Цвет текста под заголовком', 'Пусто — по стилю и фону.')
                ),
                el(c.PanelBody, { title: 'Кнопки', initialOpen: false },
                    el(c.TextControl, {
                        label: 'Ссылка кнопки', type: 'url', value: a.buttonUrl || '',
                        help: 'Без ссылки кнопка на сайте не выводится.',
                        onChange: function (v) { set({ buttonUrl: v }); }
                    }),
                    el(Toggle, {
                        label: 'Кнопка в новой вкладке', checked: !!a.buttonNewTab,
                        onChange: function (v) { set({ buttonNewTab: !!v }); }
                    }),
                    el(c.TextControl, {
                        label: 'Ссылка второй кнопки', type: 'url', value: a.button2Url || '',
                        help: 'Контурная кнопка рядом с основной. Текст — на холсте.',
                        onChange: function (v) { set({ button2Url: v }); }
                    }),
                    el(Toggle, {
                        label: 'Вторая кнопка в новой вкладке', checked: !!a.button2NewTab,
                        onChange: function (v) { set({ button2NewTab: !!v }); }
                    }),
                    el(c.SelectControl, {
                        label: 'Иконка кнопки', value: icons[a.buttonIcon] ? a.buttonIcon : '', options: iconOptions,
                        onChange: function (v) { set({ buttonIcon: v }); }
                    }),
                    el(c.SelectControl, {
                        label: 'Иконка второй кнопки', value: icons[a.button2Icon] ? a.button2Icon : '', options: iconOptions,
                        onChange: function (v) { set({ button2Icon: v }); }
                    }),
                    IconSizeControl('hero', a, set, 'Размер иконок на кнопках, px'),
                    el(Toggle, {
                        label: 'Таймер под кнопками', checked: !!a.showCountdown,
                        help: 'Даты и подписи — из общих настроек, вкладка «Таймер и эффекты».',
                        onChange: function (v) { set({ showCountdown: !!v }); }
                    })
                ),
                el(c.PanelBody, { title: 'Изображение', initialOpen: false },
                    el('div', { className: 'wpd-ed-media-control' },
                        a.imageUrl ? el('img', { src: a.imageUrl, alt: '' }) : null,
                        mediaButton,
                        a.imageUrl ? el(c.Button, {
                            variant: 'link', isDestructive: true,
                            onClick: function () { set({ imageUrl: '', imageId: 0 }); }
                        }, 'Убрать изображение') : null
                    ),
                    el(c.TextControl, {
                        label: 'Или адрес изображения (URL)', type: 'url', value: a.imageUrl || '',
                        onChange: function (v) { set({ imageUrl: v, imageId: 0 }); }
                    }),
                    el(c.SelectControl, {
                        label: 'Положение изображения', value: position, options: opts(ho.imagePosition),
                        onChange: function (v) { set({ imagePosition: v }); }
                    }),
                    position === 'background' ? el(c.RangeControl, {
                        label: 'Затемнение фона, %', value: overlay, min: 0, max: 90, step: 5,
                        onChange: function (v) { set({ overlay: typeof v === 'number' ? v : 55 }); }
                    }) : null,
                    position === 'background' ? colorField('overlayColor', 'Цвет затемнения', 'Пусто — свой цвет фона или тёмный цвет.') : null,
                    position !== 'background' ? el(c.SelectControl, {
                        label: 'Подгонка изображения', value: fit, options: opts(ho.imageFit),
                        onChange: function (v) { set({ imageFit: v }); }
                    }) : null,
                    position !== 'background' ? el(c.SelectControl, {
                        label: 'Рамка изображения', value: istyle, options: opts(ho.imageStyle),
                        onChange: function (v) { set({ imageStyle: v }); }
                    }) : null,
                    el(Toggle, {
                        label: 'Показывать на телефоне', checked: a.imageMobile !== false,
                        onChange: function (v) { set({ imageMobile: !!v }); }
                    }),
                    el(c.TextControl, {
                        label: 'Описание изображения (alt)', value: a.imageAlt || '',
                        help: 'Для незрячих посетителей и поисковиков. Пусто — декоративное изображение.',
                        onChange: function (v) { set({ imageAlt: v }); }
                    })
                ),
                ResetPanel('wpd/hero', set)
            ),
            Wrapper('hero', { className: props.className, attributes: a },
                el('section', { className: cls.join(' '), style: style },
                    el('div', { className: 'wpd-hero__inner' },
                        el('div', { className: 'wpd-hero__text' },
                            (sel || a.badge) ? el('span', { className: 'wpd-badge' + (a.badge ? '' : ' is-empty') },
                                el(Inline, { value: a.badge, placeholder: 'Метка', label: 'Метка над заголовком', onChange: function (v) { set({ badge: v }); } })
                            ) : null,
                            el('h1', { className: 'wpd-hero__title' },
                                el(Plain, { value: a.title, single: true, placeholder: 'Заголовок', onChange: function (v) { set({ title: v }); } })
                            ),
                            (sel || a.lead) ? el('p', { className: 'wpd-hero__lead' },
                                el(Plain, { value: a.lead, placeholder: 'Текст под заголовком', onChange: function (v) { set({ lead: v }); } })
                            ) : null,
                            (sel || a.buttonText || a.button2Text) ? el('div', { className: 'wpd-hero__actions' },
                                button('buttonText', 'buttonUrl', 'wpd-btn', 'Текст кнопки', true),
                                button('button2Text', 'button2Url', 'wpd-btn wpd-btn--ghost', 'Вторая кнопка', false)
                            ) : null,
                            (sel && ((a.buttonText && !a.buttonUrl) || (a.button2Text && !a.button2Url)))
                                ? el('p', { className: 'wpd-ed-hint' }, 'Укажите ссылки кнопок в настройках блока справа (раздел «Кнопки»).')
                                : null,
                            timerPreview
                        ),
                        (a.imageUrl && !isBgImage)
                            ? el('div', { className: 'wpd-hero__media' }, el('img', { src: a.imageUrl, alt: a.imageAlt || '' }))
                            : (orb
                                ? el('div', { className: 'wpd-hero__media wpd-hero__media--orb' },
                                    el('span', { className: 'wpd-orb__aura', 'aria-hidden': 'true' }),
                                    el('img', { className: 'wpd-orb__img', src: data.softOrb, alt: '' }),
                                    sel ? el('div', { className: 'wpd-ed-orb-replace' }, mediaButton) : null)
                                : ((sel && !a.imageUrl) ? el('div', { className: 'wpd-hero__media wpd-ed-media-empty' }, mediaButton) : null))
                    )
                )
            )
        );
    }

    /* ---------- 2.7.0: «Раскладка и вид» остальных блоков ---------- */

    function lookOpts(slug) {
        return (data.lookOptions || {})[slug] || {};
    }

    /*
     * Значение параметра блока: атрибут, совпадающий со значением по умолчанию (оно не сохраняется в разметку),
     * и число 0 берутся из настроек страницы или общих (data.lookSettings) — как на сайте.
     */
    function lookValue(slug, a, attr) {
        var block = data.blocks['wpd/' + slug];
        var def = block && block.attributes[attr] ? block.attributes[attr]['default'] : undefined;
        var conf = (data.lookSettings || {})[slug] || {};
        var o = lookOpts(slug)[attr] || {};
        var v = a[attr];
        var unset = v === undefined || v === null || v === def;
        if (o.type === 'number') {
            return (unset || !(parseInt(v, 10) > 0)) ? (parseInt(conf[attr], 10) || 0) : parseInt(v, 10);
        }
        if (o.type === 'checkbox') {
            return unset ? !!conf[attr] : !!v;
        }
        return unset ? (conf[attr] !== undefined && conf[attr] !== null ? String(conf[attr]) : '') : String(v);
    }

    /* Классы и CSS-переменные корня блока — те же, что выводит WPD_Renderer::block_look() */
    function blockLook(slug, a, base, extraStyle) {
        var opts = lookOpts(slug);
        var cls = [];
        var st = Object.assign({}, extraStyle || {});
        var sel = function (attr) {
            var o = opts[attr];
            if (!o || !o.options) { return ''; }
            var v = String(lookValue(slug, a, attr));
            return v !== '' && Object.prototype.hasOwnProperty.call(o.options, v) ? v : '';
        };
        var num = function (attr) {
            var o = opts[attr];
            if (!o) { return 0; }
            var v = parseInt(lookValue(slug, a, attr), 10);
            return v > 0 ? Math.min(o.max, v) : 0;
        };
        var col = function (attr) {
            return opts[attr] ? hexColor(lookValue(slug, a, attr)) : '';
        };
        var v = sel('columns');
        if (v) { cls.push('wpd-look--cols'); st['--wpd-bl-cols'] = v; st['--wpd-bl-cols-t'] = String(Math.min(2, parseInt(v, 10))); }
        v = sel('columnsMobile');
        if (v) { cls.push('wpd-look--cols-m'); st['--wpd-bl-cols-m'] = v; }
        v = num('gap');
        if (v) { cls.push('wpd-look--gap'); st['--wpd-bl-gap'] = v + 'px'; }
        v = sel('textAlign');
        if (v) { cls.push('wpd-look--align-' + v); }
        var variant = sel('variant');
        if (variant) { cls.push('wpd-look--' + variant); }
        v = sel('radius');
        if (v) { cls.push('wpd-look--radius'); st['--wpd-radius'] = ({ none: 0, small: 6, medium: 12, large: 20 })[v] + 'px'; }
        v = num('textSize');
        if (v) { cls.push('wpd-look--fs'); st['--wpd-bl-fs'] = Math.max(11, v) + 'px'; }
        var bg = col('bgColor');
        if (bg) { cls.push('wpd-look--bg'); st['--wpd-bl-bg'] = bg; }
        v = col('accentColor');
        if (v) { cls.push('wpd-look--accent'); st['--wpd-primary'] = v; st['--wpd-accent'] = v; }
        v = col('textColor');
        if (v) { cls.push('wpd-look--color'); st['--wpd-bl-text'] = v; }
        if (bg) {
            cls.push(isDark(bg) ? 'wpd-look--ondark' : 'wpd-look--onlight');
        } else if (variant === 'solid') {
            cls.push('wpd-look--ondark');
        } else if (variant) {
            cls.push('wpd-look--onlight');
        }
        return { className: base + (cls.length ? ' wpd-look ' + cls.join(' ') : ''), style: st };
    }

    /* Число из параметра блока в пределах min…max или 0 */
    function lookPx(slug, a, attr, min, max) {
        var v = parseInt(lookValue(slug, a, attr), 10);
        return v > 0 ? Math.max(min, Math.min(max, v)) : 0;
    }

    /* Боковая панель: «Параметры блока», «Раскладка и вид», «Цвета» — по описанию полей из data.lookOptions */
    function LookPanels(slug, a, set) {
        var opts = lookOpts(slug);
        var conf = (data.lookSettings || {})[slug] || {};
        var put = function (attr, v) { var o = {}; o[attr] = v; set(o); };
        var control = function (attr) {
            var o = opts[attr];
            var cls = 'wpd-ed-look-' + attr;
            switch (o.type) {
                case 'select':
                    return el(c.SelectControl, {
                        key: attr, label: o.label, className: cls, value: String(lookValue(slug, a, attr)), options: optionList(o.options),
                        onChange: function (v) { put(attr, v); }
                    });
                case 'number':
                    return el(c.RangeControl, {
                        key: attr, label: o.label, className: cls, value: parseInt(a[attr], 10) || 0, min: 0, max: o.max, step: 1,
                        help: '0 — как в настройках (' + (parseInt(conf[attr], 10) || 0) + ').' + (o.min > 1 ? ' От ' + o.min + ' до ' + o.max + '.' : ''),
                        onChange: function (v) { put(attr, typeof v === 'number' ? v : 0); }
                    });
                case 'checkbox':
                    return el(c.ToggleControl, {
                        key: attr, label: o.label, className: cls, checked: !!lookValue(slug, a, attr), help: o.help || undefined,
                        onChange: function (v) { put(attr, !!v); }
                    });
                case 'color_opt':
                    return c.ColorPalette ? el(c.BaseControl, { key: attr, label: o.label, help: o.help || undefined, id: 'wpd-' + slug + '-' + attr, className: cls },
                        el(c.ColorPalette, {
                            colors: (data.palette || []).map(function (col, i) { return { name: 'Цвет ' + (i + 1), color: col }; }),
                            value: hexColor(lookValue(slug, a, attr)) || undefined, clearable: true, disableCustomColors: false,
                            onChange: function (v) { put(attr, v || ''); }
                        })
                    ) : el(c.TextControl, { key: attr, label: o.label + ' (#rrggbb)', value: a[attr] || '', onChange: function (v) { put(attr, v); } });
                case 'datetime':
                    return el(c.TextControl, {
                        key: attr, label: o.label, className: cls, type: 'datetime-local', help: (o.help ? o.help + ' ' : '') + (data.countdownTz || ''),
                        value: String(lookValue(slug, a, attr)).replace(' ', 'T'),
                        onChange: function (v) { put(attr, (v || '').replace('T', ' ').slice(0, 16)); }
                    });
                default:
                    return el(c.TextControl, {
                        key: attr, label: o.label, className: cls, help: o.help || undefined, value: String(lookValue(slug, a, attr)),
                        onChange: function (v) { put(attr, v); }
                    });
            }
        };
        var group = function (g) {
            return Object.keys(opts).filter(function (k) { return opts[k].group === g; }).map(control);
        };
        var extra = group('extra');
        var look = group('look');
        var colors = group('colors');
        return [
            extra.length ? el(c.PanelBody, { key: 'wpd-x', title: 'Параметры блока', initialOpen: false, className: 'wpd-ed-look-panel-extra' }, extra) : null,
            look.length ? el(c.PanelBody, { key: 'wpd-l', title: 'Раскладка и вид', initialOpen: false, className: 'wpd-ed-look-panel-layout' }, look) : null,
            colors.length ? el(c.PanelBody, { key: 'wpd-c', title: 'Цвета', initialOpen: false, className: 'wpd-ed-look-panel-colors' }, colors) : null
        ];
    }

    /* Иконка элемента: необязательная */
    function IconSelectOpt(p) {
        return el('select', {
            className: 'wpd-ed-select wpd-ed-icon-opt', value: p.value || '', 'aria-label': p.label || 'Иконка',
            onChange: function (e) { p.onChange(e.target.value); }
        }, [el('option', { key: '', value: '' }, '— без иконки —')].concat(Object.keys(data.icons || {}).map(function (key) {
            return el('option', { key: key, value: key }, data.icons[key]);
        })));
    }

    /* Состояние элемента на холсте: скрыт или показывается по датам */
    function itemFlags(it) {
        var scheduled = !!(it.from || it.to);
        return (it.hidden ? ' wpd-ed-off' : '') + (scheduled ? ' wpd-ed-scheduled' : '');
    }
    function ItemState(it, what) {
        var scheduled = !!(it.from || it.to);
        if (!it.hidden && !scheduled) {
            return null;
        }
        return el('div', { className: 'wpd-ed-hint wpd-ed-state' },
            it.hidden ? (what || 'Скрыт') + ' на сайте' : ('Показ: ' + (it.from ? 'с ' + it.from : '') + (it.to ? ' до ' + it.to : '') + ' ' + (data.countdownTz || '')));
    }

    /*
     * Дополнительные поля элемента (2.7.0) под ним, когда блок выделен: иконка, цвет, метка, подписи, флажки, скрытие и даты.
     * p.fields — какие поля показывать.
     */
    function ItemMore(p) {
        var it = p.it;
        var patch = p.patch;
        var has = function (k) { return p.fields.indexOf(k) !== -1; };
        var text = function (k, ph) {
            return el('input', {
                type: 'text', className: 'wpd-ed-urlinput wpd-ed-more-' + k, value: it[k] || '', placeholder: ph, 'aria-label': ph,
                onChange: function (e) { var o = {}; o[k] = e.target.value; patch(o); }
            });
        };
        var check = function (k, label) {
            return el(Check, { checked: it[k], label: label, onChange: function (v) { var o = {}; o[k] = v; patch(o); } });
        };
        return el('div', { className: 'wpd-ed-item wpd-ed-more' + (p.className ? ' ' + p.className : '') },
            has('icon') ? el('div', { className: 'wpd-ed-row' }, el('span', { className: 'wpd-ed-hint' }, 'Иконка:'),
                el(IconSelectOpt, { value: it.icon, onChange: function (v) { patch({ icon: v }); } })) : null,
            has('color') ? el('div', { className: 'wpd-ed-row' }, el('span', { className: 'wpd-ed-hint' }, (p.colorLabel || 'Цвет') + ':'),
                el(ColorField, { value: it.color, label: p.colorLabel || 'Цвет', onChange: function (v) { patch({ color: v }); } })) : null,
            has('badge') ? text('badge', 'Метка, например «Новое»') : null,
            has('type') ? text('type', 'Текст значка (пусто — PDF или WEB)') : null,
            has('more') ? text('more', 'Текст ссылки (пусто — как в блоке)') : null,
            has('button') ? text('button', 'Текст кнопки (нужна ссылка)') : null,
            el('div', { className: 'wpd-ed-row' },
                has('open') ? check('open', 'ответ открыт сразу') : null,
                has('new_tab') ? check('new_tab', 'в новой вкладке') : null,
                has('download') ? check('download', 'скачивать файл') : null,
                check('hidden', 'скрыть')
            ),
            el('div', { className: 'wpd-ed-row wpd-ed-dates' },
                el('label', null, 'с ', el('input', {
                    type: 'datetime-local', value: (it.from || '').replace(' ', 'T'), 'aria-label': 'Показывать с',
                    onChange: function (e) { patch({ from: e.target.value.replace('T', ' ') }); }
                })),
                el('label', null, ' до ', el('input', {
                    type: 'datetime-local', value: (it.to || '').replace(' ', 'T'), 'aria-label': 'Показывать до',
                    onChange: function (e) { patch({ to: e.target.value.replace('T', ' ') }); }
                })),
                el('span', { className: 'wpd-ed-hint' }, ' ' + (data.countdownTz || ''))
            ),
            p.tools || null
        );
    }

    /* Метка элемента */
    function Badge(text, mod) {
        return text ? el('span', { className: 'wpd-it-badge' + (mod ? ' wpd-it-badge--' + mod : '') }, text) : null;
    }

    /* Свой цвет элемента: класс тона и переменная */
    function itemColor(hex, varName) {
        var h = hexColor(hex);
        if (!h) {
            return { className: '', style: undefined };
        }
        var st = {};
        st[varName || '--wpd-it-bg'] = h;
        return { className: ' has-color ' + (isDark(h) ? 'wpd-it--dark' : 'wpd-it--light'), style: st };
    }

    /* ---------- Обратный отсчёт ---------- */

    function CountdownEdit(props) {
        var a = props.attributes;
        var set = props.setAttributes;
        var shown = formatDate(a.date);
        var shownEnd = formatDate(a.end);
        var endValid = shownEnd !== '' && (a.end || '') > (a.date || '');
        var valSize = lookPx('countdown', a, 'valueSize', 14, 64);
        var look = blockLook('countdown', a, 'wpd-countdown', valSize ? { '--wpd-bl-val': valSize + 'px' } : null);
        var dateField = function (label, key, help) {
            return el(c.TextControl, {
                label: label, type: 'datetime-local', help: help,
                value: (a[key] || '').replace(' ', 'T'),
                onChange: function (v) { var o = {}; o[key] = (v || '').replace('T', ' ').slice(0, 16); set(o); }
            });
        };

        return el(Fragment, null,
            el(be.InspectorControls, null,
                el(c.PanelBody, { title: 'Обратный отсчёт' },
                    dateField('Дата и время начала', 'date', 'Часовой пояс: ' + (data.countdownTzName || data.countdownTz || 'как в настройках сайта') + '. Меняется в общих настройках, вкладка «Таймер и эффекты».'),
                    dateField('Дата и время окончания', 'end', 'Необязательно. С наступлением начала блок считает время до окончания.'),
                    a.end && !endValid ? el('p', { className: 'wpd-ed-warn' }, 'Окончание должно быть позже начала — иначе второй таймер не показывается.') : null,
                    el(c.TextControl, {
                        label: 'Текст после окончания', value: a.doneText || '',
                        onChange: function (v) { set({ doneText: v }); }
                    })
                ),
                el(c.PanelBody, { title: 'Кнопка на время этапа', initialOpen: !!a.linkUrl },
                    el(c.TextControl, {
                        label: 'Ссылка', type: 'url', value: a.linkUrl || '', placeholder: 'https://…',
                        help: 'Видна только между началом и окончанием (без даты окончания — после начала). Пусто — кнопки нет.',
                        onChange: function (v) { set({ linkUrl: v }); }
                    }),
                    el(c.ToggleControl || c.CheckboxControl, {
                        label: 'Открывать в новой вкладке', checked: !!a.linkNewTab,
                        onChange: function (v) { set({ linkNewTab: !!v }); }
                    })
                ),
                LookPanels('countdown', a, set),
                ResetPanel('wpd/countdown', set)
            ),
            Wrapper('countdown', props.className,
                el('div', { className: look.className + (valSize ? ' wpd-countdown--size' : ''), style: look.style },
                    el('div', { className: 'wpd-countdown__main' },
                        el('p', { className: 'wpd-countdown__label' },
                            el(Plain, { value: a.label, single: true, placeholder: 'Подпись до начала', onChange: function (v) { set({ label: v }); } })
                        ),
                        el('p', { className: 'wpd-countdown__value' },
                            shown || el('span', { className: 'wpd-ed-hint' }, 'Дата не задана — укажите её в настройках блока справа. Без даты блок на сайте не выводится.')
                        ),
                        endValid ? el('div', { className: 'wpd-ed-countdown-end' },
                            el('p', { className: 'wpd-countdown__label' },
                                el(Plain, { value: a.endLabel, single: true, placeholder: 'Подпись до окончания', onChange: function (v) { set({ endLabel: v }); } })
                            ),
                            el('p', { className: 'wpd-countdown__value' }, shownEnd)
                        ) : null
                    ),
                    a.linkUrl ? el('span', { className: 'wpd-btn wpd-countdown__link' },
                        el(Inline, { value: a.linkText, placeholder: 'Текст кнопки', onChange: function (v) { set({ linkText: v }); } }),
                        ' \u2192'
                    ) : null
                )
            )
        );
    }

    /* ---------- Статистика ---------- */

    function StatsEdit(props) {
        var a = props.attributes;
        var set = props.setAttributes;
        var sel = props.isSelected;
        var items = list(a.items);
        var setItems = function (v) { set({ items: v }); };
        var numSize = lookPx('stats', a, 'numSize', 20, 96);
        var look = blockLook('stats', a, 'wpd-stats', numSize ? { '--wpd-bl-num': numSize + 'px' } : null);
        var title = lookValue('stats', a, 'title');

        return el(Fragment, null,
            el(be.InspectorControls, null,
                el(c.PanelBody, { title: 'Статистика' },
                    el('p', null, 'Число и подпись правятся прямо на карточке. Иконка, цвет, скрытие и даты показа — под карточкой, когда блок выделен.')
                ),
                LookPanels('stats', a, set),
                ResetPanel('wpd/stats', set)
            ),
            Wrapper('stats', props.className,
                el('section', { className: look.className, style: look.style },
                    (sel || title) ? el('h2', { className: 'wpd-h2' + (title ? '' : ' is-empty') },
                        el(Plain, { value: title, single: true, placeholder: 'Заголовок блока (необязательно)', onChange: function (v) { set({ title: v }); } })
                    ) : null,
                    items.length ? el('ul', { className: 'wpd-stats__grid' }, items.map(function (it, i) {
                        var patch = function (o) { setItems(updateItem(items, i, o)); };
                        var col = itemColor(it.color);
                        return el('li', { key: i, className: 'wpd-stat' + col.className + itemFlags(it), style: col.style },
                            it.icon ? el('span', { className: 'wpd-stat__icon dashicons dashicons-' + it.icon, 'aria-hidden': true }) : null,
                            el('span', { className: 'wpd-stat__num' },
                                el(Plain, { value: it.num, single: true, placeholder: '300+', label: 'Число', onChange: function (v) { patch({ num: v }); } })
                            ),
                            el('span', { className: 'wpd-stat__label' },
                                el(Plain, { value: it.label, single: true, placeholder: 'подпись', label: 'Подпись', onChange: function (v) { patch({ label: v }); } })
                            ),
                            ItemState(it, 'Скрыта'),
                            sel ? el(ItemMore, {
                                it: it, patch: patch, fields: ['icon', 'color'], colorLabel: 'Цвет карточки', className: 'wpd-ed-more--stats',
                                tools: el(Tools, { items: items, index: i, onChange: setItems })
                            }) : null
                        );
                    })) : Empty('Нет карточек — на сайте блок не выводится.'),
                    sel ? el(AddButton, {
                        label: 'Добавить карточку',
                        onClick: function () { setItems(items.concat([{ num: '', label: '', icon: '', color: '', hidden: 0, from: '', to: '' }])); }
                    }) : null
                )
            )
        );
    }

    /* ---------- Документы ---------- */

    function DocsEdit(props) {
        var a = props.attributes;
        var set = props.setAttributes;
        var sel = props.isSelected;
        var items = list(a.items);
        var setItems = function (v) { set({ items: v }); };
        var showType = !!lookValue('docs', a, 'showType');
        var look = blockLook('docs', a, 'wpd-docs' + (showType ? '' : ' wpd-docs--notype'));

        return el(Fragment, null,
            el(be.InspectorControls, null,
                el(c.PanelBody, { title: 'Значки' },
                    IconSizeControl('docs', a, set, 'Размер значков, px')
                ),
                LookPanels('docs', a, set),
                ResetPanel('wpd/docs', set)
            ),
            Wrapper('docs', { className: props.className, attributes: a },
                el('section', { className: look.className, style: look.style },
                    el('h2', { className: 'wpd-h2' },
                        el(Plain, { value: a.title, single: true, placeholder: 'Заголовок блока', onChange: function (v) { set({ title: v }); } })
                    ),
                    items.length ? el('ul', { className: 'wpd-docs__list' }, items.map(function (it, i) {
                        var patch = function (o) { setItems(updateItem(items, i, o)); };
                        var col = itemColor(it.color, '--wpd-it-icon');
                        var type = it.type ? String(it.type).slice(0, 6) : (isPdf(it.url) ? 'PDF' : 'WEB');
                        return el('li', { key: i, className: 'wpd-ed-doc' },
                            el('div', { className: 'wpd-doc' + (it.url ? '' : ' is-nolink') + (col.className ? ' has-icon-color' : '') + itemFlags(it), style: col.style },
                                showType ? el('span', { className: 'wpd-doc__icon', 'aria-hidden': true }, type) : null,
                                el('div', { className: 'wpd-doc__name wpd-ed-grow' },
                                    el(Plain, { value: it.title, single: true, placeholder: 'Название документа', onChange: function (v) { patch({ title: v }); } }),
                                    (sel || it.desc) ? el('span', { className: 'wpd-doc__desc' + (it.desc ? '' : ' is-empty') },
                                        el(Plain, { value: it.desc, single: true, placeholder: 'Подпись (необязательно)', onChange: function (v) { patch({ desc: v }); } })
                                    ) : null,
                                    sel ? el('div', { className: 'wpd-ed-url' },
                                        el('input', {
                                            type: 'url', className: 'wpd-ed-urlinput', value: it.url || '',
                                            placeholder: 'https://… (PDF или страница)', 'aria-label': 'Ссылка на документ',
                                            onChange: function (e) { patch({ url: e.target.value }); }
                                        }),
                                        el(Media, {
                                            label: 'Выбрать файл из медиатеки', icon: 'admin-media', iconOnly: true, variant: 'tertiary',
                                            onSelect: function (m) {
                                                patch({ url: m.url, title: it.title || m.title || '' });
                                            }
                                        })
                                    ) : (!it.url ? el('span', { className: 'wpd-ed-hint' }, 'Нет ссылки — на сайте не выводится') : null)
                                ),
                                Badge(it.badge, 'inline')
                            ),
                            ItemState(it),
                            sel ? el(ItemMore, {
                                it: it, patch: patch, fields: ['badge', 'type', 'color', 'new_tab', 'download'], colorLabel: 'Цвет значка', className: 'wpd-ed-more--docs',
                                tools: el(Tools, { items: items, index: i, onChange: setItems })
                            }) : null
                        );
                    })) : Empty('Нет документов — на сайте блок не выводится.'),
                    sel ? el(AddButton, {
                        label: 'Добавить документ',
                        onClick: function () { setItems(items.concat([{ title: '', url: '', desc: '', badge: '', type: '', color: '', new_tab: 0, download: 0, hidden: 0, from: '', to: '' }])); }
                    }) : null
                )
            )
        );
    }

    /* ---------- Частые вопросы ---------- */

    function FaqEdit(props) {
        var a = props.attributes;
        var set = props.setAttributes;
        var sel = props.isSelected;
        var items = list(a.items);
        var setItems = function (v) { set({ items: v }); };
        var look = blockLook('faq', a, 'wpd-faq' + (lookValue('faq', a, 'numbered') ? ' wpd-faq--numbered' : ''));

        return el(Fragment, null,
            el(be.InspectorControls, null,
                el(c.PanelBody, { title: 'Частые вопросы' },
                    el('p', null, 'Ответ поддерживает жирный, курсив и ссылки. Поиск по вопросам появляется на сайте, когда вопросов четыре и больше (если он включён в общих настройках).')
                ),
                LookPanels('faq', a, set),
                ResetPanel('wpd/faq', set)
            ),
            Wrapper('faq', props.className,
                el('section', { className: look.className, style: look.style },
                    el('h2', { className: 'wpd-h2' },
                        el(Plain, { value: a.title, single: true, placeholder: 'Заголовок блока', onChange: function (v) { set({ title: v }); } })
                    ),
                    items.length ? el('div', { className: 'wpd-faq__list' }, items.map(function (it, i) {
                        return el('div', { key: i, className: 'wpd-faq__item wpd-ed-open' + itemFlags(it) },
                            el('div', { className: 'wpd-faq__q' },
                                el(Plain, { value: it.q, single: true, placeholder: 'Вопрос', onChange: function (v) { setItems(updateItem(items, i, { q: v })); } })
                            ),
                            el(be.RichText, {
                                tagName: 'div',
                                className: 'wpd-faq__a',
                                value: String(it.a || '').replace(/\r?\n/g, '<br>'),
                                allowedFormats: ['core/bold', 'core/italic', 'core/link'],
                                placeholder: 'Ответ',
                                'aria-label': 'Ответ',
                                onChange: function (v) { setItems(updateItem(items, i, { a: v })); }
                            }),
                            ItemState(it),
                            sel ? el(ItemMore, {
                                it: it, patch: function (o) { setItems(updateItem(items, i, o)); }, fields: ['open'], className: 'wpd-ed-more--faq',
                                tools: el(Tools, { items: items, index: i, onChange: setItems })
                            }) : null
                        );
                    })) : Empty('Нет вопросов — на сайте блок не выводится.'),
                    sel ? el(AddButton, {
                        label: 'Добавить вопрос',
                        onClick: function () { setItems(items.concat([{ q: '', a: '', open: 0, hidden: 0, from: '', to: '' }])); }
                    }) : null
                )
            )
        );
    }

    /* ---------- Общие поля для компонентов 1.5.0 ---------- */

    var ServerSideRender = wp.serverSideRender;

    function UrlField(p) {
        return el('input', {
            type: 'url', className: 'wpd-ed-urlinput', value: p.value || '',
            placeholder: p.placeholder || 'https://…', 'aria-label': p.label || 'Ссылка',
            onChange: function (e) { p.onChange(e.target.value); }
        });
    }

    function Check(p) {
        return el('label', { className: 'wpd-ed-check' },
            el('input', {
                type: 'checkbox', checked: !!p.checked,
                onChange: function (e) { p.onChange(e.target.checked ? 1 : 0); }
            }),
            ' ' + p.label
        );
    }

    function ColorField(p) {
        return el('span', { className: 'wpd-ed-color' },
            el('input', {
                type: 'color', value: p.value || '#1d4ed8', 'aria-label': p.label || 'Цвет',
                onChange: function (e) { p.onChange(e.target.value); }
            }),
            p.value
                ? el(c.Button, { variant: 'link', onClick: function () { p.onChange(''); } }, 'Цвет палитры')
                : el('span', { className: 'wpd-ed-hint' }, 'цвет палитры')
        );
    }

    function IconSelect(p) {
        return el('select', {
            className: 'wpd-ed-select', value: p.value, 'aria-label': 'Иконка',
            onChange: function (e) { p.onChange(e.target.value); }
        }, Object.keys(data.icons || {}).map(function (key) {
            return el('option', { key: key, value: key }, data.icons[key]);
        }));
    }

    function ImageButtons(p) {
        return el(Fragment, null,
            el(Media, {
                label: p.value ? 'Заменить изображение' : 'Изображение', icon: 'format-image', variant: 'secondary',
                allowedTypes: ['image'],
                onSelect: function (m) { p.onChange((m.sizes && m.sizes.large && m.sizes.large.url) || m.url); }
            }),
            p.value ? el(c.Button, { variant: 'link', isDestructive: true, onClick: function () { p.onChange(''); } }, 'Убрать') : null
        );
    }

    function options(map) {
        return Object.keys(map || {}).map(function (key) {
            return { value: key, label: map[key] };
        });
    }

    /* ---------- Слайдер ---------- */

    function SliderEdit(props) {
        var a = props.attributes;
        var set = props.setAttributes;
        var sel = props.isSelected;
        var items = list(a.items);
        var setItems = function (v) { set({ items: v }); };
        var cap = lookValue('slider', a, 'captionPos');
        var look = blockLook('slider', a, 'wpd-slider wpd-slider--editor' + (cap === 'top' || cap === 'center' ? ' wpd-slider--cap-' + cap : ''),
            { '--wpd-slider-h': Math.min(a.height || 420, 360) + 'px' });
        var toggle = function (key, label) {
            return el(c.ToggleControl, {
                label: label, checked: !!a[key],
                onChange: function (v) { var o = {}; o[key] = v; set(o); }
            });
        };

        return el(Fragment, null,
            el(be.InspectorControls, null,
                el(c.PanelBody, { title: 'Слайдер' },
                    el(c.RangeControl, {
                        label: 'Высота, px', min: 200, max: 900, step: 10, value: a.height || 420,
                        onChange: function (v) { set({ height: v || 420 }); }
                    }),
                    toggle('autoplay', 'Автопрокрутка (интервал — в «Параметрах блока»)'),
                    toggle('arrows', 'Стрелки'),
                    toggle('dots', 'Точки-переключатели')
                ),
                el(c.PanelBody, { title: 'Телефоны', initialOpen: false },
                    toggle('mobile', 'Показывать на телефонах'),
                    toggle('mobileHeadings', 'Заголовки слайдов'),
                    toggle('mobileTexts', 'Тексты слайдов')
                ),
                LookPanels('slider', a, set),
                ResetPanel('wpd/slider', set)
            ),
            Wrapper('slider', props.className,
                el('section', { className: look.className, style: look.style },
                    items.length ? el('div', { className: 'wpd-slider__track wpd-ed-stack' }, items.map(function (it, i) {
                        return el('div', { key: i, className: 'wpd-slide' + (it.image ? '' : ' wpd-slide--noimg') + itemFlags(it) },
                            it.image ? el('img', { className: 'wpd-slide__img', src: it.image, alt: '' }) : null,
                            el('div', { className: 'wpd-slide__caption' },
                                it.badge ? el('span', { className: 'wpd-slide__badge' }, it.badge) : null,
                                el('h2', { className: 'wpd-slide__title' },
                                    el(Plain, { value: it.heading, single: true, placeholder: 'Заголовок слайда', onChange: function (v) { setItems(updateItem(items, i, { heading: v })); } })
                                ),
                                (sel || it.text) ? el('p', { className: 'wpd-slide__text' },
                                    el(Plain, { value: it.text, placeholder: 'Текст слайда', onChange: function (v) { setItems(updateItem(items, i, { text: v })); } })
                                ) : null,
                                (it.url && it.button) ? el('span', { className: 'wpd-btn wpd-slide__btn' }, it.button + ' \u2192') : null,
                                ItemState(it)
                            ),
                            sel ? el('div', { className: 'wpd-ed-item wpd-ed-item--top' },
                                el('strong', null, 'Слайд ' + (i + 1)),
                                el(ImageButtons, { value: it.image, onChange: function (v) { setItems(updateItem(items, i, { image: v })); } }),
                                el(UrlField, { value: it.url, label: 'Ссылка слайда', placeholder: 'Ссылка (необязательно)', onChange: function (v) { setItems(updateItem(items, i, { url: v })); } }),
                                el(Check, { checked: it.new_tab, label: 'в новой вкладке', onChange: function (v) { setItems(updateItem(items, i, { new_tab: v })); } }),
                                el(ItemMore, { it: it, patch: function (o) { setItems(updateItem(items, i, o)); }, fields: ['badge', 'button'], className: 'wpd-ed-more--slides' }),
                                el(Tools, { items: items, index: i, onChange: setItems })
                            ) : null
                        );
                    })) : Empty('Нет слайдов — на сайте блок не выводится.'),
                    sel ? el(AddButton, {
                        label: 'Добавить слайд',
                        onClick: function () { setItems(items.concat([{ image: '', heading: '', text: '', url: '', new_tab: 0, button: '', badge: '', hidden: 0, from: '', to: '' }])); }
                    }) : null
                )
            )
        );
    }

    /* ---------- Кнопки-ссылки ---------- */

    /*
     * Кнопки-ссылки (2.6.0): те же классы и CSS-переменные, что выводит WPD_Renderer::buttons_look() на сайте.
     * Атрибут, совпадающий со значением по умолчанию (оно не сохраняется в разметку), берётся из настроек страницы
     * или общих (data.buttonsSettings) — как на сайте.
     */
    function buttonsValue(a, attr) {
        var def = data.blocks['wpd/buttons'] && data.blocks['wpd/buttons'].attributes[attr]
            ? data.blocks['wpd/buttons'].attributes[attr]['default'] : undefined;
        var conf = data.buttonsSettings || {};
        var v = a[attr];
        var isNum = typeof def === 'number';
        if (v === undefined || v === null || v === def || (isNum && !(parseInt(v, 10) > 0))) {
            return conf[attr] !== undefined ? conf[attr] : (isNum ? 0 : '');
        }
        return isNum ? parseInt(v, 10) : String(v);
    }
    function hexColor(v) {
        return /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(v || '') ? v : '';
    }
    function isDark(hexv) {
        var h = hexColor(hexv);
        if (!h) {
            return false;
        }
        if (h.length === 4) {
            h = '#' + h[1] + h[1] + h[2] + h[2] + h[3] + h[3];
        }
        var r = parseInt(h.substr(1, 2), 16), g = parseInt(h.substr(3, 2), 16), b = parseInt(h.substr(5, 2), 16);
        return (0.299 * r + 0.587 * g + 0.114 * b) / 255 < 0.6;
    }
    function buttonsLook(a) {
        var opt = data.buttonsOptions || {};
        var pick = function (attr, fallback) {
            var v = buttonsValue(a, attr);
            return opt[attr] && opt[attr].options && Object.prototype.hasOwnProperty.call(opt[attr].options, v) ? v : fallback;
        };
        var int = function (attr) {
            var v = parseInt(buttonsValue(a, attr), 10);
            var max = opt[attr] ? opt[attr].max : 999;
            return v > 0 ? Math.min(max, v) : 0;
        };
        var cls = ['wpd-buttons'];
        var st = {};
        var layout = pick('layout', 'stack');
        var variant = pick('variant', 'card');
        if (layout !== 'stack') { cls.push('wpd-buttons--' + layout); }
        if (variant !== 'card') { cls.push('wpd-buttons--' + variant); }
        var cols = pick('columns', '');
        if (cols !== '') { cls.push('wpd-buttons--cols'); st['--wpd-qb-cols'] = cols; }
        var colsM = pick('columnsMobile', '');
        if (colsM !== '') { cls.push('wpd-buttons--cols-m'); st['--wpd-qb-cols-m'] = colsM; }
        var align = pick('textAlign', '');
        if (align !== '') { cls.push('wpd-buttons--align-' + align); }
        var radius = pick('radius', '');
        if (radius !== '') { st['--wpd-qb-radius'] = { none: '0px', small: '6px', medium: '12px', large: '20px', pill: '999px' }[radius]; }
        var gap = int('gap');
        if (gap) { st['--wpd-qb-gap'] = gap + 'px'; }
        var height = int('height');
        if (height) { st['--wpd-qb-h'] = height + 'px'; }
        var size = int('textSize');
        if (size) {
            size = Math.max(11, size);
            cls.push('wpd-buttons--fs');
            st['--wpd-qb-fs'] = size + 'px';
            st['--wpd-qb-fs-small'] = Math.max(11, Math.round(size * 0.85)) + 'px';
        }
        var bg = hexColor(buttonsValue(a, 'bgColor'));
        if (bg) {
            st['--wpd-qb-bg'] = bg;
            if (isDark(bg)) { cls.push('wpd-buttons--dark'); }
        }
        [['iconColor', '--wpd-qb-icon'], ['textColor', '--wpd-qb-text'], ['hoverColor', '--wpd-qb-hover']].forEach(function (pair) {
            var col = hexColor(buttonsValue(a, pair[0]));
            if (col) { st[pair[1]] = col; }
        });
        return { className: cls.join(' '), style: st };
    }
    function buttonItemLook(it) {
        var cls = '';
        var st = {};
        var bg = hexColor(it.color);
        if (bg) {
            st['--wpd-qb-bg'] = bg;
            cls += ' has-color' + (isDark(bg) ? ' wpd-qbtn--dark' : ' wpd-qbtn--light');
        }
        var ic = hexColor(it.icon_color);
        if (ic) {
            st['--wpd-qb-icon'] = ic;
            cls += ' has-icon-color';
        }
        if (it.badge) { cls += ' wpd-qbtn--badge'; }
        if (it.image) { cls += ' wpd-qbtn--img'; }
        if (it.hidden) { cls += ' wpd-qbtn--off'; }
        return { className: cls, style: st };
    }
    /* Варианты списка: пустое значение («как в настройках», «автоматически») — первым */
    function optionList(map) {
        var keys = Object.keys(map || {});
        keys.sort(function (x, y) { return (x === '' ? -1 : 0) - (y === '' ? -1 : 0); });
        return keys.map(function (k) { return { value: k, label: map[k] }; });
    }

    function ButtonsEdit(props) {
        var a = props.attributes;
        var set = props.setAttributes;
        var sel = props.isSelected;
        var items = list(a.items);
        var setItems = function (v) { set({ items: v }); };
        var opt = data.buttonsOptions || {};
        var look = buttonsLook(a);
        var title = buttonsValue(a, 'title');

        function select(attr) {
            if (!opt[attr]) { return null; }
            return el(c.SelectControl, {
                label: opt[attr].label, className: 'wpd-ed-buttons-' + attr, value: String(buttonsValue(a, attr)), options: optionList(opt[attr].options),
                onChange: function (v) { var o = {}; o[attr] = v; set(o); }
            });
        }
        function range(attr, help) {
            if (!opt[attr]) { return null; }
            return el(c.RangeControl, {
                label: opt[attr].label, className: 'wpd-ed-buttons-' + attr,
                value: parseInt(a[attr], 10) || 0, min: 0, max: opt[attr].max, step: 1,
                help: help || ('0 — как в настройках (' + (parseInt((data.buttonsSettings || {})[attr], 10) || 0) + ').'),
                onChange: function (v) { var o = {}; o[attr] = typeof v === 'number' ? v : 0; set(o); }
            });
        }
        function color(attr, help) {
            if (!opt[attr]) { return null; }
            var value = hexColor(buttonsValue(a, attr));
            var update = function (v) { var o = {}; o[attr] = v || ''; set(o); };
            return c.ColorPalette ? el(c.BaseControl, { label: opt[attr].label, help: help || opt[attr].help, id: 'wpd-buttons-' + attr, className: 'wpd-ed-buttons-' + attr },
                el(c.ColorPalette, {
                    colors: (data.palette || []).map(function (col, i) { return { name: 'Цвет ' + (i + 1), color: col }; }),
                    value: value || undefined, clearable: true, disableCustomColors: false,
                    onChange: update
                })
            ) : el(c.TextControl, { label: opt[attr].label + ' (#rrggbb)', value: a[attr] || '', placeholder: '#rrggbb', onChange: update });
        }
        function itemFields(it, i) {
            var patch = function (o) { setItems(updateItem(items, i, o)); };
            return el('div', { className: 'wpd-ed-item wpd-ed-item--buttons' },
                el('div', { className: 'wpd-ed-row' },
                    el(IconSelect, { value: it.icon, onChange: function (v) { patch({ icon: v }); } }),
                    el(ImageButtons, { value: it.image, onChange: function (v) { patch({ image: v }); } })
                ),
                el(UrlField, { value: it.url, onChange: function (v) { patch({ url: v }); } }),
                el('div', { className: 'wpd-ed-row' },
                    el(Check, { checked: it.new_tab, label: 'в новой вкладке', onChange: function (v) { patch({ new_tab: v }); } }),
                    el(Check, { checked: it.download, label: 'скачивать файл', onChange: function (v) { patch({ download: v }); } }),
                    el(Check, { checked: it.small, label: 'мелкий текст', onChange: function (v) { patch({ small: v }); } })
                ),
                el('input', {
                    type: 'text', className: 'wpd-ed-urlinput wpd-ed-badge', value: it.badge || '', placeholder: 'Метка в углу, например «Новое»',
                    'aria-label': 'Метка', onChange: function (e) { patch({ badge: e.target.value }); }
                }),
                el('div', { className: 'wpd-ed-row' },
                    el('span', { className: 'wpd-ed-hint' }, 'Плитка:'),
                    el(ColorField, { value: it.color, label: 'Цвет плитки', onChange: function (v) { patch({ color: v }); } })
                ),
                el('div', { className: 'wpd-ed-row' },
                    el('span', { className: 'wpd-ed-hint' }, 'Иконка:'),
                    el(ColorField, { value: it.icon_color, label: 'Цвет иконки', onChange: function (v) { patch({ icon_color: v }); } })
                ),
                el('div', { className: 'wpd-ed-row' },
                    el(Check, { checked: it.hidden, label: 'скрыть кнопку', onChange: function (v) { patch({ hidden: v }); } })
                ),
                el('div', { className: 'wpd-ed-row wpd-ed-dates' },
                    el('label', null, 'с ', el('input', {
                        type: 'datetime-local', value: (it.from || '').replace(' ', 'T'), 'aria-label': 'Показывать с',
                        onChange: function (e) { patch({ from: e.target.value.replace('T', ' ') }); }
                    })),
                    el('label', null, ' до ', el('input', {
                        type: 'datetime-local', value: (it.to || '').replace(' ', 'T'), 'aria-label': 'Показывать до',
                        onChange: function (e) { patch({ to: e.target.value.replace('T', ' ') }); }
                    })),
                    el('span', { className: 'wpd-ed-hint' }, ' ' + (data.countdownTz || ''))
                ),
                el(Tools, { items: items, index: i, onChange: setItems })
            );
        }

        return el(Fragment, null,
            el(be.InspectorControls, null,
                el(c.PanelBody, { title: 'Кнопки-ссылки' },
                    el('p', null, 'Текст и подпись правятся прямо на плитке. Иконка, картинка, ссылка, метка, цвета и даты показа — под плиткой, когда блок выделен. Кнопка без ссылки выводится как подпись.'),
                    IconSizeControl('buttons', a, set)
                ),
                el(c.PanelBody, { title: 'Раскладка', initialOpen: false, className: 'wpd-ed-buttons-panel-layout' },
                    select('columns'), select('columnsMobile'), range('gap'), range('height'), select('textAlign')
                ),
                el(c.PanelBody, { title: 'Вид и цвета', initialOpen: false, className: 'wpd-ed-buttons-panel-look' },
                    select('layout'), select('variant'), select('radius'), range('textSize'),
                    color('bgColor'), color('iconColor'), color('textColor'), color('hoverColor')
                ),
                ResetPanel('wpd/buttons', set)
            ),
            Wrapper('buttons', { className: props.className, attributes: a },
                el('nav', { className: look.className, style: look.style },
                    (sel || title) ? el('h2', { className: 'wpd-h2' + (title ? '' : ' is-empty') },
                        el(Plain, { value: title, single: true, placeholder: 'Заголовок блока (необязательно)', onChange: function (v) { set({ title: v }); } })
                    ) : null,
                    items.length ? el('ul', { className: 'wpd-buttons__grid' }, items.map(function (it, i) {
                        var il = buttonItemLook(it);
                        var scheduled = !!(it.from || it.to);
                        return el('li', { key: i },
                            el('div', { className: 'wpd-qbtn' + (it.small ? ' wpd-qbtn--small' : '') + il.className + (scheduled ? ' wpd-qbtn--scheduled' : ''), style: il.style },
                                it.image
                                    ? el('img', { className: 'wpd-qbtn__img', src: it.image, alt: '' })
                                    : el('span', { className: 'wpd-qbtn__icon dashicons dashicons-' + (it.icon || 'admin-links'), 'aria-hidden': true }),
                                el('span', { className: 'wpd-qbtn__body' },
                                    el('span', { className: 'wpd-qbtn__text' },
                                        el(Plain, { value: it.text, single: true, placeholder: 'Текст', onChange: function (v) { setItems(updateItem(items, i, { text: v })); } })
                                    ),
                                    (sel || it.desc) ? el('span', { className: 'wpd-qbtn__desc' + (it.desc ? '' : ' is-empty') },
                                        el(Plain, { value: it.desc, single: true, placeholder: 'Подпись (необязательно)', onChange: function (v) { setItems(updateItem(items, i, { desc: v })); } })
                                    ) : null
                                ),
                                it.badge ? el('span', { className: 'wpd-qbtn__badge' }, it.badge) : null
                            ),
                            (it.hidden || scheduled) ? el('div', { className: 'wpd-ed-hint wpd-ed-state' },
                                it.hidden ? 'Скрыта на сайте' : ('Показ: ' + (it.from ? 'с ' + it.from : '') + (it.to ? ' до ' + it.to : '') + ' ' + (data.countdownTz || ''))
                            ) : null,
                            sel ? itemFields(it, i) : null
                        );
                    })) : Empty('Нет кнопок — на сайте блок не выводится.'),
                    sel ? el(AddButton, {
                        label: 'Добавить кнопку',
                        onClick: function () { setItems(items.concat([{ icon: 'admin-links', text: '', url: '', new_tab: 0, small: 0, desc: '', badge: '', image: '', color: '', icon_color: '', download: 0, hidden: 0, from: '', to: '' }])); }
                    }) : null
                )
            )
        );
    }

    /* ---------- Карточки ---------- */

    function CardsEdit(props) {
        var a = props.attributes;
        var set = props.setAttributes;
        var sel = props.isSelected;
        var items = list(a.items);
        var setItems = function (v) { set({ items: v }); };
        var imgH = lookPx('cards', a, 'imageHeight', 40, 480);
        var look = blockLook('cards', a, 'wpd-cards' + (imgH ? ' wpd-cards--img-h' : ''), imgH ? { '--wpd-bl-img-h': imgH + 'px' } : null);
        var moreDefault = lookValue('cards', a, 'moreText');

        return el(Fragment, null,
            el(be.InspectorControls, null,
                el(c.PanelBody, { title: 'Карточки' },
                    el('p', null, 'Пустой цвет — цвета палитры по очереди: основной, акцентный, тёмный. Иконка, метка, текст ссылки, скрытие и даты показа — под карточкой, когда блок выделен.')
                ),
                LookPanels('cards', a, set),
                ResetPanel('wpd/cards', set)
            ),
            Wrapper('cards', props.className,
                el('section', { className: look.className, style: look.style },
                    (sel || a.title) ? el('h2', { className: 'wpd-h2' + (a.title ? '' : ' is-empty') },
                        el(Plain, { value: a.title, single: true, placeholder: 'Заголовок блока (необязательно)', onChange: function (v) { set({ title: v }); } })
                    ) : null,
                    items.length ? el('ul', { className: 'wpd-cards__grid' }, items.map(function (it, i) {
                        var patch = function (o) { setItems(updateItem(items, i, o)); };
                        var style = it.color ? { '--wpd-card': it.color } : undefined;
                        var more = it.more || moreDefault;
                        return el('li', { key: i, className: 'wpd-card' + (it.color ? ' has-color' : '') + (it.badge ? ' wpd-card--badge' : '') + itemFlags(it), style: style },
                            el('div', { className: 'wpd-card__link' },
                                el('span', { className: 'wpd-card__title' },
                                    it.icon ? el('span', { className: 'wpd-card__icon dashicons dashicons-' + it.icon, 'aria-hidden': true }) : null,
                                    el(Plain, { value: it.title, single: true, placeholder: 'Заголовок', onChange: function (v) { patch({ title: v }); } })
                                ),
                                it.image ? el('img', { className: 'wpd-card__img', src: it.image, alt: '' }) : null,
                                (sel || it.text) ? el('span', { className: 'wpd-card__text' },
                                    el(Plain, { value: it.text, placeholder: 'Текст', onChange: function (v) { patch({ text: v }); } })
                                ) : null,
                                it.url ? (more
                                    ? el('span', { className: 'wpd-card__more wpd-card__more--text' }, more + ' \u2192')
                                    : el('span', { className: 'wpd-card__more', 'aria-hidden': true }, '\u2192')) : null
                            ),
                            Badge(it.badge),
                            ItemState(it, 'Скрыта'),
                            sel ? el('div', { className: 'wpd-ed-item' },
                                el(ImageButtons, { value: it.image, onChange: function (v) { patch({ image: v }); } }),
                                el(ColorField, { value: it.color, onChange: function (v) { patch({ color: v }); } }),
                                el(UrlField, { value: it.url, onChange: function (v) { patch({ url: v }); } }),
                                el(Check, { checked: it.new_tab, label: 'в новой вкладке', onChange: function (v) { patch({ new_tab: v }); } }),
                                el(ItemMore, { it: it, patch: patch, fields: ['icon', 'badge', 'more'], className: 'wpd-ed-more--cards' }),
                                el(Tools, { items: items, index: i, onChange: setItems })
                            ) : null
                        );
                    })) : Empty('Нет карточек — на сайте блок не выводится.'),
                    sel ? el(AddButton, {
                        label: 'Добавить карточку',
                        onClick: function () { setItems(items.concat([{ title: '', text: '', image: '', color: '', url: '', new_tab: 0, icon: '', badge: '', more: '', hidden: 0, from: '', to: '' }])); }
                    }) : null
                )
            )
        );
    }

    /* ---------- Объявление ---------- */

    function NoticeEdit(props) {
        var a = props.attributes;
        var set = props.setAttributes;
        var sel = props.isSelected;
        var layout = data.noticeLayouts && data.noticeLayouts[a.layout] ? a.layout : 'image_text';
        var shown = (layout === 'image_text' && !a.image) ? 'text' : layout;
        var look = blockLook('notice', a, 'wpd-notice wpd-notice--' + shown.replace('_', '-'), a.color ? { '--wpd-notice-bg': a.color } : null);
        // Свой цвет фона главнее вида: тон текста — по нему (как в WPD_Renderer::notice_html)
        if (hexColor(a.color)) {
            var plain = look.className.replace(/ wpd-look--on(dark|light)\b/g, '');
            look.className = plain + (isDark(a.color)
                ? (plain.indexOf(' wpd-look') === -1 ? ' wpd-look' : '') + ' wpd-look--ondark'
                : (plain !== look.className ? ' wpd-look--onlight' : ''));
        }
        var buttonText = a.url ? lookValue('notice', a, 'buttonText') : '';

        var media = shown !== 'text' ? el('div', { className: 'wpd-notice__media' },
            a.image ? el('img', { src: a.image, alt: '' })
                : el('div', { className: 'wpd-ed-media-empty' }, el(ImageButtons, { value: '', onChange: function (v) { set({ image: v }); } }))
        ) : null;

        var body = shown !== 'image' ? el('div', { className: 'wpd-notice__body' },
            (sel || a.preTitle) ? el('p', { className: 'wpd-notice__pre' },
                el(Plain, { value: a.preTitle, single: true, placeholder: 'Текст над заголовком', onChange: function (v) { set({ preTitle: v }); } })
            ) : null,
            el('h2', { className: 'wpd-notice__title' },
                el(Plain, { value: a.title, single: true, placeholder: 'Заголовок объявления', onChange: function (v) { set({ title: v }); } })
            ),
            el(be.RichText, {
                tagName: 'div', className: 'wpd-notice__content', value: a.content || '',
                allowedFormats: ['core/bold', 'core/italic', 'core/link'],
                placeholder: 'Текст объявления', 'aria-label': 'Текст объявления',
                onChange: function (v) { set({ content: v }); }
            }),
            buttonText ? el('p', { className: 'wpd-notice__actions' }, el('span', { className: 'wpd-btn wpd-notice__btn' }, buttonText + ' \u2192')) : null
        ) : null;

        return el(Fragment, null,
            el(be.InspectorControls, null,
                el(c.PanelBody, { title: 'Объявление' },
                    el(c.SelectControl, {
                        label: 'Вид', value: layout, options: options(data.noticeLayouts),
                        onChange: function (v) { set({ layout: v }); }
                    }),
                    el('div', { className: 'wpd-ed-media-control' },
                        a.image ? el('img', { src: a.image, alt: '' }) : null,
                        el(ImageButtons, { value: a.image, onChange: function (v) { set({ image: v }); } })
                    ),
                    el(c.TextControl, {
                        label: 'Или адрес изображения (URL)', type: 'url', value: a.image || '',
                        onChange: function (v) { set({ image: v }); }
                    }),
                    el('p', null, 'Цвет фона'),
                    el(ColorField, { value: a.color, onChange: function (v) { set({ color: v }); } }),
                    el(c.TextControl, {
                        label: 'Ссылка', type: 'url', value: a.url || '',
                        help: 'Ссылкой становятся заголовок и изображение.',
                        onChange: function (v) { set({ url: v }); }
                    }),
                    el(c.ToggleControl, {
                        label: 'Открывать в новой вкладке', checked: !!a.newTab,
                        onChange: function (v) { set({ newTab: v }); }
                    })
                ),
                LookPanels('notice', a, set),
                ResetPanel('wpd/notice', set)
            ),
            Wrapper('notice', props.className,
                el('section', { className: look.className, style: look.style }, media, body)
            )
        );
    }

    /* ---------- Новости ---------- */

    function NewsEdit(props) {
        var a = props.attributes;
        var set = props.setAttributes;
        var blockProps = be.useBlockProps ? be.useBlockProps({ className: 'wpd-ed-ssr' }) : { className: 'wpd-ed-ssr' };
        var cats = (data.categories || []).map(function (cat) {
            return { value: String(cat.value), label: cat.label };
        });

        return el(Fragment, null,
            el(be.InspectorControls, null,
                el(c.PanelBody, { title: 'Новости' },
                    el(c.TextControl, { label: 'Заголовок блока', value: a.title || '', onChange: function (v) { set({ title: v }); } }),
                    el(c.SelectControl, {
                        label: 'Рубрика', value: String(a.category || 0), options: cats,
                        onChange: function (v) { set({ category: parseInt(v, 10) || 0 }); }
                    }),
                    el(c.RangeControl, {
                        label: 'Количество записей', min: 1, max: 24, value: a.count || 6,
                        onChange: function (v) { set({ count: v || 6 }); }
                    }),
                    el(c.SelectControl, {
                        label: 'Вид', value: a.layout || 'carousel', options: options(data.newsLayouts),
                        onChange: function (v) { set({ layout: v }); }
                    }),
                    el(c.TextControl, {
                        label: 'Текст ссылки на все новости', value: a.more || '',
                        help: 'Пусто — без ссылки.',
                        onChange: function (v) { set({ more: v }); }
                    })
                ),
                LookPanels('news', a, set),
                ResetPanel('wpd/news', set)
            ),
            el('div', blockProps,
                ServerSideRender
                    ? el(c.Disabled || Fragment, null, el(ServerSideRender, {
                        block: 'wpd/news',
                        attributes: a,
                        EmptyResponsePlaceholder: function () {
                            return el('div', { className: 'wpd-page wpd-page--editor', style: data.vars },
                                Empty('Записей пока нет — на сайте блок не выводится.'));
                        }
                    }))
                    : el('p', null, 'Новости: ' + (a.title || ''))
            )
        );
    }

    /* ---------- Контакты ---------- */

    function ContactsEdit(props) {
        var a = props.attributes;
        var set = props.setAttributes;
        var sel = props.isSelected;
        var any = a.org || a.address || a.city || a.phone || a.email;
        var showIcons = !!lookValue('contacts', a, 'showIcons');
        var buttonText = lookValue('contacts', a, 'buttonText');
        var look = blockLook('contacts', a, 'wpd-contacts' + (showIcons ? '' : ' wpd-contacts--noicons'));

        function row(cls, icon, fields) {
            return el('li', { className: 'wpd-contacts__' + cls },
                showIcons ? el('span', { className: 'wpd-contacts__icon dashicons dashicons-' + icon, 'aria-hidden': true }) : null,
                el('span', { className: 'wpd-ed-grow' }, fields)
            );
        }
        function field(key, placeholder) {
            return el(Plain, { value: a[key], single: true, placeholder: placeholder, label: placeholder, onChange: function (v) { var o = {}; o[key] = v; set(o); } });
        }

        return el(Fragment, null,
            el(be.InspectorControls, null,
                el(c.PanelBody, { title: 'Ссылки' },
                    el(c.TextControl, {
                        label: 'Ссылка организации', type: 'url', value: a.orgUrl || '', placeholder: 'https://…',
                        help: 'Название организации станет ссылкой. Другой сайт открывается в новой вкладке.',
                        onChange: function (v) { set({ orgUrl: v }); }
                    }),
                    el(c.TextControl, {
                        label: 'Ссылка на карту', type: 'url', value: a.map || '',
                        help: 'Адрес станет ссылкой на карту.',
                        onChange: function (v) { set({ map: v }); }
                    }),
                    el(c.TextControl, {
                        label: 'Страница «Контакты»', type: 'url', value: a.page || '',
                        help: 'Кнопка «Связаться». Пусто — кнопка ведёт на e-mail.',
                        onChange: function (v) { set({ page: v }); }
                    })
                ),
                el(c.PanelBody, { title: 'Иконки' },
                    IconSizeControl('contacts', a, set)
                ),
                LookPanels('contacts', a, set),
                ResetPanel('wpd/contacts', set)
            ),
            Wrapper('contacts', { className: props.className, attributes: a },
                el('section', { className: look.className, style: look.style },
                    (sel || a.title) ? el('h2', { className: 'wpd-h2' },
                        el(Plain, { value: a.title, single: true, placeholder: 'Заголовок блока', onChange: function (v) { set({ title: v }); } })
                    ) : null,
                    (sel || any) ? el('div', { className: 'wpd-contacts__box' },
                        el('ul', { className: 'wpd-contacts__list' },
                            (sel || a.org) ? row('org', 'building', el('span', { className: a.orgUrl ? 'wpd-ed-link' : '' }, field('org', 'Название организации'))) : null,
                            (sel || a.address || a.city) ? row('address', 'location-alt', [
                                el('span', { key: 'a', className: 'wpd-ed-line' }, field('address', 'Адрес')),
                                el('span', { key: 'c', className: 'wpd-ed-line' }, field('city', 'Индекс и город'))
                            ]) : null,
                            (sel || a.code) ? row('code', 'id', field('code', 'Код организации (необязательно)')) : null,
                            (sel || a.email) ? row('email', 'email-alt', field('email', 'E-mail')) : null,
                            (sel || a.phone) ? row('phone', 'phone', field('phone', 'Телефон')) : null
                        ),
                        ((a.page || a.email) && buttonText) ? el('span', { className: 'wpd-btn wpd-contacts__btn' }, buttonText + ' →') : null
                    ) : Empty('Контакты не заполнены — на сайте блок не выводится.')
                )
            )
        );
    }

    /* ---------- Регистрация ---------- */

    var editors = {
        hero: HeroEdit,
        countdown: CountdownEdit,
        stats: StatsEdit,
        docs: DocsEdit,
        faq: FaqEdit,
        slider: SliderEdit,
        buttons: ButtonsEdit,
        cards: CardsEdit,
        notice: NoticeEdit,
        news: NewsEdit,
        contacts: ContactsEdit
    };

    Object.keys(data.blocks).forEach(function (name) {
        var def = data.blocks[name];
        var slug = name.split('/')[1];
        if (!editors[slug] || (wp.blocks.getBlockType && wp.blocks.getBlockType(name) && wp.blocks.getBlockType(name).edit)) {
            return;
        }
        wp.blocks.registerBlockType(name, {
            apiVersion: 3,
            title: def.title,
            description: def.description,
            category: data.category,
            icon: def.icon,
            keywords: def.keywords,
            attributes: def.attributes,
            supports: { html: false, align: ['wide', 'full'] },
            example: {},
            edit: editors[slug],
            save: function () {
                return null;
            }
        });
    });
})(window.wp, window.wpdBlocks);
