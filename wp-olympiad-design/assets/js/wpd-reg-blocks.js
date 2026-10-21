/*
 * WP Olympiad Design 2.0.0 — блоки «Олимпиада: регистрация» и «Олимпиада: личный кабинет».
 * Предпросмотр рисует сервер (ServerSideRender), поля формы на холсте неактивны. Пустой текст — значение из настроек регистрации.
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
    var SSR = wp.serverSideRender;

    var TEXTS = {
        'wpd/registration': [
            { key: 'title', label: 'Заголовок' },
            { key: 'intro', label: 'Текст над формой', multi: true },
            { key: 'button', label: 'Текст кнопки' }
        ],
        'wpd/cabinet': [
            { key: 'title', label: 'Заголовок' }
        ]
    };

    function makeEdit(name) {
        return function (props) {
            var a = props.attributes;
            var defs = (data.defaults && data.defaults[name]) || {};
            var blockProps = be.useBlockProps ? be.useBlockProps() : { className: props.className };

            var controls = TEXTS[name].map(function (f) {
                return el(f.multi ? c.TextareaControl : c.TextControl, {
                    key: f.key,
                    label: f.label,
                    value: a[f.key] || '',
                    placeholder: defs[f.key] || '',
                    help: 'Пусто — текст из настроек регистрации.',
                    onChange: function (v) {
                        var o = {};
                        o[f.key] = v;
                        props.setAttributes(o);
                    }
                });
            });

            return el(Fragment, null,
                el(be.InspectorControls, null,
                    el(c.PanelBody, { title: 'Тексты' }, controls),
                    el(c.PanelBody, { title: 'Регистрация', initialOpen: false },
                        el('p', null, 'Поля формы, сроки приёма, лимит мест и письма задаются в разделе «Олимпиада → Регистрация».'),
                        el('p', null, el('a', { href: data.settingsUrl, target: '_blank', rel: 'noopener' }, 'Настройки регистрации')),
                        el('p', null, el('a', { href: data.listUrl, target: '_blank', rel: 'noopener' }, 'Список участников'))
                    )
                ),
                el('div', blockProps,
                    el(c.Disabled, null,
                        SSR ? el(SSR, { block: name, attributes: a }) : el('p', null, data.blocks[name].title)
                    )
                )
            );
        };
    }

    Object.keys(data.blocks).forEach(function (name) {
        var def = data.blocks[name];
        wp.blocks.registerBlockType(name, {
            apiVersion: 3,
            title: def.title,
            description: def.description,
            icon: def.icon,
            category: data.category,
            keywords: def.keywords,
            attributes: def.attributes,
            supports: { html: false, align: ['wide', 'full'] },
            edit: makeEdit(name),
            save: function () {
                return null;
            }
        });
    });
})(window.wp, window.wpdReg);
