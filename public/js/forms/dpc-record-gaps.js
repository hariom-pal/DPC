/**
 * Missing-record form — change register of 2026-08-27, item 16.
 *
 * One job: make Remarks visibly mandatory the moment a reason that demands them is
 * chosen. The rule itself is enforced in `RecordGapRequest`, which is where it has to
 * live — this only tells the officer before they submit, instead of after.
 *
 * Reads `data-requires-remarks` off the chosen option rather than comparing against
 * the code `other`, for the same reason the master carries the flag as a column: an
 * Administrator may mark *Court Case* as needing the case number, and a hardcoded
 * `other` here would silently disagree with the server.
 *
 * External file with no inline script, because the CSP forbids one (TD-29).
 */
(function () {
    'use strict';

    var form = document.querySelector('[data-record-gap-form]');

    if (!form) {
        return;
    }

    var reasonSelect = form.querySelector('#record_reason_id');
    var remarks = form.querySelector('#remarks');
    var marker = form.querySelector('[data-remarks-required-marker]');

    if (!reasonSelect || !remarks) {
        return;
    }

    function sync() {
        var option = reasonSelect.options[reasonSelect.selectedIndex];
        var required = !!option && option.getAttribute('data-requires-remarks') === '1';

        remarks.required = required;

        if (marker) {
            marker.classList.toggle('d-none', !required);
        }
    }

    reasonSelect.addEventListener('change', sync);
    sync();
}());
