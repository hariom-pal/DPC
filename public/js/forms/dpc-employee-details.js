/**
 * Employee details — the questionnaires' "ask the next question only when the
 * previous answer leads to it" (Coder Instructions, page 3 for CR, rule 2.1 for PH).
 *
 * Any element carrying `data-show-when="field=value"` is shown only while the radio
 * named `field` in the same form is checked at `value`. Purely a convenience: the
 * server clears answers a branch does not reach (EmployeeDetailsService), so a form
 * submitted without this script still records the right thing.
 *
 * External file with no inline script, because the CSP forbids one (TD-29).
 */
(function () {
    'use strict';

    function init(root) {
    root.querySelectorAll('form').forEach(function (form) {
        var dependants = form.querySelectorAll('[data-show-when]');

        if (dependants.length === 0) {
            return;
        }

        function checkedValue(name) {
            var checked = form.querySelector('input[name="' + name + '"]:checked');

            return checked ? checked.value : null;
        }

        function visible(element) {
            var rule = element.getAttribute('data-show-when').split('=');

            if (checkedValue(rule[0]) !== rule[1]) {
                return false;
            }

            // Only for the years listed (2026-11-05): a Property Return the promotion
            // rule makes mandatory.
            var years = element.getAttribute('data-only-years');
            var year = form.querySelector('[name="year"]');

            if (years !== null && (year === null || years.split(',').indexOf(year.value) === -1)) {
                return false;
            }

            // A year already justified is not asked again.
            var except = element.getAttribute('data-except-years');

            if (except && year !== null && except.split(',').indexOf(year.value) !== -1) {
                return false;
            }

            // The late-return questions only for a return submitted after 31 January
            // of its own year — the 2025 return after 31 January 2025 (2026-11-14).
            if (element.hasAttribute('data-show-if-late')) {
                var on = form.querySelector('[name="submitted_on"]');

                if (year === null || year.value === '' || on === null || on.value === '' || on.value <= parseInt(year.value, 10) + '-01-31') {
                    return false;
                }
            }

            // A question is reachable only if the question it depends on is.
            var radio = form.querySelector('input[name="' + rule[0] + '"]');
            var parent = radio ? radio.closest('[data-show-when]') : null;

            return parent === null || visible(parent);
        }

        function sync() {
            dependants.forEach(function (element) {
                var shown = visible(element);

                element.classList.toggle('d-none', !shown);
                // Required only while shown (2026-11-05).
                element.querySelectorAll('[data-required-when-shown]').forEach(function (input) {
                    input.required = shown;
                });
            });

            // A missing-record reason that makes the officer Unfit ("appointed in this
            // year") asks for no document: there is no record to produce (2026-11-14).
            form.querySelectorAll('[data-missing-record]').forEach(function (box) {
                var reason = box.querySelector('select[name="missing_reason_id"]');
                var option = reason ? reason.options[reason.selectedIndex] : null;
                var unfit = !!(option && option.hasAttribute('data-makes-unfit'));

                box.querySelectorAll('[data-missing-documents]').forEach(function (cell) {
                    cell.hidden = unfit;
                    if (unfit) {
                        cell.querySelectorAll('input').forEach(function (input) { input.required = false; });
                    }
                });
                box.querySelectorAll('[data-missing-unfit]').forEach(function (note) { note.hidden = !unfit; });
                // The CIR explanation does not apply to a year that makes the officer Unfit.
                box.querySelectorAll('[data-missing-help]').forEach(function (help) { help.hidden = unfit; });
            });
        }

        form.addEventListener('change', sync);
        form.addEventListener('input', sync);
        sync();
    });
    }

    init(document);
    // Sections saved without a page load are drawn again (2026-11-14).
    document.addEventListener('edpc:content', function (event) { init(event.detail.root); });
})();
