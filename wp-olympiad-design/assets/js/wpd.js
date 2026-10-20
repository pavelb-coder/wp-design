/*
 * WP Olympiad Design — интерактив блоков.
 * Без зависимостей. Если JavaScript отключён, страница показывает статический текст:
 * дату вместо таймера, числа без анимации, FAQ без поиска, слайдер без стрелок (листается прокруткой).
 */
(function () {
    'use strict';

    var reduceMotion = !!(window.matchMedia &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches);

    function pad(n) {
        return n < 10 ? '0' + n : String(n);
    }

    /* Обратный отсчёт */
    function initCountdown(box) {
        var target = Date.parse(box.getAttribute('data-target'));
        var doneText = box.getAttribute('data-done') || '';
        var out = box.querySelector('.wpd-countdown__value');
        if (isNaN(target) || !out) {
            return;
        }

        var timer = null;
        function tick() {
            var diff = target - Date.now();
            if (diff <= 0) {
                out.textContent = doneText;
                if (timer) {
                    clearInterval(timer);
                }
                return;
            }
            var days = Math.floor(diff / 86400000);
            var hours = Math.floor((diff % 86400000) / 3600000);
            var mins = Math.floor((diff % 3600000) / 60000);
            var secs = Math.floor((diff % 60000) / 1000);
            out.textContent = days + ' дн. ' + hours + ' ч ' + pad(mins) + ' мин ' + pad(secs) + ' сек';
        }

        tick();
        // Если дата уже прошла, tick() ничего не запустит
        if (Date.now() < target) {
            timer = setInterval(tick, 1000);
        }
    }

    /* Поиск по вопросам FAQ */
    function initFaqSearch(section) {
        var input = section.querySelector('.wpd-faq__search');
        var empty = section.querySelector('.wpd-faq__empty');
        var items = section.querySelectorAll('.wpd-faq__item');
        if (!input) {
            return;
        }

        input.hidden = false;
        input.addEventListener('input', function () {
            var query = input.value.trim().toLowerCase();
            var shown = 0;
            for (var i = 0; i < items.length; i++) {
                var matches = !query || items[i].textContent.toLowerCase().indexOf(query) !== -1;
                items[i].hidden = !matches;
                if (matches) {
                    shown++;
                }
            }
            if (empty) {
                empty.hidden = shown !== 0;
            }
        });
    }

    /* Анимация чисел статистики: запускается, когда блок появляется в экране */
    function animateNumber(el) {
        var target = parseInt(el.getAttribute('data-count'), 10);
        var suffix = el.getAttribute('data-suffix') || '';
        var duration = 1200;
        var start = null;

        function step(ts) {
            if (start === null) {
                start = ts;
            }
            var progress = Math.min((ts - start) / duration, 1);
            el.textContent = Math.round(target * progress) + suffix;
            if (progress < 1) {
                window.requestAnimationFrame(step);
            }
        }
        window.requestAnimationFrame(step);
    }

    function initStatCounters(nums) {
        if (reduceMotion || !('IntersectionObserver' in window)) {
            return; // остаются итоговые значения из HTML
        }

        for (var i = 0; i < nums.length; i++) {
            nums[i].textContent = '0' + (nums[i].getAttribute('data-suffix') || '');
        }

        var observer = new IntersectionObserver(function (entries, obs) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    obs.unobserve(entry.target);
                    animateNumber(entry.target);
                }
            });
        }, { threshold: 0.5 });

        for (var j = 0; j < nums.length; j++) {
            observer.observe(nums[j]);
        }
    }

    /* Печать: открываем все вопросы FAQ, после печати возвращаем как было */
    function initPrintFaq() {
        var opened = [];
        window.addEventListener('beforeprint', function () {
            opened = [];
            var items = document.querySelectorAll('.wpd-page .wpd-faq__item');
            for (var i = 0; i < items.length; i++) {
                if (!items[i].open) {
                    items[i].open = true;
                    opened.push(items[i]);
                }
            }
        });
        window.addEventListener('afterprint', function () {
            for (var i = 0; i < opened.length; i++) {
                opened[i].open = false;
            }
            opened = [];
        });
    }

    /*
     * Слайдер и карусель новостей. Без скрипта ленту можно пролистать прокруткой (scroll-snap);
     * скрипт показывает стрелки и точки, включает автопрокрутку и листание клавишами.
     */
    function scrollToX(track, x) {
        if (typeof track.scrollTo === 'function') {
            try {
                track.scrollTo({ left: x, behavior: reduceMotion ? 'auto' : 'smooth' });
                return;
            } catch (e) { /* старые браузеры без объекта параметров */ }
        }
        track.scrollLeft = x;
    }

    function initCarousel(root) {
        var track = root.querySelector('[data-wpd-track]');
        if (!track || root.getAttribute('data-wpd-ready')) {
            return;
        }
        var slides = track.children;
        var count = slides.length;
        root.setAttribute('data-wpd-ready', '1');
        if (count < 2) {
            return;
        }

        var isSlider = root.getAttribute('data-wpd-carousel') === 'slider';
        var prev = root.querySelector('[data-wpd-prev]');
        var next = root.querySelector('[data-wpd-next]');
        var dotsBox = root.querySelector('[data-wpd-dots]');
        var dots = [];

        function width() {
            return track.clientWidth || 1;
        }

        // Номер текущего слайда (для карусели новостей — первого видимого)
        function current() {
            var step = slides[0].offsetWidth || width();
            return Math.max(0, Math.min(count - 1, Math.round(track.scrollLeft / step)));
        }

        function atEnd() {
            return track.scrollLeft + width() >= track.scrollWidth - 2;
        }

        function goTo(i) {
            i = (i + count) % count;
            scrollToX(track, slides[i].offsetLeft);
            setActive(i);
        }

        function step(dir) {
            if (isSlider) {
                goTo(current() + dir);
                return;
            }
            // Карусель новостей: листаем на ширину видимой области, в конце — к началу
            if (dir > 0 && atEnd()) {
                scrollToX(track, 0);
            } else if (dir < 0 && track.scrollLeft <= 2) {
                scrollToX(track, track.scrollWidth);
            } else {
                scrollToX(track, track.scrollLeft + dir * width());
            }
        }

        function setActive(i) {
            for (var k = 0; k < dots.length; k++) {
                dots[k].setAttribute('aria-current', k === i ? 'true' : 'false');
            }
        }

        if (root.hasAttribute('data-arrows') && prev && next) {
            prev.hidden = false;
            next.hidden = false;
            prev.addEventListener('click', function () { step(-1); });
            next.addEventListener('click', function () { step(1); });
        }

        if (isSlider && root.hasAttribute('data-dots') && dotsBox) {
            for (var d = 0; d < count; d++) {
                var dot = document.createElement('button');
                dot.type = 'button';
                dot.className = 'wpd-slider__dot';
                dot.setAttribute('aria-label', 'Слайд ' + (d + 1));
                dot.addEventListener('click', goTo.bind(null, d));
                dotsBox.appendChild(dot);
                dots.push(dot);
            }
            dotsBox.hidden = false;
            setActive(0);
        }

        var scrollTimer = null;
        track.addEventListener('scroll', function () {
            clearTimeout(scrollTimer);
            scrollTimer = setTimeout(function () { setActive(current()); }, 80);
        });

        track.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
                e.preventDefault();
                step(e.key === 'ArrowLeft' ? -1 : 1);
            }
        });

        // Автопрокрутка: пауза при наведении, фокусе и на скрытой вкладке; без неё при «уменьшить движение»
        var delay = parseInt(root.getAttribute('data-autoplay'), 10);
        if (isSlider && delay > 0 && !reduceMotion) {
            var paused = false;
            var pause = function () { paused = true; };
            var resume = function () { paused = false; };
            root.addEventListener('mouseenter', pause);
            root.addEventListener('mouseleave', resume);
            root.addEventListener('focusin', pause);
            root.addEventListener('focusout', resume);
            root.setAttribute('data-wpd-autoplay', 'on');
            setInterval(function () {
                if (!paused && !document.hidden) {
                    step(1);
                }
            }, delay);
        }
    }

    function init() {
        initPrintFaq();

        var countdowns = document.querySelectorAll('.wpd-countdown');
        for (var i = 0; i < countdowns.length; i++) {
            initCountdown(countdowns[i]);
        }

        var faqs = document.querySelectorAll('.wpd-faq[data-wpd-search]');
        for (var j = 0; j < faqs.length; j++) {
            initFaqSearch(faqs[j]);
        }

        initStatCounters(document.querySelectorAll('.wpd-stat__num[data-count]'));

        var carousels = document.querySelectorAll('.wpd-page [data-wpd-carousel]');
        for (var k = 0; k < carousels.length; k++) {
            initCarousel(carousels[k]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
