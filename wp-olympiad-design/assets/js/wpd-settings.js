/*
 * WP Olympiad Design — страница настроек «Олимпиада».
 * Повторяющиеся поля (слайды, кнопки, карточки), выбор изображений из медиатеки,
 * выбор цвета с пустым значением, палитры и превью иконок. Без JavaScript все поля остаются обычными полями формы.
 */
(function ($, l10n) {
    'use strict';

    l10n = l10n || {};

    /* Выбор цвета (Iris) с кнопкой «Очистить»: пустое значение — цвет по умолчанию */
    function initColors(scope) {
        if (!$.fn.wpColorPicker) {
            return;
        }
        $(scope).find('.wpd-color-opt').each(function () {
            if (!$(this).closest('.wp-picker-container').length && !$(this).closest('template').length) {
                $(this).wpColorPicker();
            }
        });
    }

    /* Повторяющиеся поля */
    function renumber($rep) {
        var $rows = $rep.find('[data-wpd-rep-list] > [data-wpd-rep-row]');
        $rows.each(function (i) {
            $(this).find('[data-wpd-rep-num]').first().text(i + 1);
        });
        var max = parseInt($rep.attr('data-max'), 10) || 99;
        $rep.find('[data-wpd-rep-add]').prop('disabled', $rows.length >= max);
    }

    $(document).on('click', '[data-wpd-rep-add]', function () {
        var $rep = $(this).closest('[data-wpd-rep]');
        var next = parseInt($rep.attr('data-next'), 10) || 0;
        var tpl = $rep.find('template[data-wpd-rep-tpl]').get(0);
        if (!tpl) {
            return;
        }
        var html = tpl.innerHTML.replace(/__i__/g, String(next));
        $rep.attr('data-next', next + 1);
        var $row = $(html).appendTo($rep.find('[data-wpd-rep-list]'));
        initColors($row);
        renumber($rep);
        $row.find('input[type="text"], input[type="url"], textarea').first().trigger('focus');
    });

    $(document).on('click', '[data-wpd-rep-remove]', function () {
        var $rep = $(this).closest('[data-wpd-rep]');
        var $row = $(this).closest('[data-wpd-rep-row]');
        var filled = $row.find('input[type="text"], input[type="url"], textarea').filter(function () {
            return $.trim($(this).val()) !== '';
        }).length;
        if (filled && !window.confirm(l10n.remove || 'Удалить?')) {
            return;
        }
        $row.remove();
        renumber($rep);
    });

    $(document).on('click', '[data-wpd-rep-move]', function () {
        var $row = $(this).closest('[data-wpd-rep-row]');
        if (parseInt($(this).attr('data-wpd-rep-move'), 10) < 0) {
            $row.prev('[data-wpd-rep-row]').before($row);
        } else {
            $row.next('[data-wpd-rep-row]').after($row);
        }
        renumber($row.closest('[data-wpd-rep]'));
        $(this).trigger('focus');
    });

    /* Изображение из медиатеки */
    $(document).on('click', '[data-wpd-s-pick]', function (e) {
        e.preventDefault();
        if (!window.wp || !wp.media) {
            return;
        }
        var $box = $(this).closest('[data-wpd-s-media]');
        var frame = wp.media({
            title: l10n.chooseImage || 'Изображение',
            button: { text: l10n.use || 'OK' },
            library: { type: 'image' },
            multiple: false
        });
        frame.on('select', function () {
            var m = frame.state().get('selection').first().toJSON();
            var url = (m.sizes && m.sizes.large && m.sizes.large.url) || m.url;
            $box.find('[data-wpd-s-url]').val(url).trigger('change');
        });
        frame.open();
    });

    $(document).on('change input', '[data-wpd-s-url]', function () {
        var url = $.trim($(this).val());
        var $img = $(this).closest('[data-wpd-s-media]').find('[data-wpd-s-preview]');
        $img.attr('src', url).prop('hidden', url === '');
    });

    /* Превью иконки */
    $(document).on('change', '[data-wpd-icon]', function () {
        $(this).closest('.wpd-icon-pick').find('[data-wpd-icon-preview]')
            .attr('class', 'dashicons dashicons-' + $(this).val());
    });

    /* Палитры: выбор подставляет цвета в поля, правка цвета вручную переключает на «Свои цвета» */
    var applying = false;
    $(document).on('change', '.wpd-palettes input[type="radio"]', function () {
        var colors = {};
        try {
            colors = JSON.parse($(this).attr('data-colors') || '{}');
        } catch (e) { /* ничего */ }
        applying = true;
        $.each(colors, function (key, value) {
            $('#wpd_' + key).val(value).trigger('change');
        });
        applying = false;
    });

    $(document).on('input change', '#wpd_primary, #wpd_accent, #wpd_dark, #wpd_text, #wpd_bg', function () {
        if (!applying) {
            $('.wpd-palettes input[value="custom"]').prop('checked', true);
        }
    });

    $(function () {
        initColors(document);
        $('[data-wpd-rep]').each(function () {
            renumber($(this));
        });
    });
})(jQuery, window.wpdSettings);
