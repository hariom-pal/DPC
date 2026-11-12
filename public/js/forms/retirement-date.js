/**
 * Vacancy Management, Section C. A retirement's date is calculated, never entered
 * (business rule confirmed 2026-11-12): the last calendar day of the officer's
 * birth month in the retirement year — the birth year plus the retirement age of
 * the post's mapping in force on 1 January of the Vacancy Year. It is shown in a
 * read-only field that follows the date of birth as it is typed; the server
 * calculates it again and ignores anything posted. A promotion to a higher post
 * keeps its own date.
 *
 * Markup: the read-only date carries `data-retirement-date` (and
 * `data-retirement-age` when the mapping gives one); the date of birth is
 * `#c_dob`, the promotion's date `#c_date`; parts shown only for one reason carry
 * `data-retirement-only` or `data-promotion-only`.
 *
 * External file with no inline script, because the CSP forbids one (TD-29).
 */
(function () {
    'use strict';

    function pad(n) { return (n < 10 ? '0' : '') + n; }

    /** dd-mm-yyyy, or '' when the date of birth is not a whole date. */
    function retires(dob, age) {
        var parts = dob.split('-').map(Number);
        if (parts.length !== 3 || parts.some(isNaN) || parts[0] < 1000) { return ''; }
        // Day 0 of the month after the birth month is the birth month's last day,
        // so February comes out as the 28th or, in a leap year, the 29th.
        var date = new Date(parts[0] + age, parts[1], 0);
        return pad(date.getDate()) + '-' + pad(date.getMonth() + 1) + '-' + date.getFullYear();
    }

    document.querySelectorAll('[data-retirement-date]').forEach(function (out) {
        var form = out.form;
        var reason = form && form.querySelector('[name="reason"]');
        if (!reason) { return; }
        var dob = form.querySelector('#c_dob');
        var date = form.querySelector('#c_date');
        var age = parseInt(out.getAttribute('data-retirement-age') || '', 10);

        var sync = function () {
            var retirement = reason.value === 'retirement';
            form.querySelectorAll('[data-retirement-only]').forEach(function (el) { el.hidden = !retirement; });
            form.querySelectorAll('[data-promotion-only]').forEach(function (el) { el.hidden = retirement; });
            if (date) {
                date.required = !retirement;
                date.disabled = retirement;
            }
            if (dob) { dob.required = retirement && !isNaN(age); }
            out.value = retirement && !isNaN(age) && dob && dob.value ? retires(dob.value, age) : '';
        };

        reason.addEventListener('change', sync);
        if (dob) {
            dob.addEventListener('change', sync);
            dob.addEventListener('input', sync);
        }
        sync();
    });
})();
