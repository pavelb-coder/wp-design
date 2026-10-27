/*
 * WP Olympiad Design — панель «Блоки олимпиады» в редакторе записи.
 * Порядок блоков, списки строк (добавить, удалить, переместить), выбор файлов из медиатеки.
 * 2.4.0: своё оформление страницы (группы общих настроек), вид блоков, инструменты (копирование, сброс,
 * экспорт, импорт), предпросмотр оформления на холсте блочного редактора.
 * Работает в классическом и блочном редакторе.
 */
(function ($) {
    'use strict';

    var data = window.wpdPageEditor || { global: {}, i18n: {} };
    var t = data.i18n || {};
    var counter = 0;

    function moveItem($item, dir) {
        if (dir < 0) {
            var $prev = $item.prev();
            if ($prev.length) {
                $item.insertBefore($prev);
            }
        } else {
            var $next = $item.next();
            if ($next.length) {
                $item.insertAfter($next);
            }
        }
    }

    /* Новая строка из <template>. Уникальный индекс: порядок задаётся положением в форме. */
    function addRow($box, list, values) {
        var tpl = $box.find('template[data-wpd-tpl="' + list + '"]')[0];
        if (!tpl) {
            return $();
        }
        counter++;
        var html = tpl.innerHTML.replace(/__i__/g, 'n' + Date.now().toString(36) + counter);
        var $row = $($.parseHTML(html.trim()));
        if (values) {
            $row.find('[data-wpd-field]').each(function () {
                var key = this.getAttribute('data-wpd-field');
                if (values[key] !== undefined) {
                    this.value = values[key];
                }
            });
        }
        $box.find('[data-wpd-list="' + list + '"]').append($row);
        return $row;
    }

    function setMode($box, list, mode) {
        var custom = mode === 'custom';
        $box.find('[data-wpd-custom="' + list + '"]').prop('hidden', !custom);
        $box.find('[data-wpd-global="' + list + '"]').prop('hidden', custom);
        if (custom && !$box.find('[data-wpd-list="' + list + '"] .wpd-pe__row').length) {
            addRow($box, list).find('input, textarea').first().trigger('focus');
        }
    }

    function initSortable($box) {
        if (!$.fn.sortable) {
            return;
        }
        $box.find('[data-wpd-sortable]').sortable({
            handle: '.wpd-pe__handle',
            axis: 'y',
            items: '> li',
            placeholder: 'wpd-pe__placeholder'
        });
        $box.find('[data-wpd-list]').sortable({
            handle: '.wpd-pe__handle',
            axis: 'y',
            items: '> .wpd-pe__row',
            placeholder: 'wpd-pe__placeholder'
        });
    }

    /* Выбор из медиатеки */
    function openMedia(options, onSelect) {
        if (!window.wp || !wp.media) {
            return;
        }
        var frame = wp.media({
            title: options.title,
            button: { text: t.use || 'Выбрать' },
            library: options.type ? { type: options.type } : {},
            multiple: false
        });
        frame.on('select', function () {
            onSelect(frame.state().get('selection').first().toJSON());
        });
        frame.open();
    }

    function init($box) {
        initSortable($box);

        // Порядок блоков: стрелки
        $box.on('click', '[data-wpd-move]', function (e) {
            e.preventDefault();
            var $li = $(this).closest('li');
            moveItem($li, parseInt(this.getAttribute('data-wpd-move'), 10));
            $(this).trigger('focus');
        });

        // Блок скрыт — строка бледнее
        $box.on('change', '.wpd-pe__block input[type="checkbox"]', function () {
            $(this).closest('li').toggleClass('is-hidden', !this.checked);
        });

        // Строки списков
        $box.on('click', '[data-wpd-row-move]', function (e) {
            e.preventDefault();
            moveItem($(this).closest('.wpd-pe__row'), parseInt(this.getAttribute('data-wpd-row-move'), 10));
            $(this).trigger('focus');
        });
        $box.on('click', '[data-wpd-row-remove]', function (e) {
            e.preventDefault();
            var $row = $(this).closest('.wpd-pe__row');
            var filled = $row.find('input, textarea').filter(function () {
                return this.value.trim() !== '';
            }).length;
            if (filled && !window.confirm(t.remove || 'Удалить строку?')) {
                return;
            }
            $row.remove();
        });
        $box.on('click', '[data-wpd-add]', function (e) {
            e.preventDefault();
            addRow($box, this.getAttribute('data-wpd-add')).find('input, textarea').first().trigger('focus');
        });
        $box.on('click', '[data-wpd-copy]', function (e) {
            e.preventDefault();
            var list = this.getAttribute('data-wpd-copy');
            var items = (data.global && data.global[list]) || [];
            if (!items.length) {
                window.alert(t.emptyGlobal || 'В общих настройках этот список пуст.');
                return;
            }
            var $list = $box.find('[data-wpd-list="' + list + '"]');
            var filled = $list.find('input, textarea').filter(function () {
                return this.value.trim() !== '';
            }).length;
            if (filled && !window.confirm(t.replace || 'Заменить текущие строки?')) {
                return;
            }
            $list.empty();
            items.forEach(function (item) {
                addRow($box, list, item);
            });
        });

        // Источник списка: общие / свои
        $box.on('change', '[data-wpd-mode]', function () {
            if (this.checked) {
                setMode($box, this.getAttribute('data-wpd-mode'), this.value);
            }
        });

        // Изображение главного блока
        $box.on('click', '[data-wpd-media-pick]', function (e) {
            e.preventDefault();
            var $wrap = $(this).closest('[data-wpd-media]');
            openMedia({ title: t.chooseImage || 'Изображение', type: 'image' }, function (att) {
                var url = (att.sizes && att.sizes.medium && att.sizes.medium.url) || att.url;
                $wrap.find('[data-wpd-media-id]').val(att.id);
                $wrap.find('[data-wpd-media-preview]').attr('src', url).prop('hidden', false);
                $wrap.find('[data-wpd-media-clear]').prop('hidden', false);
            });
        });
        $box.on('click', '[data-wpd-media-clear]', function (e) {
            e.preventDefault();
            var $wrap = $(this).closest('[data-wpd-media]');
            $wrap.find('[data-wpd-media-id]').val('');
            $wrap.find('[data-wpd-media-preview]').attr('src', '').prop('hidden', true);
            $(this).prop('hidden', true);
        });

        // Файл документа
        $box.on('click', '[data-wpd-file]', function (e) {
            e.preventDefault();
            var $row = $(this).closest('.wpd-pe__row');
            openMedia({ title: t.chooseFile || 'Файл' }, function (att) {
                $row.find('[data-wpd-field="url"]').val(att.url);
                var $title = $row.find('[data-wpd-field="title"]');
                if (!$title.val().trim()) {
                    $title.val(att.title || att.filename || '');
                }
            });
        });

        // Подсказка «оформление выключено» следует за галочкой в боковой панели
        $(document).on('change', 'input[name="wpd_enabled"]', function () {
            $box.find('[data-wpd-off]').prop('hidden', this.checked);
            $box.find('[data-wpd-blocks-note]').prop('hidden', !this.checked);
        });
    }

    /* ---------------------------------------------------------------
     * 2.4.0: своё оформление, вид блоков, инструменты, предпросмотр
     * ------------------------------------------------------------- */

    function blockEditor() {
        return window.wp && wp.data && wp.data.select && wp.data.select('core/editor') ? wp.data : null;
    }

    /* Правка панели делает запись «изменённой» в блочном редакторе, иначе кнопка «Обновить» может быть неактивна */
    var dirtyTimer = 0;
    function markDirty() {
        var d = blockEditor();
        if (!d || dirtyTimer) {
            return;
        }
        dirtyTimer = window.setTimeout(function () {
            dirtyTimer = 0;
            try {
                d.dispatch('core/editor').editPost({ meta: { _wpd_rev: String(Date.now()) } });
            } catch (e) { /* мета-поле недоступно — не мешаем работе */ }
        }, 150);
    }

    /* Холст блочного редактора: документ iframe «editor-canvas» или сам документ */
    function canvasDocs() {
        var docs = [document];
        var frames = document.querySelectorAll('iframe[name="editor-canvas"], iframe.edit-site-visual-editor__editor-canvas');
        for (var i = 0; i < frames.length; i++) {
            try {
                if (frames[i].contentDocument) {
                    docs.push(frames[i].contentDocument);
                }
            } catch (e) { /* чужой источник */ }
        }
        return docs;
    }

    var MANAGED = /^(wpd-style-[a-z0-9_-]+|wpd-page--(btn-[a-z0-9_-]+|shadow-[a-z0-9_-]+|no-underline|no-hover|titles-center|alt-sections|link-color))$/;
    var lastPreview = null;
    var observers = [];

    function applyPreview(doc) {
        if (!lastPreview) {
            return;
        }
        var nodes = doc.querySelectorAll('.wpd-page');
        for (var i = 0; i < nodes.length; i++) {
            var el = nodes[i];
            if (el.closest('.wpd-pe')) {
                continue;
            }
            // 2.5.0: своё значение блока (размер иконок) из data-wpd-style сильнее оформления страницы
            var style = lastPreview.style + (el.getAttribute('data-wpd-style') || '');
            if (el.getAttribute('style') !== style) {
                el.setAttribute('style', style);
            }
            var keep = el.className.split(/\s+/).filter(function (c) {
                return c && !MANAGED.test(c);
            });
            lastPreview.classes.forEach(function (c) {
                if (keep.indexOf(c) < 0) {
                    keep.push(c);
                }
            });
            var next = keep.join(' ');
            if (el.className !== next) {
                el.className = next;
            }
        }
    }

    function watchCanvas() {
        canvasDocs().forEach(function (doc) {
            if (doc.__wpdObserved || !window.MutationObserver || !doc.body) {
                return;
            }
            doc.__wpdObserved = true;
            var mo = new MutationObserver(function () {
                applyPreview(doc);
            });
            mo.observe(doc.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'style'] });
            observers.push(mo);
        });
    }

    var previewTimer = 0;
    var previewSeq = 0;
    function requestPreview($box) {
        if (!blockEditor() || !window.wp || !wp.apiFetch) {
            return;
        }
        window.clearTimeout(previewTimer);
        previewTimer = window.setTimeout(function () {
            var seq = ++previewSeq;
            if (window.tinyMCE && window.tinyMCE.triggerSave) {
                window.tinyMCE.triggerSave();
            }
            wp.apiFetch({
                path: '/wpd/v1/preview',
                method: 'POST',
                data: { post_id: data.postId || parseInt($box.attr('data-post-id'), 10) || 0, form: $box.find(':input').serialize() }
            }).then(function (res) {
                if (seq !== previewSeq || !res) {
                    return;
                }
                lastPreview = { style: res.style || '', classes: res.classes || ['wpd-page'] };
                if (window.wpdBlocks) {
                    window.wpdBlocks.vars = res.vars || window.wpdBlocks.vars;
                    window.wpdBlocks.pageClass = (res.classes || []).filter(function (c) { return c !== 'wpd-page'; }).join(' ');
                }
                watchCanvas();
                canvasDocs().forEach(applyPreview);
                $(document).trigger('wpd:preview', [res]);
            }).catch(function () { /* предпросмотр необязателен */ });
        }, 300);
    }

    function toolsMessage($box, text, isError) {
        $box.find('[data-wpd-tools-msg]').text(text || '').toggleClass('is-error', !!isError);
    }

    function toolsRequest($box, options) {
        if (!window.wp || !wp.apiFetch) {
            return;
        }
        var id = data.postId || parseInt($box.attr('data-post-id'), 10) || 0;
        options.path = '/wpd/v1/page/' + id;
        toolsMessage($box, '…');
        wp.apiFetch(options).then(function () {
            // Перезагрузка без вопроса «Покинуть страницу?» — пользователь уже подтвердил
            window.onbeforeunload = null;
            $(window).off('beforeunload');
            window.location.reload();
        }).catch(function (err) {
            toolsMessage($box, (t.failed || 'Не получилось') + ': ' + ((err && err.message) || ''), true);
        });
    }

    function initDesign($box) {
        // Группы «Своё для этой страницы»
        $box.on('change', '[data-wpd-ovg]', function () {
            var group = this.getAttribute('data-wpd-ovg');
            var $panel = $(this).closest('[data-wpd-ovg-panel]');
            $box.find('[data-wpd-ovg-body="' + group + '"]').prop('hidden', !this.checked);
            $panel.toggleClass('is-on', this.checked).find('[data-wpd-ovg-badge]').prop('hidden', !this.checked);
        });

        // Вид блока
        $box.on('click', '[data-wpd-bs-toggle]', function (e) {
            e.preventDefault();
            var $bs = $(this).closest('li').find('[data-wpd-bs]');
            var open = $bs.prop('hidden');
            $bs.prop('hidden', !open);
            this.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        $box.on('change input', '[data-wpd-bs] :input', function () {
            var $li = $(this).closest('li');
            var set = $li.find('[data-wpd-bs] :input').filter(function () {
                return $.trim($(this).val()) !== '';
            }).length;
            $li.toggleClass('has-bstyle', set > 0);
        });

        // Стиль оформления страницы: стиль главного блока меняется так же, как при сохранении
        $box.on('change', '#wpd_pe_ov_design_style', function () {
            var $hero = $box.find('#wpd_pe_style');
            if (this.value === 'soft') {
                $hero.val('soft');
            } else if ($hero.val() === 'soft') {
                $hero.val('dark');
            }
        });

        // Любая правка панели: отметка «изменено» и предпросмотр
        $box.on('change input', ':input', function () {
            markDirty();
            requestPreview($box);
        });
        $box.on('click', '[data-wpd-rep-add], [data-wpd-rep-remove], [data-wpd-rep-move], [data-wpd-move], [data-wpd-row-remove], [data-wpd-row-move], [data-wpd-add]', function () {
            markDirty();
        });
        $box.on('sortupdate', function () {
            markDirty();
        });
        $(document).on('change', 'input[name="wpd_enabled"]', markDirty);
        // Выбор цвета (Iris) меняет поле без события change
        $box.on('irischange irisclear', function () {
            window.setTimeout(function () {
                markDirty();
                requestPreview($box);
            }, 0);
        });

        // Инструменты
        $box.on('click', '[data-wpd-copy-page]', function (e) {
            e.preventDefault();
            var from = parseInt($box.find('[data-wpd-copy-from]').val(), 10) || 0;
            if (!from) {
                toolsMessage($box, t.chooseSource || 'Выберите страницу.', true);
                return;
            }
            if (window.confirm(t.copyConfirm || 'Заменить настройки?')) {
                toolsRequest($box, { method: 'POST', data: { from: from } });
            }
        });
        $box.on('click', '[data-wpd-reset-page]', function (e) {
            e.preventDefault();
            if (window.confirm(t.resetConfirm || 'Сбросить?')) {
                toolsRequest($box, { method: 'DELETE' });
            }
        });
        $box.on('click', '[data-wpd-import]', function (e) {
            e.preventDefault();
            var raw = $.trim($box.find('[data-wpd-import-in]').val());
            if (!raw) {
                toolsMessage($box, t.emptyImport || 'Вставьте JSON.', true);
                return;
            }
            if (window.confirm(t.importConfirm || 'Импортировать?')) {
                toolsRequest($box, { method: 'POST', data: { data: raw } });
            }
        });
        $box.on('click', '[data-wpd-export]', function (e) {
            e.preventDefault();
            if (!window.wp || !wp.apiFetch) {
                return;
            }
            var id = data.postId || parseInt($box.attr('data-post-id'), 10) || 0;
            wp.apiFetch({ path: '/wpd/v1/page/' + id }).then(function (res) {
                var json = JSON.stringify(res, null, 2);
                $box.find('[data-wpd-export-out]').val(json).prop('hidden', false);
                $box.find('[data-wpd-export-copy], [data-wpd-export-download]').prop('hidden', false);
                toolsMessage($box, '');
            }).catch(function (err) {
                toolsMessage($box, (t.failed || 'Не получилось') + ': ' + ((err && err.message) || ''), true);
            });
        });
        $box.on('click', '[data-wpd-export-copy]', function (e) {
            e.preventDefault();
            var $out = $box.find('[data-wpd-export-out]');
            var done = function () { toolsMessage($box, t.copied || 'Скопировано'); };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText($out.val()).then(done, function () {
                    $out.trigger('select');
                    document.execCommand('copy');
                    done();
                });
            } else {
                $out.trigger('select');
                document.execCommand('copy');
                done();
            }
        });
        $box.on('click', '[data-wpd-export-download]', function (e) {
            e.preventDefault();
            var blob = new Blob([$box.find('[data-wpd-export-out]').val()], { type: 'application/json' });
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'wpd-page-' + (data.postId || 'export') + '.json';
            document.body.appendChild(a);
            a.click();
            window.setTimeout(function () {
                URL.revokeObjectURL(a.href);
                a.remove();
            }, 500);
        });

        // Первый предпросмотр, когда холст готов: значения панели могли отличаться от сохранённых (например, после «Назад»)
        if (blockEditor()) {
            window.setTimeout(function () {
                watchCanvas();
            }, 1500);
        }
    }

    // Для боковой колонки: запросить предпросмотр после её правок
    window.wpdPagePreview = function () {
        $('[data-wpd-pe]').each(function () {
            requestPreview($(this));
        });
    };
    window.wpdMarkDirty = markDirty;

    $(function () {
        $('[data-wpd-pe]').each(function () {
            init($(this));
            initDesign($(this));
        });
    });
})(jQuery);
