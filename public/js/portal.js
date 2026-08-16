/*
 * e-DPC Portal — presentation behaviour only.
 *
 * Three small things: the accessibility font-size control, the statistics
 * counters, and a busy state on submitted forms. No business behaviour, no
 * network calls, no dependencies beyond Bootstrap's own bundle.
 *
 * Everything degrades: with JavaScript off the page renders fully, the
 * statistics show their final values, and forms submit normally.
 */
(function () {
    'use strict';

    /* ------------------------------------------------ font size (A- A A+) */

    var STORE_KEY = 'edpc.fontScale';
    var root = document.documentElement;

    function applyFont(scale) {
        if (scale === 'md') {
            root.removeAttribute('data-font');
        } else {
            root.setAttribute('data-font', scale);
        }

        document.querySelectorAll('.btn-font').forEach(function (btn) {
            btn.setAttribute('aria-pressed', String(btn.dataset.font === scale));
        });

        try { window.localStorage.setItem(STORE_KEY, scale); } catch (e) { /* private mode */ }
    }

    try {
        var saved = window.localStorage.getItem(STORE_KEY);
        if (saved) { applyFont(saved); }
    } catch (e) { /* private mode — the default size is fine */ }

    document.addEventListener('click', function (event) {
        var btn = event.target.closest('.btn-font');
        if (btn) { applyFont(btn.dataset.font === 'sm' ? 'md' : btn.dataset.font); }
    });

    /* ------------------------------------------------------ stat counters */

    var counters = document.querySelectorAll('[data-count-to]');

    if (counters.length) {
        // Respect the reader's motion preference, and never leave a number
        // mid-count: if animation is off or unsupported, show the real value.
        var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        var run = function (el) {
            var target = parseInt(el.dataset.countTo, 10) || 0;

            if (reduce || !window.requestAnimationFrame || target === 0) {
                el.textContent = target.toLocaleString('en-IN');
                return;
            }

            var started = null;
            var duration = 900;

            var step = function (now) {
                if (started === null) { started = now; }
                var progress = Math.min((now - started) / duration, 1);
                // Ease-out: fast then settling, which reads as counting up
                // rather than as a bar filling.
                var eased = 1 - Math.pow(1 - progress, 3);
                el.textContent = Math.round(target * eased).toLocaleString('en-IN');
                if (progress < 1) { window.requestAnimationFrame(step); }
            };

            window.requestAnimationFrame(step);
        };

        if (window.IntersectionObserver) {
            var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        run(entry.target);
                        observer.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.4 });

            counters.forEach(function (el) { observer.observe(el); });
        } else {
            counters.forEach(run);
        }
    }

    /* ------------------------------------------- busy state on submission */

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement) || form.dataset.noBusy !== undefined) { return; }

        var button = form.querySelector('button[type="submit"], button:not([type])');
        if (!button || button.classList.contains('is-busy')) { return; }

        // Purely visual. The button is not disabled until after the browser
        // has begun the submission, so the form still posts its value.
        window.setTimeout(function () {
            button.classList.add('is-busy');
            button.setAttribute('aria-busy', 'true');
        }, 0);
    });
})();
