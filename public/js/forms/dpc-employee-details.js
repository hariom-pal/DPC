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

            // A year already justified is shown, but not asked again (2026-11-16): its
            // reason and document can be replaced, and need not be (see alreadyJustified).

            // The late-return questions only for a return submitted after 31 January
            // of the next year — the 2025 return after 31 January 2026 (2026-11-16).
            if (element.hasAttribute('data-show-if-late')) {
                var on = form.querySelector('[name="submitted_on"]');

                if (year === null || year.value === '' || on === null || on.value === '' || on.value <= (parseInt(year.value, 10) + 1) + '-01-31') {
                    return false;
                }
            }

            // A question is reachable only if the question it depends on is.
            var radio = form.querySelector('input[name="' + rule[0] + '"]');
            var parent = radio ? radio.closest('[data-show-when]') : null;

            return parent === null || visible(parent);
        }

        function alreadyJustified(element) {
            var except = element.getAttribute('data-except-years');
            var year = form.querySelector('[name="year"]');

            return !!(except && year !== null && except.split(',').indexOf(year.value) !== -1);
        }

        function sync() {
            dependants.forEach(function (element) {
                var shown = visible(element);
                var justified = alreadyJustified(element);

                element.classList.toggle('d-none', !shown);
                // Required only while shown (2026-11-05), and not for a year whose reason
                // and document are already recorded.
                element.querySelectorAll('[data-required-when-shown]').forEach(function (input) {
                    input.required = shown && !justified;
                });
                element.querySelectorAll('[data-justified-note]').forEach(function (note) {
                    note.hidden = !justified;
                });
            });

            // No submission date before 1 January of the Property File's year (2026-11-16).
            form.querySelectorAll('[data-min-from-year]').forEach(function (input) {
                var yearField = form.querySelector('[name="year"]');
                if (yearField && /^\d{4}$/.test(yearField.value)) {
                    input.min = yearField.value + '-01-01';
                } else {
                    input.removeAttribute('min');
                }
            });

            // The Final Departmental View Regarding Integrity, for an answer that asks for it (2026-11-16).
            form.querySelectorAll('select[name="integrity_option_id"]').forEach(function (select) {
                var option = select.options[select.selectedIndex];
                var needsView = !!(option && option.hasAttribute('data-needs-view'));
                var shown = needsView && !select.closest('.d-none');

                form.querySelectorAll('[data-integrity-view]').forEach(function (box) {
                    box.hidden = !shown;
                    var view = box.querySelector('select[name="integrity_departmental_view"]');
                    if (view) { view.required = shown; }
                });
            });

            // A missing-record reason that makes the officer Unfit ("appointed in this
            // year") asks for no document: there is no record to produce (2026-11-14).
            form.querySelectorAll('[data-missing-record]').forEach(function (box) {
                var reason = box.querySelector('select[name="missing_reason_id"]');
                var option = reason ? reason.options[reason.selectedIndex] : null;
                var unfit = !!(option && option.hasAttribute('data-makes-unfit'));

                // The document is asked for every reason; a reason that names its document
                // (the appointment letter) shows that name (2026-11-16).
                var named = option ? option.getAttribute('data-document-label') : null;
                box.querySelectorAll('label[data-document-label-default]').forEach(function (label) {
                    label.textContent = named ? named + ' (PDF)' : label.getAttribute('data-document-label-default');
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
