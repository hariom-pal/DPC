/*
 * Final Seniority List form — repeating entry cards (M4, screen S17).
 *
 * Extracted from an inline `<script>` block on 2026-08-22 so that
 * `script-src 'self'` can be enforced rather than reported (TD-29). Logic
 * unchanged; the seed rows now arrive on `#entries[data-seed]` instead of being
 * interpolated into the script with `@json`.
 */
(function () {
    'use strict';

    var container = document.getElementById('entries');
    var templateEl = document.getElementById('entry-template');

    if (!container || !templateEl) { return; }

    var tpl = templateEl.innerHTML;
    var i = 0;

    function addRow() {
        var idx = i++;
        var wrap = document.createElement('div');
        wrap.innerHTML = tpl.replaceAll('__E__', idx);
        var el = wrap.firstElementChild;
        container.appendChild(el);
        return el;
    }

    container.addEventListener('click', function (e) {
        if (e.target.classList.contains('remove-row')) e.target.closest('.entry-card').remove();
    });
    // Absent when manual entry is off (2026-09-07); the rows are still drawn.
    var addBtn = document.getElementById('add-entry');

    if (addBtn) {
        addBtn.addEventListener('click', function () { addRow(); });
    }

    var seed = JSON.parse(container.dataset.seed || '[]');
    var readOnly = container.hasAttribute('data-read-only');

    if (seed.length === 0) {
        // Nothing to show, and nothing to add: an empty row the officer cannot fill
        // is worse than none.
        if (!readOnly) { addRow(); }
    } else {
        seed.forEach(function (entry) {
            var el = addRow();
            el.querySelector('[name$="[seniority_number]"]').value = entry.seniority_number || '';
            el.querySelector('[name$="[employee_number]"]').value = entry.employee_number || '';
            el.querySelector('[name$="[name]"]').value = entry.name || '';
            el.querySelector('[name$="[dob]"]').value = entry.dob || '';
            el.querySelector('[name$="[qualification]"]').value = entry.qualification || '';
            el.querySelector('[name$="[promotion_to_post_id]"]').value = entry.promotion_to_post_id || '';
            el.querySelector('[name$="[promotion_from_post_id]"]').value = entry.promotion_from_post_id || '';
            /* Both selectors are anchored on the full name, not on a suffix:
               `[name$="[reservation_category_id]"]` also matches
               `[seat_reservation_category_id]`, so a suffix match would depend on
               which of the two happens to come first in the template. */
            if (el.querySelector('[name*="][reservation_category_id"]')) { el.querySelector('[name*="][reservation_category_id"]').value = entry.reservation_category_id || ''; }
            if (el.querySelector('[name$="[seat_reservation_category_id]"]')) { el.querySelector('[name$="[seat_reservation_category_id]"]').value = entry.seat_reservation_category_id || ''; }
            el.querySelector('[name$="[mobile]"]').value = entry.mobile || '';
            el.querySelector('[name$="[date_of_joining_service]"]').value = entry.date_of_joining_service || '';
            el.querySelector('[name$="[date_of_posting_on_promotion_to_post]"]').value = entry.date_of_posting_on_promotion_to_post || '';
            el.querySelector('[name$="[date_of_posting_on_promotion_from_post]"]').value = entry.date_of_posting_on_promotion_from_post || '';
        });
    }

    /*
     * Show the rows, but do not let them be edited.
     *
     * Manual entry is off by default since 2026-09-07 — the list comes from the
     * upload — but the officer must still *see* what was imported, so the grid is
     * rendered and then frozen rather than not rendered at all.
     *
     * `disabled` rather than `readonly`: it covers selects as well as text boxes,
     * and a disabled field is not posted — which matters because the route is gated
     * on the same flag and would refuse the payload anyway.
     */
    if (readOnly) {
        container.querySelectorAll('input, select, textarea, button').forEach(function (field) {
            field.disabled = true;
        });
    }
})();
