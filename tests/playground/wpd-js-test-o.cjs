// Фаза O (2.1.0): «Показать пароль» в wpd.js — jsdom. Запуск из папки, где установлен jsdom:
//   node wpd-js-test-o.cjs <путь_к_wpd.js>
// Разметка повторяет вывод WPD_Registration::password_html() и show_password_html().
const fs = require('fs');
const { JSDOM } = require('jsdom');

const js = fs.readFileSync(process.argv[2], 'utf8');
let pass = 0;
let fail = 0;
function check(name, cond, detail) {
    if (cond) { pass++; console.log('PASS ' + name); } else { fail++; console.log('FAIL ' + name + (detail !== undefined ? ' :: ' + detail : '')); }
}
const field = (id, name) => '<div class="wpd-reg__field"><label for="wpd-reg-' + id + '">П</label><input type="password" id="wpd-reg-' + id + '" name="' + name + '" value="" required data-wpd-pass></div>';
const toggle = '<label class="wpd-reg__check wpd-reg__showpass" hidden data-wpd-showpass><input type="checkbox"> <span>Показать пароль</span></label>';
const html = '<!doctype html><html><body><div class="wpd-page">'
    + '<form id="f1" class="wpd-reg__form">' + field('pass', 'wpd_pass') + field('pass2', 'wpd_pass2') + '<input type="text" id="other" name="x">' + toggle + '</form>'
    + '<form id="f2">' + field('c', 'wpd_pass_current') + toggle + '</form>'
    + '<div id="orphan">' + toggle + '</div>'
    + '</div></body></html>';

const dom = new JSDOM(html, { runScripts: 'outside-only', pretendToBeVisual: true });
const w = dom.window;
w.matchMedia = w.matchMedia || (() => ({ matches: false, addListener() {}, removeListener() {}, addEventListener() {}, removeEventListener() {} }));
w.eval(js);
if (w.document.readyState === 'loading') {
    w.document.dispatchEvent(new w.Event('DOMContentLoaded'));
}
const d = w.document;
const t1 = d.querySelector('#f1 [data-wpd-showpass]');
const t2 = d.querySelector('#f2 [data-wpd-showpass]');
const t3 = d.querySelector('#orphan [data-wpd-showpass]');
check('toggle shown when JS works', t1.hidden === false && t2.hidden === false);
check('toggle outside form stays hidden', t3.hidden === true);
const box = t1.querySelector('input');
box.checked = true;
box.dispatchEvent(new w.Event('change', { bubbles: true }));
check('checked: both fields of the form shown as text', d.getElementById('wpd-reg-pass').type === 'text' && d.getElementById('wpd-reg-pass2').type === 'text');
check('checked: other inputs untouched', d.getElementById('other').type === 'text' && d.getElementById('other').getAttribute('type') === 'text');
check('checked: other form untouched', d.getElementById('wpd-reg-c').type === 'password');
box.checked = false;
box.dispatchEvent(new w.Event('change', { bubbles: true }));
check('unchecked: fields hidden again', d.getElementById('wpd-reg-pass').type === 'password' && d.getElementById('wpd-reg-pass2').type === 'password');
box.checked = true;
box.dispatchEvent(new w.Event('change', { bubbles: true }));
const f1 = d.getElementById('f1');
f1.addEventListener('submit', (e) => e.preventDefault());
f1.dispatchEvent(new w.Event('submit', { bubbles: true, cancelable: true }));
check('submit: fields switched back to password', d.getElementById('wpd-reg-pass').type === 'password' && d.getElementById('wpd-reg-pass2').type === 'password');
check('submit: checkbox cleared', box.checked === false);
const box2 = t2.querySelector('input');
box2.checked = true;
box2.dispatchEvent(new w.Event('change', { bubbles: true }));
check('second form: own toggle works', d.getElementById('wpd-reg-c').type === 'text' && d.getElementById('wpd-reg-pass').type === 'password');

console.log('\nTOTAL: ' + (pass + fail) + ', FAILED: ' + fail);
