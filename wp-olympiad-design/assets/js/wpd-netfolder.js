/*
 * WP Olympiad Design 2.8.0 — импорт документов из сетевой папки (SMB).
 * Вкладка «Документы»: проверка подключения (с несохранёнными полями формы), просмотр файлов с выбором новых и импорт
 * в общий список. Панель «Блоки олимпиады» в редакторе записи: «Импортировать сейчас» для своего списка страницы.
 */
(function () {
    'use strict';

    var STATUS = {
        'new': 'новый',
        changed: 'изменён — обновится',
        same: 'уже в списке',
        toobig: 'больше допустимого размера — пропускается'
    };

    function api(path, method, data) {
        if (!window.wp || !wp.apiFetch) {
            return Promise.reject(new Error('Модуль запросов WordPress (wp-api-fetch) не загружен.'));
        }
        return wp.apiFetch({ path: '/wpd/v1/netfolder/' + path, method: method, data: data });
    }

    function errText(e) {
        return (e && e.message) ? e.message : 'Запрос не выполнен.';
    }

    function spin(root, on) {
        var s = root.querySelector('[data-wpd-nf-spin]');
        if (s) {
            s.classList.toggle('is-active', !!on);
        }
    }

    function notice(box, text, type) {
        box.innerHTML = '';
        if (!text) {
            return;
        }
        var div = document.createElement('div');
        div.className = 'notice inline notice-' + (type || 'info');
        var p = document.createElement('p');
        p.textContent = text;
        div.appendChild(p);
        box.appendChild(div);
    }

    function errorsText(report) {
        var errs = report && report.errors ? report.errors : {};
        return Object.keys(errs).map(function (k) { return k + ': ' + errs[k]; }).join('; ');
    }

    /* ---------- Вкладка «Документы» ---------- */

    function initSettings(root) {
        var form = root.querySelector('[data-wpd-nf-form]');
        var result = root.querySelector('[data-wpd-nf-result]');
        var preview = root.querySelector('[data-wpd-nf-preview]');
        var tbody = root.querySelector('[data-wpd-nf-rows]');
        var all = root.querySelector('[data-wpd-nf-all]');
        var busy = false;

        function field(k) {
            var el = form ? form.querySelector('[name="wpd_netfolder[' + k + ']"]') : null;
            return el ? el.value : '';
        }

        function start() {
            if (busy) {
                return false;
            }
            busy = true;
            spin(root, true);
            root.querySelectorAll('[data-wpd-nf-test],[data-wpd-nf-scan],[data-wpd-nf-import]').forEach(function (b) { b.disabled = true; });
            return true;
        }

        function stop() {
            busy = false;
            spin(root, false);
            root.querySelectorAll('[data-wpd-nf-test],[data-wpd-nf-scan],[data-wpd-nf-import]').forEach(function (b) { b.disabled = false; });
        }

        root.addEventListener('click', function (e) {
            var btn = e.target.closest('button');
            if (!btn || !root.contains(btn)) {
                return;
            }

            if (btn.hasAttribute('data-wpd-nf-test')) {
                e.preventDefault();
                if (!start()) {
                    return;
                }
                var formData = {};
                ['path', 'user', 'pass', 'domain', 'driver', 'mount', 'sub'].forEach(function (k) { formData[k] = field(k); });
                api('test', 'POST', { form: formData }).then(function (r) {
                    var text = r.message;
                    if (r.ok) {
                        text += ' Способ: ' + ({ ext: 'PHP-расширение smbclient', cli: 'программа smbclient', mount: 'подключённая папка' }[r.driver] || r.driver)
                            + '. В папке: подпапок — ' + r.dirs + ', файлов — ' + r.files + '.';
                    }
                    notice(result, text, r.ok ? 'success' : 'error');
                }).catch(function (err) {
                    notice(result, errText(err), 'error');
                }).then(stop);
            }

            if (btn.hasAttribute('data-wpd-nf-scan')) {
                e.preventDefault();
                if (!start()) {
                    return;
                }
                api('scan', 'POST', { target: 'settings' }).then(function (r) {
                    renderPreview(r);
                }).catch(function (err) {
                    preview.hidden = true;
                    notice(result, errText(err), 'error');
                }).then(stop);
            }

            if (btn.hasAttribute('data-wpd-nf-import')) {
                e.preventDefault();
                if (!start()) {
                    return;
                }
                var only = [];
                tbody.querySelectorAll('input[data-rel]').forEach(function (c) {
                    if (c.checked) {
                        only.push(c.getAttribute('data-rel'));
                    }
                });
                api('import', 'POST', { target: 'settings', only: only }).then(function (r) {
                    var errs = errorsText(r.report);
                    notice(result, r.message + (errs ? ' ' + errs : '') + ' Страница обновится…', errs ? 'warning' : 'success');
                    preview.hidden = true;
                    // Общий список выводится формой выше — перезагружаем вкладку, чтобы увидеть строки
                    setTimeout(function () { window.location.reload(); }, 1200);
                }).catch(function (err) {
                    notice(result, errText(err), 'error');
                    stop();
                });
            }
        });

        if (all) {
            all.addEventListener('change', function () {
                tbody.querySelectorAll('input[data-rel]').forEach(function (c) { c.checked = all.checked; });
            });
        }

        function cell(tr, text, cls) {
            var td = document.createElement('td');
            td.textContent = text;
            if (cls) {
                td.className = cls;
            }
            tr.appendChild(td);
            return td;
        }

        function renderPreview(r) {
            tbody.innerHTML = '';
            var counts = { 'new': 0, changed: 0, same: 0, toobig: 0 };
            r.files.forEach(function (f) {
                counts[f.status] = (counts[f.status] || 0) + 1;
                var tr = document.createElement('tr');
                tr.className = 'is-' + f.status;
                var th = document.createElement('th');
                th.className = 'check-column';
                if (f.status === 'new') {
                    var c = document.createElement('input');
                    c.type = 'checkbox';
                    c.checked = true;
                    c.setAttribute('data-rel', f.rel);
                    c.setAttribute('aria-label', 'Импортировать ' + f.name);
                    th.appendChild(c);
                }
                tr.appendChild(th);
                cell(tr, f.rel);
                cell(tr, f.size);
                cell(tr, f.mtime);
                cell(tr, STATUS[f.status] || f.status);
                tbody.appendChild(tr);
            });
            r.gone.forEach(function (g) {
                var tr = document.createElement('tr');
                tr.className = 'is-gone';
                tr.appendChild(document.createElement('th'));
                cell(tr, g.rel);
                cell(tr, '—');
                cell(tr, '—');
                cell(tr, g.hidden ? 'нет в папке, строка уже скрыта' : 'нет в папке — строка ' + (field('gone') === 'remove' ? 'удалится' : 'скроется'));
                tbody.appendChild(tr);
            });
            var total = r.files.length + r.gone.length;
            preview.hidden = total === 0;
            if (all) {
                all.checked = true;
            }
            notice(result, total
                ? 'Папка «' + (r.sub || 'корень') + '»: новых — ' + counts['new'] + ', изменённых — ' + counts.changed + ', уже в списке — ' + counts.same
                    + (counts.toobig ? ', слишком больших — ' + counts.toobig : '') + (r.gone.length ? ', пропавших из папки — ' + r.gone.length : '') + '.'
                : 'В папке «' + (r.sub || 'корень') + '» нет подходящих файлов.', total ? 'info' : 'warning');
        }
    }

    /* ---------- Панель страницы ---------- */

    function postId() {
        if (window.wpdPageEditor && wpdPageEditor.postId) {
            return wpdPageEditor.postId;
        }
        var el = document.getElementById('post_ID');
        return el ? parseInt(el.value, 10) : 0;
    }

    function initPage(box) {
        var btn = box.querySelector('[data-wpd-nf-import]');
        var msg = box.querySelector('[data-wpd-nf-msg]');
        if (!btn) {
            return;
        }
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var id = postId();
            if (!id) {
                msg.textContent = 'Сначала сохраните черновик записи.';
                return;
            }
            btn.disabled = true;
            spin(box, true);
            msg.textContent = '';
            var sub = box.querySelector('[data-wpd-nf-sub]');
            var auto = box.querySelector('[data-wpd-nf-auto]');
            api('import', 'POST', { target: 'page', post_id: id, sub: sub ? sub.value : '', auto: auto && auto.checked ? 1 : 0 }).then(function (r) {
                var panel = box.closest('[data-wpd-custom="docs"]');
                var list = panel ? panel.querySelector('[data-wpd-list="docs"]') : null;
                if (list && typeof r.html === 'string') {
                    list.innerHTML = r.html;
                }
                var errs = errorsText(r.report);
                msg.textContent = r.message + (errs ? ' ' + errs : '') + ' Список страницы уже сохранён.';
                msg.classList.toggle('is-error', !!errs);
            }).catch(function (err) {
                msg.textContent = errText(err);
                msg.classList.add('is-error');
            }).then(function () {
                btn.disabled = false;
                spin(box, false);
            });
        });
    }

    function boot() {
        document.querySelectorAll('[data-wpd-nf]').forEach(initSettings);
        document.querySelectorAll('[data-wpd-nf-page]').forEach(initPage);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}());
