/*
 * Working Officers & Vacancies form — two repeaters on one screen
 * (M4, screen S16).
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

        if (!container || !templateEl || !addBtn) { return; }

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
        addBtn.addEventListener('click', function () { addRow(); });

        if (seed.length === 0) {
            addRow();
        } else {
            seed.forEach(function (item) {
                var el = addRow();
                fillRow(el, item);
            });
        }
    }

    repeater('vacancies', 'add-vacancy', 'vacancy-template', 'V', function (el, v) {
        el.querySelector('[name$="[vacancy_type_id]"]').value = v.vacancy_type_id || '';
        el.querySelector('[name$="[reservation_category_id]"]').value = v.reservation_category_id || '';
        el.querySelector('[name$="[count]"]').value = v.count == null ? 0 : v.count;
    });

    repeater('officers', 'add-officer', 'officer-template', 'O', function (el, o) {
        el.querySelector('[name$="[sn]"]').value = o.sn || '';
        el.querySelector('[name$="[employee_number]"]').value = o.employee_number || '';
        el.querySelector('[name$="[name]"]').value = o.name || '';
        el.querySelector('[name$="[dob]"]').value = o.dob || '';
        el.querySelector('[name$="[qualification]"]').value = o.qualification || '';
        el.querySelector('[name$="[current_post]"]').value = o.current_post || '';
        el.querySelector('[name$="[feeder_post]"]').value = o.feeder_post || '';
        el.querySelector('[name$="[reservation_category_id]"]').value = o.reservation_category_id || '';
        el.querySelector('[name$="[date_of_joining_service]"]').value = o.date_of_joining_service || '';
        el.querySelector('[name$="[date_of_posting_on_post]"]').value = o.date_of_posting_on_post || '';
        el.querySelector('[name$="[date_of_posting_on_feeder_post]"]').value = o.date_of_posting_on_feeder_post || '';
        el.querySelector('[name$="[roster_position]"]').value = o.roster_position || '';
        el.querySelector('[name$="[deputation_date]"]').value = o.deputation_date || '';
    });
})();
