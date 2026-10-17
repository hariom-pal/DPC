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

    document.querySelectorAll('form').forEach(function (form) {
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

            // A question is reachable only if the question it depends on is.
            var radio = form.querySelector('input[name="' + rule[0] + '"]');
            var parent = radio ? radio.closest('[data-show-when]') : null;

            return parent === null || visible(parent);
        }

        function sync() {
            dependants.forEach(function (element) {
                element.classList.toggle('d-none', !visible(element));
            });
        }

        form.addEventListener('change', sync);
        sync();
    });
})();
