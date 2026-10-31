/*
 * e-DPC Portal — presentation behaviour only.
 *
 * Six small things: the accessibility font-size control, the statistics
 * counters, the confirmation prompt before a destructive action, the
 * submit-on-change filter selects, no paste into Confirm Password, and a busy
 * state on submitted forms. No business behaviour, no network calls, no
 * dependencies beyond Bootstrap's own bundle.
 *
 * Everything degrades: with JavaScript off the page renders fully, the
 * statistics show their final values, and forms submit normally.
 *
 * **Why the confirmations and the filter selects live here rather than in the
 * markup.** They were `onsubmit="return confirm(...)"` and
 * `onchange="this.form.submit()"` attributes until 2026-08-22 — 21 of them
 * across 20 views, several on M4's frozen screens. An inline handler is an
 * inline script as far as a browser is concerned, so every one of them had to
 * go before `script-src 'self'` could be *enforced* rather than merely
 * reported. That was TD-29, and this is half of closing it: the behaviour is
 * identical, but it is now declared in an attribute the browser reads as data
 * (`data-confirm`, `data-submit-on-change`) and executed from a file the policy
 * already trusts.
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

    /* ------------------------------- confirm before a destructive action */

    /*
     * Registered before the busy-state listener below, deliberately: a
     * cancelled submission must not leave a spinner on a button that is never
     * going anywhere. Both listeners are on `document`, so ordering here is
     * ordering at run time.
     */
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement)) { return; }

        var message = form.dataset.confirm;
        if (message === undefined || message === '') { return; }

        // Identical in effect to the `onsubmit="return confirm(...)"` this
        // replaced: a refusal stops the submission and nothing else happens.
        if (!window.confirm(message)) { event.preventDefault(); }
    });

    /* ------------------------------------ submit-on-change filter selects */

    /*
     * A `<select data-submit-on-change>` posts its own form as soon as the
     * value changes — the pattern behind the "choose a DPC" filter on the
     * circulation screen. `submit()` rather than `requestSubmit()`, because the
     * attribute it replaced called `submit()`: it bypasses validation and the
     * submit event, which is what a GET filter form wants, and it means this
     * navigation never trips the confirmation or busy handlers above.
     *
     * Every screen using it also ships a `<noscript>` submit button, so the
     * filter still works with JavaScript off.
     */
    document.addEventListener('change', function (event) {
        var el = event.target;
        if (!el || el.dataset === undefined || el.dataset.submitOnChange === undefined) { return; }
        if (el.form) { el.form.submit(); }
    });

    /* ------------------------------------- no paste into Confirm Password */

    /*
     * The confirmation of a new password must be typed (2026-10-29), so a
     * mistyped password copied from the first box cannot confirm itself. Every
     * `password_confirmation` field, and anything marked `data-no-paste`.
     */
    var NO_PASTE = 'input[name="password_confirmation"], [data-no-paste]';

    ['paste', 'drop'].forEach(function (type) {
        document.addEventListener(type, function (event) {
            var el = event.target;
            if (el && el.matches && el.matches(NO_PASTE)) { event.preventDefault(); }
        }, true);
    });

    /* ------------------------------------------- busy state on submission */

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement) || form.dataset.noBusy !== undefined) { return; }

        // A confirmation refused above already stopped this submission.
        if (event.defaultPrevented) { return; }

        var button = form.querySelector('button[type="submit"], button:not([type])');
        if (!button || button.classList.contains('is-busy')) { return; }

        // Purely visual. The button is not disabled until after the browser
        // has begun the submission, so the form still posts its value.
        window.setTimeout(function () {
            button.classList.add('is-busy');
            button.setAttribute('aria-busy', 'true');
        }, 0);
    });

    /* --------------------------- Vacancy Management: higher-post fields */

    // Shown only for a promotion to a higher post (2026-10-30).
    document.querySelectorAll('[data-higher-toggle]').forEach(function (select) {
        var fields = select.form && select.form.querySelector('[data-higher-fields]');
        if (!fields) { return; }
        var sync = function () { fields.hidden = select.value !== 'higher_post_promotion'; };
        select.addEventListener('change', sync);
        sync();
    });
})();
