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
        return m ? m[3] + '.' + m[2] + '.' + m[1] + ', ' + m[4] + ':' + m[5] : '';
    }

    /* Корневой контейнер с CSS-переменными из общих настроек */
    function Wrapper(slug, className) {
        var cls = 'wpd-page wpd-page--block wpd-page--editor wpd-page--' + slug;
        var props = be.useBlockProps
            ? be.useBlockProps({ className: cls, style: data.vars })
            : { className: cls + (className ? ' ' + className : ''), style: data.vars };
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

    function HeroEdit(props) {
        var a = props.attributes;
        var set = props.setAttributes;
        var sel = props.isSelected;
        var variant = data.heroStyles[a.variant] ? a.variant : 'dark';
        var styleOptions = Object.keys(data.heroStyles).map(function (key) {
            return { value: key, label: data.heroStyles[key] };
        });

        function pickImage(m) {
            var url = (m.sizes && m.sizes.large && m.sizes.large.url) || m.url;
            set({ imageUrl: url, imageId: m.id || 0 });
        }

        var mediaButton = el(Media, {
            label: a.imageUrl ? 'Заменить изображение' : 'Выбрать изображение',
            allowedTypes: ['image'],
            value: a.imageId,
            onSelect: pickImage
        });

        return el(Fragment, null,
            el(be.InspectorControls, null,
                el(c.PanelBody, { title: 'Главный блок' },
                    el(c.SelectControl, {
                        label: 'Стиль', value: variant, options: styleOptions,
                        onChange: function (v) { set({ variant: v }); }
                    }),
                    el(c.TextControl, {
                        label: 'Ссылка кнопки', type: 'url', value: a.buttonUrl || '',
                        help: 'Без ссылки кнопка на сайте не выводится.',
                        onChange: function (v) { set({ buttonUrl: v }); }
                    }),
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
                    })
                ),
                ResetPanel('wpd/hero', set)
            ),
            Wrapper('hero', props.className,
                el('section', { className: 'wpd-hero wpd-hero--' + variant },
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
                            (sel || a.buttonText) ? el('span', { className: 'wpd-btn' + (a.buttonText ? '' : ' is-empty') },
                                el(Inline, { value: a.buttonText, placeholder: 'Текст кнопки', onChange: function (v) { set({ buttonText: v }); } }),
                                ' →'
                            ) : null,
                            (sel && a.buttonText && !a.buttonUrl)
                                ? el('p', { className: 'wpd-ed-hint' }, 'Укажите ссылку кнопки в настройках блока справа.')
                                : null
                        ),
                        a.imageUrl
                            ? el('div', { className: 'wpd-hero__media' }, el('img', { src: a.imageUrl, alt: '' }))
                            : (sel ? el('div', { className: 'wpd-hero__media wpd-ed-media-empty' }, mediaButton) : null)
                    )
                )
            )
        );
    }

    /* ---------- Обратный отсчёт ---------- */

    function CountdownEdit(props) {
        var a = props.attributes;
        var set = props.setAttributes;
        var shown = formatDate(a.date);

        return el(Fragment, null,
            el(be.InspectorControls, null,
                el(c.PanelBody, { title: 'Обратный отсчёт' },
                    el(c.TextControl, {
                        label: 'Дата и время события', type: 'datetime-local',
                        value: (a.date || '').replace(' ', 'T'),
                        help: 'Часовой пояс — из настроек сайта (Настройки → Общие).',
                        onChange: function (v) { set({ date: (v || '').replace('T', ' ').slice(0, 16) }); }
                    }),
                    el(c.TextControl, {
                        label: 'Текст после окончания отсчёта', value: a.doneText || '',
                        onChange: function (v) { set({ doneText: v }); }
                    })
                ),
                ResetPanel('wpd/countdown', set)
            ),
            Wrapper('countdown', props.className,
                el('div', { className: 'wpd-countdown' },
                    el('p', { className: 'wpd-countdown__label' },
                        el(Plain, { value: a.label, single: true, placeholder: 'Подпись к таймеру', onChange: function (v) { set({ label: v }); } })
                    ),
                    el('p', { className: 'wpd-countdown__value' },
                        shown || el('span', { className: 'wpd-ed-hint' }, 'Дата не задана — укажите её в настройках блока справа. Без даты блок на сайте не выводится.')
                    )
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

        return el(Fragment, null,
            el(be.InspectorControls, null, ResetPanel('wpd/stats', set)),
            Wrapper('stats', props.className,
                el('section', { className: 'wpd-stats' },
                    items.length ? el('ul', { className: 'wpd-stats__grid' }, items.map(function (it, i) {
                        return el('li', { key: i, className: 'wpd-stat' },
                            el('span', { className: 'wpd-stat__num' },
                                el(Plain, { value: it.num, single: true, placeholder: '300+', label: 'Число', onChange: function (v) { setItems(updateItem(items, i, { num: v })); } })
                            ),
                            el('span', { className: 'wpd-stat__label' },
                                el(Plain, { value: it.label, single: true, placeholder: 'подпись', label: 'Подпись', onChange: function (v) { setItems(updateItem(items, i, { label: v })); } })
                            ),
                            sel ? el(Tools, { items: items, index: i, onChange: setItems }) : null
                        );
                    })) : Empty('Нет карточек — на сайте блок не выводится.'),
                    sel ? el(AddButton, {
                        label: 'Добавить карточку',
                        onClick: function () { setItems(items.concat([{ num: '', label: '' }])); }
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

        return el(Fragment, null,
            el(be.InspectorControls, null, ResetPanel('wpd/docs', set)),
            Wrapper('docs', props.className,
                el('section', { className: 'wpd-docs' },
                    el('h2', { className: 'wpd-h2' },
                        el(Plain, { value: a.title, single: true, placeholder: 'Заголовок блока', onChange: function (v) { set({ title: v }); } })
                    ),
                    items.length ? el('ul', { className: 'wpd-docs__list' }, items.map(function (it, i) {
                        return el('li', { key: i, className: 'wpd-ed-doc' },
                            el('div', { className: 'wpd-doc' + (it.url ? '' : ' is-nolink') },
                                el('span', { className: 'wpd-doc__icon', 'aria-hidden': true }, isPdf(it.url) ? 'PDF' : 'WEB'),
                                el('div', { className: 'wpd-doc__name wpd-ed-grow' },
                                    el(Plain, { value: it.title, single: true, placeholder: 'Название документа', onChange: function (v) { setItems(updateItem(items, i, { title: v })); } }),
                                    sel ? el('div', { className: 'wpd-ed-url' },
                                        el('input', {
                                            type: 'url', className: 'wpd-ed-urlinput', value: it.url || '',
                                            placeholder: 'https://… (PDF или страница)', 'aria-label': 'Ссылка на документ',
                                            onChange: function (e) { setItems(updateItem(items, i, { url: e.target.value })); }
                                        }),
                                        el(Media, {
                                            label: 'Выбрать файл из медиатеки', icon: 'admin-media', iconOnly: true, variant: 'tertiary',
                                            onSelect: function (m) {
                                                setItems(updateItem(items, i, { url: m.url, title: it.title || m.title || '' }));
                                            }
                                        })
                                    ) : (!it.url ? el('span', { className: 'wpd-ed-hint' }, 'Нет ссылки — на сайте не выводится') : null)
                                )
                            ),
                            sel ? el(Tools, { items: items, index: i, onChange: setItems }) : null
                        );
                    })) : Empty('Нет документов — на сайте блок не выводится.'),
                    sel ? el(AddButton, {
                        label: 'Добавить документ',
                        onClick: function () { setItems(items.concat([{ title: '', url: '' }])); }
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

        return el(Fragment, null,
            el(be.InspectorControls, null,
                el(c.PanelBody, { title: 'Частые вопросы' },
                    el('p', null, 'Ответ поддерживает жирный, курсив и ссылки. Поиск по вопросам появляется на сайте, когда вопросов четыре и больше (если он включён в общих настройках).')
                ),
                ResetPanel('wpd/faq', set)
            ),
            Wrapper('faq', props.className,
                el('section', { className: 'wpd-faq' },
                    el('h2', { className: 'wpd-h2' },
                        el(Plain, { value: a.title, single: true, placeholder: 'Заголовок блока', onChange: function (v) { set({ title: v }); } })
                    ),
                    items.length ? el('div', { className: 'wpd-faq__list' }, items.map(function (it, i) {
                        return el('div', { key: i, className: 'wpd-faq__item wpd-ed-open' },
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
                            sel ? el(Tools, { items: items, index: i, onChange: setItems }) : null
                        );
                    })) : Empty('Нет вопросов — на сайте блок не выводится.'),
                    sel ? el(AddButton, {
                        label: 'Добавить вопрос',
                        onClick: function () { setItems(items.concat([{ q: '', a: '' }])); }
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
                    toggle('autoplay', 'Автопрокрутка (каждые 6 секунд)'),
                    toggle('arrows', 'Стрелки'),
                    toggle('dots', 'Точки-переключатели')
                ),
                el(c.PanelBody, { title: 'Телефоны', initialOpen: false },
                    toggle('mobile', 'Показывать на телефонах'),
                    toggle('mobileHeadings', 'Заголовки слайдов'),
                    toggle('mobileTexts', 'Тексты слайдов')
                ),
                ResetPanel('wpd/slider', set)
            ),
            Wrapper('slider', props.className,
                el('section', { className: 'wpd-slider wpd-slider--editor', style: { '--wpd-slider-h': Math.min(a.height || 420, 360) + 'px' } },
                    items.length ? el('div', { className: 'wpd-slider__track wpd-ed-stack' }, items.map(function (it, i) {
                        return el('div', { key: i, className: 'wpd-slide' + (it.image ? '' : ' wpd-slide--noimg') },
                            it.image ? el('img', { className: 'wpd-slide__img', src: it.image, alt: '' }) : null,
                            el('div', { className: 'wpd-slide__caption' },
                                el('h2', { className: 'wpd-slide__title' },
                                    el(Plain, { value: it.heading, single: true, placeholder: 'Заголовок слайда', onChange: function (v) { setItems(updateItem(items, i, { heading: v })); } })
                                ),
                                (sel || it.text) ? el('p', { className: 'wpd-slide__text' },
                                    el(Plain, { value: it.text, placeholder: 'Текст слайда', onChange: function (v) { setItems(updateItem(items, i, { text: v })); } })
                                ) : null
                            ),
                            sel ? el('div', { className: 'wpd-ed-item wpd-ed-item--top' },
                                el('strong', null, 'Слайд ' + (i + 1)),
                                el(ImageButtons, { value: it.image, onChange: function (v) { setItems(updateItem(items, i, { image: v })); } }),
                                el(UrlField, { value: it.url, label: 'Ссылка слайда', placeholder: 'Ссылка (необязательно)', onChange: function (v) { setItems(updateItem(items, i, { url: v })); } }),
                                el(Check, { checked: it.new_tab, label: 'в новой вкладке', onChange: function (v) { setItems(updateItem(items, i, { new_tab: v })); } }),
                                el(Tools, { items: items, index: i, onChange: setItems })
                            ) : null
                        );
                    })) : Empty('Нет слайдов — на сайте блок не выводится.'),
                    sel ? el(AddButton, {
                        label: 'Добавить слайд',
                        onClick: function () { setItems(items.concat([{ image: '', heading: '', text: '', url: '', new_tab: 0 }])); }
                    }) : null
                )
            )
        );
    }

    /* ---------- Кнопки-ссылки ---------- */

    function ButtonsEdit(props) {
        var a = props.attributes;
        var set = props.setAttributes;
        var sel = props.isSelected;
        var items = list(a.items);
        var setItems = function (v) { set({ items: v }); };

        return el(Fragment, null,
            el(be.InspectorControls, null,
                el(c.PanelBody, { title: 'Кнопки-ссылки' },
                    el('p', null, 'Текст правится прямо на плитке. Иконка, ссылка и размер текста — под плиткой, когда блок выделен. Кнопка без ссылки выводится как подпись.')
                ),
                ResetPanel('wpd/buttons', set)
            ),
            Wrapper('buttons', props.className,
                el('nav', { className: 'wpd-buttons' },
                    items.length ? el('ul', { className: 'wpd-buttons__grid' }, items.map(function (it, i) {
                        return el('li', { key: i },
                            el('div', { className: 'wpd-qbtn' + (it.small ? ' wpd-qbtn--small' : '') },
                                el('span', { className: 'wpd-qbtn__icon dashicons dashicons-' + (it.icon || 'admin-links'), 'aria-hidden': true }),
                                el('span', { className: 'wpd-qbtn__text' },
                                    el(Plain, { value: it.text, single: true, placeholder: 'Текст', onChange: function (v) { setItems(updateItem(items, i, { text: v })); } })
                                )
                            ),
                            sel ? el('div', { className: 'wpd-ed-item' },
                                el(IconSelect, { value: it.icon, onChange: function (v) { setItems(updateItem(items, i, { icon: v })); } }),
                                el(UrlField, { value: it.url, onChange: function (v) { setItems(updateItem(items, i, { url: v })); } }),
                                el(Check, { checked: it.new_tab, label: 'в новой вкладке', onChange: function (v) { setItems(updateItem(items, i, { new_tab: v })); } }),
                                el(Check, { checked: it.small, label: 'мелкий текст', onChange: function (v) { setItems(updateItem(items, i, { small: v })); } }),
                                el(Tools, { items: items, index: i, onChange: setItems })
                            ) : null
                        );
                    })) : Empty('Нет кнопок — на сайте блок не выводится.'),
                    sel ? el(AddButton, {
                        label: 'Добавить кнопку',
                        onClick: function () { setItems(items.concat([{ icon: 'admin-links', text: '', url: '', new_tab: 0, small: 0 }])); }
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

        return el(Fragment, null,
            el(be.InspectorControls, null,
                el(c.PanelBody, { title: 'Карточки' },
                    el('p', null, 'Пустой цвет — цвета палитры по очереди: основной, акцентный, тёмный.')
                ),
                ResetPanel('wpd/cards', set)
            ),
            Wrapper('cards', props.className,
                el('section', { className: 'wpd-cards' },
                    (sel || a.title) ? el('h2', { className: 'wpd-h2' + (a.title ? '' : ' is-empty') },
                        el(Plain, { value: a.title, single: true, placeholder: 'Заголовок блока (необязательно)', onChange: function (v) { set({ title: v }); } })
                    ) : null,
                    items.length ? el('ul', { className: 'wpd-cards__grid' }, items.map(function (it, i) {
                        var style = it.color ? { '--wpd-card': it.color } : undefined;
                        return el('li', { key: i, className: 'wpd-card' + (it.color ? ' has-color' : ''), style: style },
                            el('div', { className: 'wpd-card__link' },
                                el('span', { className: 'wpd-card__title' },
                                    el(Plain, { value: it.title, single: true, placeholder: 'Заголовок', onChange: function (v) { setItems(updateItem(items, i, { title: v })); } })
                                ),
                                it.image ? el('img', { className: 'wpd-card__img', src: it.image, alt: '' }) : null,
                                (sel || it.text) ? el('span', { className: 'wpd-card__text' },
                                    el(Plain, { value: it.text, placeholder: 'Текст', onChange: function (v) { setItems(updateItem(items, i, { text: v })); } })
                                ) : null
                            ),
                            sel ? el('div', { className: 'wpd-ed-item' },
                                el(ImageButtons, { value: it.image, onChange: function (v) { setItems(updateItem(items, i, { image: v })); } }),
                                el(ColorField, { value: it.color, onChange: function (v) { setItems(updateItem(items, i, { color: v })); } }),
                                el(UrlField, { value: it.url, onChange: function (v) { setItems(updateItem(items, i, { url: v })); } }),
                                el(Check, { checked: it.new_tab, label: 'в новой вкладке', onChange: function (v) { setItems(updateItem(items, i, { new_tab: v })); } }),
                                el(Tools, { items: items, index: i, onChange: setItems })
                            ) : null
                        );
                    })) : Empty('Нет карточек — на сайте блок не выводится.'),
                    sel ? el(AddButton, {
                        label: 'Добавить карточку',
                        onClick: function () { setItems(items.concat([{ title: '', text: '', image: '', color: '', url: '', new_tab: 0 }])); }
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
        var style = a.color ? { '--wpd-notice-bg': a.color } : undefined;

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
            })
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
                ResetPanel('wpd/notice', set)
            ),
            Wrapper('notice', props.className,
                el('section', { className: 'wpd-notice wpd-notice--' + shown.replace('_', '-'), style: style }, media, body)
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
        var any = a.address || a.city || a.phone || a.email;

        function row(cls, icon, fields) {
            return el('li', { className: 'wpd-contacts__' + cls },
                el('span', { className: 'wpd-contacts__icon dashicons dashicons-' + icon, 'aria-hidden': true }),
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
                ResetPanel('wpd/contacts', set)
            ),
            Wrapper('contacts', props.className,
                el('section', { className: 'wpd-contacts' },
                    (sel || a.title) ? el('h2', { className: 'wpd-h2' },
                        el(Plain, { value: a.title, single: true, placeholder: 'Заголовок блока', onChange: function (v) { set({ title: v }); } })
                    ) : null,
                    (sel || any) ? el('div', { className: 'wpd-contacts__box' },
                        el('ul', { className: 'wpd-contacts__list' },
                            (sel || a.address || a.city) ? row('address', 'location-alt', [
                                el('span', { key: 'a', className: 'wpd-ed-line' }, field('address', 'Адрес')),
                                el('span', { key: 'c', className: 'wpd-ed-line' }, field('city', 'Индекс и город'))
                            ]) : null,
                            (sel || a.code) ? row('code', 'id', field('code', 'Код организации (необязательно)')) : null,
                            (sel || a.email) ? row('email', 'email-alt', field('email', 'E-mail')) : null,
                            (sel || a.phone) ? row('phone', 'phone', field('phone', 'Телефон')) : null
                        ),
                        (a.page || a.email) ? el('span', { className: 'wpd-btn wpd-contacts__btn' }, 'Связаться →') : null
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
