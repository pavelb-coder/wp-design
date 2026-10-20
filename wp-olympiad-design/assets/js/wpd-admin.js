/*
 * WP Olympiad Design — панель «Блоки олимпиады» в редакторе записи.
 * Порядок блоков, списки строк (добавить, удалить, переместить), выбор файлов из медиатеки.
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

    $(function () {
        $('[data-wpd-pe]').each(function () {
            init($(this));
        });
    });
})(jQuery);
