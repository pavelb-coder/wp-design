// Проверка wpd.js в jsdom. Запуск из папки, где установлен jsdom:
//   npm install jsdom@24
//   node wpd-js-test.cjs <html_страницы_с_функциями> <путь_к_wpd.js> [<html_страницы_с_компонентами>]
// HTML — страница с таймером, поиском по FAQ и статистикой (например, f_features.html из phaseB).
// Третий аргумент (1.5.0) — страница со слайдером и каруселью новостей (h_components.html из phaseH).

const fs = require('fs');
const { JSDOM } = require('jsdom');

const htmlPath = process.argv[2];
const jsPath = process.argv[3];
if (!htmlPath || !jsPath) {
    console.error('usage: node wpd-js-test.cjs <page.html> <wpd.js>');
    process.exit(2);
}
const html = fs.readFileSync(htmlPath, 'utf8');
const compPath = process.argv[4];
const compHtml = compPath ? fs.readFileSync(compPath, 'utf8') : '';
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
    if (opts.layout) {
        fakeLayout(w, opts.layout);
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

// В jsdom нет раскладки: задаём ширины ленты и слайдов, scrollLeft и scrollTo с ограничением по краям
function fakeLayout(w, sizes) {
    const P = w.HTMLElement.prototype;
    const isTrack = (el) => el.hasAttribute && el.hasAttribute('data-wpd-track');
    const kind = (el) => {
        const root = el.closest && el.closest('[data-wpd-carousel]');
        return root ? root.getAttribute('data-wpd-carousel') : null;
    };
    const slideW = (track) => sizes[kind(track)] || 1000;
    Object.defineProperty(P, 'clientWidth', { configurable: true, get() { return isTrack(this) ? sizes.track : 0; } });
    Object.defineProperty(P, 'scrollWidth', { configurable: true, get() { return isTrack(this) ? this.children.length * slideW(this) : 0; } });
    Object.defineProperty(P, 'offsetWidth', { configurable: true, get() {
        return this.parentElement && isTrack(this.parentElement) ? slideW(this.parentElement) : 0;
    } });
    Object.defineProperty(P, 'offsetLeft', { configurable: true, get() {
        const p = this.parentElement;
        return p && isTrack(p) ? Array.prototype.indexOf.call(p.children, this) * slideW(p) : 0;
    } });
    Object.defineProperty(P, 'scrollLeft', { configurable: true,
        get() { return this._sl || 0; },
        set(v) { this._sl = Math.max(0, Math.min(v, this.scrollWidth - this.clientWidth)); } });
    P.scrollTo = function (o) { this.scrollLeft = typeof o === 'object' ? o.left : o; };
}

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


    // 9. Слайдер и карусель новостей (1.5.0)
    if (compHtml) {
        const layout = { track: 1000, slider: 1000, news: 340 };
        const current = (doc) => Array.prototype.map.call(doc.querySelectorAll('.wpd-slider__dot'), (d) => d.getAttribute('aria-current')).indexOf('true');
        {
            const w = await load(compHtml, { noIO: true, layout });
            const doc = w.document;
            const root = doc.querySelector('[data-wpd-carousel="slider"]');
            const track = root.querySelector('[data-wpd-track]');
            const prev = root.querySelector('[data-wpd-prev]');
            const next = root.querySelector('[data-wpd-next]');
            check('slider: marked ready', root.getAttribute('data-wpd-ready') === '1');
            check('slider: arrows shown', prev.hidden === false && next.hidden === false);
            const dots = doc.querySelectorAll('.wpd-slider__dot');
            check('slider: 3 dots created and shown', dots.length === 3 && root.querySelector('[data-wpd-dots]').hidden === false, String(dots.length));
            check('slider: first dot current', current(doc) === 0);
            check('slider: dots have labels', dots[1].getAttribute('aria-label') === 'Слайд 2');
            check('slider: autoplay on', root.getAttribute('data-wpd-autoplay') === 'on');
            next.click();
            check('slider: next -> slide 2', track.scrollLeft === 1000 && current(doc) === 1, track.scrollLeft + '/' + current(doc));
            next.click();
            next.click();
            check('slider: next on last wraps to first', track.scrollLeft === 0 && current(doc) === 0, track.scrollLeft + '/' + current(doc));
            prev.click();
            check('slider: prev on first wraps to last', track.scrollLeft === 2000 && current(doc) === 2, track.scrollLeft + '/' + current(doc));
            dots[1].click();
            check('slider: dot click goes to slide', track.scrollLeft === 1000 && current(doc) === 1);
            track.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
            check('slider: ArrowRight on track', track.scrollLeft === 2000);
            track.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true }));
            check('slider: ArrowLeft on track', track.scrollLeft === 1000);
            track.scrollLeft = 2000;
            track.dispatchEvent(new w.Event('scroll'));
            await wait(120);
            check('slider: manual scroll updates current dot', current(doc) === 2);

            const news = doc.querySelector('[data-wpd-carousel="news"]');
            const ntrack = news.querySelector('[data-wpd-track]');
            const nnext = news.querySelector('[data-wpd-next]');
            const nprev = news.querySelector('[data-wpd-prev]');
            const max = ntrack.scrollWidth - ntrack.clientWidth;
            check('news: ready, arrows shown, no dots', news.getAttribute('data-wpd-ready') === '1' && nnext.hidden === false && !news.querySelector('.wpd-slider__dot'));
            check('news: no autoplay', !news.hasAttribute('data-wpd-autoplay'));
            nnext.click();
            check('news: next scrolls by visible width (clamped)', ntrack.scrollLeft === Math.min(1000, max), ntrack.scrollLeft + ' max ' + max);
            if (max > 1000) { nnext.click(); }
            nnext.click();
            check('news: next at end returns to start', ntrack.scrollLeft === 0, String(ntrack.scrollLeft));
            nprev.click();
            check('news: prev at start jumps to end', ntrack.scrollLeft === max, String(ntrack.scrollLeft));
            w.close();
        }
        // Автопрокрутка и пауза при наведении
        {
            const fast = compHtml.replace('data-autoplay="6000"', 'data-autoplay="60"');
            const w = await load(fast, { noIO: true, layout });
            const root = w.document.querySelector('[data-wpd-carousel="slider"]');
            const track = root.querySelector('[data-wpd-track]');
            await wait(100);
            check('autoplay: advances slides', track.scrollLeft > 0, String(track.scrollLeft));
            root.dispatchEvent(new w.Event('mouseenter'));
            const at = track.scrollLeft;
            await wait(200);
            check('autoplay: paused on hover', track.scrollLeft === at, String(track.scrollLeft));
            root.dispatchEvent(new w.Event('mouseleave'));
            await wait(100);
            check('autoplay: resumes after hover', track.scrollLeft !== at);
            root.dispatchEvent(new w.Event('focusin'));
            const at2 = track.scrollLeft;
            await wait(200);
            check('autoplay: paused on focus', track.scrollLeft === at2);
            w.close();
        }
        // «Уменьшить движение» — без автопрокрутки; без data-атрибутов — без стрелок и точек; один слайд — ничего
        {
            const w = await load(compHtml, { noIO: true, layout, reduce: true });
            const root = w.document.querySelector('[data-wpd-carousel="slider"]');
            check('reduced motion: no autoplay', !root.hasAttribute('data-wpd-autoplay'));
            check('reduced motion: arrows still work', root.querySelector('[data-wpd-next]').hidden === false);
            w.close();
        }
        {
            const bare = compHtml.replace(/ data-arrows( data-dots)?/, '').replace(' data-dots', '');
            const w = await load(bare, { noIO: true, layout });
            const root = w.document.querySelector('[data-wpd-carousel="slider"]');
            check('no arrows/dots attrs: controls stay hidden', root.querySelector('[data-wpd-prev]').hidden === true && root.querySelector('[data-wpd-dots]').hidden === true && !root.querySelector('.wpd-slider__dot'));
            w.close();
        }
        {
            const w = await load(compHtml.replace(/<div class="wpd-slide(?: wpd-slide--noimg)?" role="group" aria-roledescription="slide" aria-label="[23] из 3">[\s\S]*?<\/div>(?=<div class="wpd-slide|<\/div><button)/g, ''), { noIO: true, layout });
            const root = w.document.querySelector('[data-wpd-carousel="slider"]');
            const n = root.querySelectorAll('[aria-roledescription="slide"]').length;
            check('one slide: ready but no dots, arrows hidden, no autoplay', n === 1 && root.getAttribute('data-wpd-ready') === '1' && !root.querySelector('.wpd-slider__dot') && root.querySelector('[data-wpd-next]').hidden === true && !root.hasAttribute('data-wpd-autoplay'), String(n));
            w.close();
        }
        // Без JS: первый слайд и все новости видны в разметке, управление скрыто
        {
            const dom = new JSDOM(compHtml, { runScripts: 'outside-only' });
            const doc = dom.window.document;
            check('no-JS: slider controls hidden', doc.querySelector('.wpd-slider [data-wpd-next]').hasAttribute('hidden'));
            check('no-JS: news items in markup', doc.querySelectorAll('.wpd-news__item').length >= 2);
            dom.window.close();
        }
    } else {
        console.log('SKIP carousel tests (no components page given)');
    }

    console.log('\nJS TESTS: PASS=' + pass + ' FAIL=' + fail);
    process.exit(fail ? 1 : 0);
})().catch((e) => {
    console.error(e);
    process.exit(1);
});
