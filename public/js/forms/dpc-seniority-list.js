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
    document.getElementById('add-entry').addEventListener('click', function () { addRow(); });

    var seed = JSON.parse(container.dataset.seed || '[]');
    if (seed.length === 0) {
        addRow();
    } else {
        seed.forEach(function (entry) {
            var el = addRow();
            el.querySelector('[name$="[seniority_number]"]').value = entry.seniority_number || '';
            el.querySelector('[name$="[employee_number]"]').value = entry.employee_number || '';
            el.querySelector('[name$="[name]"]').value = entry.name || '';
            el.querySelector('[name$="[dob]"]').value = entry.dob || '';
            el.querySelector('[name$="[qualification]"]').value = entry.qualification || '';
            el.querySelector('[name$="[current_post]"]').value = entry.current_post || '';
            el.querySelector('[name$="[feeder_post]"]').value = entry.feeder_post || '';
            el.querySelector('[name$="[reservation_category_id]"]').value = entry.reservation_category_id || '';
            el.querySelector('[name$="[date_of_joining_service]"]').value = entry.date_of_joining_service || '';
            el.querySelector('[name$="[date_of_posting_on_current_post]"]').value = entry.date_of_posting_on_current_post || '';
            el.querySelector('[name$="[date_of_posting_on_feeder_post]"]').value = entry.date_of_posting_on_feeder_post || '';
        });
    }
})();
