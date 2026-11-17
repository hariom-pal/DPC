/**
 * Vacancy Management, Section C. A retirement's date is calculated, never entered
 * (MoM of 12 November 2026): the last calendar day of the officer's birth month in
 * the retirement year — for a birthday on the 1st, of the month before — the birth
 * year plus the retirement age of
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
        // The shared date picker shows DD-MM-YYYY; the field it posts holds
        // YYYY-MM-DD. Either is read (2026-11-17: the picker took the `c_dob` id,
        // so this read 01-10-1985 as the year 1 and showed nothing).
        var dmy = /^(\d{1,2})-(\d{1,2})-(\d{4})$/.exec(dob);
        if (dmy) { dob = dmy[3] + '-' + dmy[2] + '-' + dmy[1]; }
        var parts = dob.split('-').map(Number);
        if (parts.length !== 3 || parts.some(isNaN) || parts[0] < 1000) { return ''; }
        // Day 0 of a month is the last day of the month before: the birth month's
        // last day, or for a birthday on the 1st the month before's (MoM of
        // 12 November 2026, rule 3). February comes out as the 28th or the 29th.
        var date = new Date(parts[0] + age, parts[2] === 1 ? parts[1] - 1 : parts[1], 0);
        return pad(date.getDate()) + '-' + pad(date.getMonth() + 1) + '-' + date.getFullYear();
    }

    document.querySelectorAll('[data-retirement-date]').forEach(function (out) {
        var form = out.form;
        var reason = form && form.querySelector('[name="reason"]');
        if (!reason) { return; }
        // The visible box (which carries `required`) and the field that is posted:
        // the date picker splits one into two and gives the box the id.
        var dob = form.querySelector('#c_dob');
        var dobValue = form.querySelector('[name="date_of_birth"]') || dob;
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
            out.value = retirement && !isNaN(age) && dobValue && dobValue.value ? retires(dobValue.value, age) : '';
        };

        reason.addEventListener('change', sync);
        [dob, dobValue].forEach(function (el) {
            if (el) {
                el.addEventListener('change', sync);
                el.addEventListener('input', sync);
            }
        });
        sync();
    });
})();
