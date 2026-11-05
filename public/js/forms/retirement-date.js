/**
 * Vacancy Management, Section C (2026-11-04). Where the post's mapping gives a
 * retirement age, a retirement's date is calculated from the officer's date of
 * birth — the last day of the month in which that age is reached, the month before
 * for a birthday on the 1st — and cannot be typed. The server calculates it again
 * and ignores the date posted. A promotion to a higher post keeps its own date.
 *
 * Markup: the date carries `data-retirement-age`; the date of birth is `#c_dob`;
 * the parts shown only for a retirement carry `data-retirement-only`.
 *
 * External file with no inline script, because the CSP forbids one (TD-29).
 */
(function () {
    'use strict';

    function pad(n) { return (n < 10 ? '0' : '') + n; }

    function retires(dob, age) {
        var parts = dob.split('-').map(Number);
        if (parts.length !== 3 || parts.some(isNaN)) { return ''; }
        // Day 0 of a month is the last day of the month before.
        var date = parts[2] === 1
            ? new Date(parts[0] + age, parts[1] - 1, 0)
            : new Date(parts[0] + age, parts[1], 0);
        return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
    }

    document.querySelectorAll('[data-retirement-age]').forEach(function (date) {
        var form = date.form;
        var reason = form && form.querySelector('[name="reason"]');
        var dob = form && form.querySelector('#c_dob');
        var age = parseInt(date.getAttribute('data-retirement-age'), 10);
        if (!reason || !dob || isNaN(age)) { return; }

        var sync = function () {
            var retirement = reason.value === 'retirement';
            form.querySelectorAll('[data-retirement-only]').forEach(function (el) { el.hidden = !retirement; });
            dob.required = retirement;
            date.readOnly = retirement;
            date.required = !retirement;
            if (retirement) {
                date.value = dob.value ? retires(dob.value, age) : '';
                date.dispatchEvent(new Event('input', {bubbles: true}));
            }
        };

        reason.addEventListener('change', sync);
        dob.addEventListener('change', sync);
        dob.addEventListener('input', sync);
        sync();
    });
})();
