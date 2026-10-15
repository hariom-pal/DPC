/*
 * Working Officers chart — one repeater (M4, screen S16).
 *
 * The vacancy breakdown used to be a second repeater beside this one. Since the
 * business directive of 2026-09-02 it is derived from this chart and rendered
 * read-only, so only the officer repeater remains — and each row now says
 * whether it is an officer or a vacant post.
 *
 * Extracted from an inline `<script>` block on 2026-08-22 so that
 * `script-src 'self'` can be enforced rather than reported (TD-29). The generic
 * `repeater()` below is exactly as it was; only the source of the seed rows
 * changed — each container now carries its own `data-seed`, read as data by the
 * browser instead of interpolated into script with `@json`.
 */
(function () {
    'use strict';

    function repeater(containerId, addBtnId, tplId, prefix, fillRow) {
        var container = document.getElementById(containerId);
        var templateEl = document.getElementById(tplId);
        var addBtn = document.getElementById(addBtnId);

        // The Add button is absent when manual entry is off, and the rows still
        // have to be drawn — so its absence is not a reason to stop.
        if (!container || !templateEl) { return; }

        var tpl = templateEl.innerHTML;
        var seed = JSON.parse(container.dataset.seed || '[]');
        var i = 0;

        function addRow() {
            var idx = i++;
            var wrap = document.createElement('div');
            wrap.innerHTML = tpl.replaceAll('__' + prefix + '__', idx);
            var el = wrap.firstElementChild;
            container.appendChild(el);
            return el;
        }

        container.addEventListener('click', function (e) {
            if (e.target.classList.contains('remove-row')) e.target.closest('.row, .officer-card').remove();
        });
        if (addBtn) {
            addBtn.addEventListener('click', function () { addRow(); });
        }

        var readOnly = container.hasAttribute('data-read-only');

        if (seed.length === 0) {
            // Nothing to show, and nothing to add: say so rather than drawing an
            // empty row the officer cannot fill.
            if (!readOnly) { addRow(); }
        } else {
            seed.forEach(function (item) {
                var el = addRow();
                fillRow(el, item);
            });
        }

        if (readOnly) { freeze(container); }
    }

    /*
     * Show the rows, but do not let them be edited.
     *
     * Manual entry is off by default since 2026-09-07 — the list comes from the
     * upload — but the officer must still *see* what was imported, so the grid is
     * rendered and then frozen rather than not rendered at all.
     *
     * `disabled` rather than `readonly`: it covers selects and checkboxes as well as
     * text boxes, and a disabled field is not posted — which matters because the
     * route is gated on the same flag and would refuse the payload anyway.
     */
    function freeze(container) {
        container.querySelectorAll('input, select, textarea, button').forEach(function (field) {
            field.disabled = true;
        });
    }


    repeater('officers', 'add-officer', 'officer-template', 'O', function (el, o) {
        el.querySelector('[name$="[sn]"]').value = o.sn || '';
        el.querySelector('[name$="[status]"]').value = o.status || 'working';
        el.querySelector('[name$="[source]"]').value = o.source || 'promotion';
        el.querySelector('[name$="[employee_number]"]').value = o.employee_number || '';
        el.querySelector('[name$="[name]"]').value = o.name || '';
        el.querySelector('[name$="[dob]"]').value = o.dob || '';
        el.querySelector('[name$="[qualification]"]').value = o.qualification || '';
        el.querySelector('[name$="[promotion_to_post_id]"]').value = o.promotion_to_post_id || '';
        el.querySelector('[name$="[promotion_from_post_id]"]').value = o.promotion_from_post_id || '';
        /* Both selectors are anchored on the full name, not on a suffix:
           `[name$="[reservation_category_id]"]` also matches
           `[seat_reservation_category_id]`, so a suffix match would depend on
           which of the two happens to come first in the template. */
        el.querySelector('[name*="][reservation_category_id"]').value = o.reservation_category_id || '';
        el.querySelector('[name$="[seat_reservation_category_id]"]').value = o.seat_reservation_category_id || '';
        el.querySelector('[name$="[mobile]"]').value = o.mobile || '';
        el.querySelector('[name$="[date_of_joining_service]"]').value = o.date_of_joining_service || '';
        el.querySelector('[name$="[date_of_posting_on_post]"]').value = o.date_of_posting_on_post || '';
        el.querySelector('[name$="[date_of_posting_on_promotion_from_post]"]').value = o.date_of_posting_on_promotion_from_post || '';
        el.querySelector('[name$="[roster_position]"]').value = o.roster_position || '';
        /* Change register of 2026-08-27, items 3 and 4: a retirement date and a
           deputation *period*, because one date cannot express an overlap. */
        el.querySelector('[name$="[date_of_retirement]"]').value = o.date_of_retirement || '';
        el.querySelector('[name$="[deputation_from]"]').value = o.deputation_from || '';
        el.querySelector('[name$="[deputation_to]"]').value = o.deputation_to || '';
        applyStatus(el);
    });

    /*
     * A vacant post has no person, so the fields that describe one are cleared and
     * disabled rather than merely ignored. Disabled inputs are not posted, which is
     * what keeps `prohibited_if` on the server from refusing a row the officer
     * filled in before switching it to vacant.
     */
    var PERSONAL = [
        '[source]',
        '[employee_number]', '[name]', '[dob]', '[qualification]',
        '[promotion_to_post]', '[promotion_from_post]',
        '[date_of_joining_service]', '[date_of_posting_on_promotion_to_post]',
        '[date_of_posting_on_promotion_from_post]',
        '[date_of_retirement]', '[deputation_from]', '[deputation_to]'
    ];

    function applyStatus(el) {
        var select = el.querySelector('.row-status');
        if (!select) { return; }

        var vacant = select.value === 'vacant';

        PERSONAL.forEach(function (suffix) {
            var field = el.querySelector('[name$="' + suffix + '"]');
            if (!field) { return; }
            if (vacant) { field.value = ''; }
            field.disabled = vacant;
            field.closest('[class*="col-"]').classList.toggle('opacity-50', vacant);
        });
    }

    var officers = document.getElementById('officers');
    if (officers) {
        officers.addEventListener('change', function (e) {
            if (e.target.classList.contains('row-status')) {
                applyStatus(e.target.closest('.officer-card'));
            }
        });
    }
})();
