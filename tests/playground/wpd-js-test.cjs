// Проверка wpd.js в jsdom. Запуск из папки, где установлен jsdom:
//   npm install jsdom@24
//   node wpd-js-test.cjs <html_страницы_с_функциями> <путь_к_wpd.js>
// HTML — страница с таймером, поиском по FAQ и статистикой (например, f_features.html из phaseB).

const fs = require('fs');
const { JSDOM } = require('jsdom');

const htmlPath = process.argv[2];
const jsPath = process.argv[3];
if (!htmlPath || !jsPath) {
    console.error('usage: node wpd-js-test.cjs <page.html> <wpd.js>');
    process.exit(2);
}
const html = fs.readFileSync(htmlPath, 'utf8');
const js = fs.readFileSync(jsPath, 'utf8');

let pass = 0;
let fail = 0;
function check(name, cond, detail) {
    if (cond) {
        pass++;
        console.log('PASS ' + name);
    } else {
        fail++;
        console.log('FAIL ' + name + (detail !== undefined ? ' :: ' + detail : ''));
    }
}

// Создаёт окно, подключает заглушки и выполняет wpd.js до события load
async function load(page, opts) {
    opts = opts || {};
    const dom = new JSDOM(page, { runScripts: 'outside-only', pretendToBeVisual: true });
    const w = dom.window;
    if (opts.noIO) {
        delete w.IntersectionObserver;
    } else {
        // Заглушка: сразу сообщаем, что элемент виден
        w.IntersectionObserver = function (cb) {
            this.observe = function (el) {
                setTimeout(function () {
                    cb([{ isIntersecting: true, target: el }], { unobserve: function () {} });
                }, 0);
            };
            this.unobserve = function () {};
        };
    }
    if (opts.reduce) {
        w.matchMedia = function () { return { matches: true, addListener: function () {}, addEventListener: function () {} }; };
    }
    w.eval(js);
    // Ждём DOMContentLoaded, на котором wpd.js запускает инициализацию
    await new Promise((r) => setTimeout(r, 30));
    return w;
}

const wait = (ms) => new Promise((r) => setTimeout(r, ms));

(async function main() {
    // 1. Обратный отсчёт
    {
        const w = await load(html, { noIO: true });
        const value = w.document.querySelector('.wpd-countdown__value');
        check('countdown: value replaced by timer', !!value && /\d+ дн\. \d+ ч \d{2} мин \d{2} сек/.test(value.textContent), value && value.textContent);
        check('countdown: timer text is not the static date', value && value.textContent.indexOf('November') === -1, value && value.textContent);
        w.close();
    }

    // 2. Прошедшая дата показывает текст из настроек
    {
        const past = html.replace(/data-target="[^"]+"/, 'data-target="2001-01-01T00:00:00+00:00"');
        const w = await load(past, { noIO: true });
        const value = w.document.querySelector('.wpd-countdown__value');
        check('countdown: past date shows done text', value && value.textContent === 'Уже прошло', value && value.textContent);
        w.close();
    }

    // 3. Поиск по FAQ
    {
        const w = await load(html, { noIO: true });
        const doc = w.document;
        const input = doc.querySelector('.wpd-faq__search');
        const items = doc.querySelectorAll('.wpd-faq__item');
        const empty = doc.querySelector('.wpd-faq__empty');
        check('faq search: input visible after JS', input && input.hidden === false);
        input.value = 'Q2'; // в f_features.html вопросы Q1..Q4
        input.dispatchEvent(new w.Event('input'));
        const visible = Array.prototype.filter.call(items, (el) => !el.hidden).length;
        check('faq search: query narrows items', visible >= 1 && visible < items.length, visible + ' of ' + items.length);
        check('faq search: empty message hidden when matches', empty && empty.hidden === true);
        input.value = 'zzzz-нет-такого';
        input.dispatchEvent(new w.Event('input'));
        const none = Array.prototype.filter.call(items, (el) => !el.hidden).length;
        check('faq search: no matches hides all items', none === 0, String(none));
        check('faq search: empty message shown', empty && empty.hidden === false);
        input.value = '';
        input.dispatchEvent(new w.Event('input'));
        const all = Array.prototype.filter.call(items, (el) => !el.hidden).length;
        check('faq search: empty query shows all', all === items.length, all + ' of ' + items.length);
        w.close();
    }

    // 4. Анимация чисел
    {
        const w = await load(html, {});
        const num = w.document.querySelector('.wpd-stat__num[data-count]');
        check('stats: animation running (value below target)', parseInt(num.textContent, 10) < 300, num.textContent);
        await wait(1700);
        check('stats: animation ends at target', num.textContent === '300+', num.textContent);
        const plain = w.document.querySelectorAll('.wpd-stat__num:not([data-count])');
        check('stats: plain value untouched', plain.length > 0 && plain[0].textContent === '12–18', plain[0] && plain[0].textContent);
        w.close();
    }

    // 5. Без IntersectionObserver числа остаются итоговыми
    {
        const w = await load(html, { noIO: true });
        const num = w.document.querySelector('.wpd-stat__num[data-count]');
        check('stats: no observer -> final value kept', num.textContent === '300+', num.textContent);
        w.close();
    }

    // 6. «Уменьшить движение» — анимации нет
    {
        const w = await load(html, { reduce: true });
        const num = w.document.querySelector('.wpd-stat__num[data-count]');
        await wait(50);
        check('reduced motion: stats keep final value', num.textContent === '300+', num.textContent);
        w.close();
    }

    // 7. Печать: все вопросы открываются и возвращаются обратно
    {
        const w = await load(html, { noIO: true });
        const items = w.document.querySelectorAll('.wpd-page .wpd-faq__item');
        items[0].open = true; // один уже открыт пользователем
        w.dispatchEvent(new w.Event('beforeprint'));
        const allOpen = Array.prototype.every.call(items, (el) => el.open);
        check('print: all FAQ items opened', allOpen);
        w.dispatchEvent(new w.Event('afterprint'));
        check('print: restored, user-opened item stays open', items[0].open === true && items[1].open === false, [items[0].open, items[1].open].join(','));
        w.close();
    }

    // 8. Без JS: статический HTML остаётся читаемым
    {
        const dom = new JSDOM(html, { runScripts: 'outside-only' });
        const doc = dom.window.document;
        check('no-JS: countdown date text present', doc.querySelector('.wpd-countdown__value').textContent.length > 0);
        check('no-JS: search input hidden', doc.querySelector('.wpd-faq__search').hasAttribute('hidden'));
        check('no-JS: stats show final numbers', doc.querySelector('.wpd-stat__num[data-count]').textContent === '300+');
        dom.window.close();
    }

    console.log('\nJS TESTS: PASS=' + pass + ' FAIL=' + fail);
    process.exit(fail ? 1 : 0);
})().catch((e) => {
    console.error(e);
    process.exit(1);
});
