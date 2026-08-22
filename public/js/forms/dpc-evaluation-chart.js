/*
 * Evaluation / Tabulation Chart form — a repeater of officer cards, each
 * holding its own repeater of year rows (M4, screen S18).
 *
 * Extracted from an inline `<script>` block on 2026-08-22 so that
 * `script-src 'self'` can be enforced rather than reported (TD-29). Logic
 * unchanged. The rows are still pre-normalised in the view — flags as '', '1'
 * or '0' strings, years merged across the three per-year tables — so there is
 * still exactly one case to handle here; they now arrive on `#rows[data-seed]`
 * rather than interpolated with `@json`.
 */
(function () {
    'use strict';

    var container = document.getElementById('rows');
    var rowTplEl = document.getElementById('row-template');
    var yearTplEl = document.getElementById('year-template');

    if (!container || !rowTplEl || !yearTplEl) { return; }

    var rowTpl = rowTplEl.innerHTML;
    var yearTpl = yearTplEl.innerHTML;
    var rowIndex = 0;

    // The three per-year tables share one visible Year input; the two
    // hidden mirrors keep loyalty/property aligned to the same year.
    function syncYear(yearRow) {
        var value = yearRow.querySelector('.year-input').value;
        yearRow.querySelectorAll('.year-mirror').forEach(function (mirror) {
            mirror.value = value;
        });
    }

    function addYear(card, seed) {
        var body = card.querySelector('.year-rows');
        var idx = Number(card.dataset.yearCount || 0);
        card.dataset.yearCount = idx + 1;

        var html = yearTpl
            .replaceAll('__R__', card.dataset.rowIndex)
            .replaceAll('__Y__', idx);
        var tbody = document.createElement('tbody');
        tbody.innerHTML = html;
        var yearRow = tbody.firstElementChild;
        body.appendChild(yearRow);

        if (seed) {
            // Values arrive pre-normalised from the Blade block in the view.
            yearRow.querySelector('.year-input').value = seed.year == null ? '' : seed.year;
            yearRow.querySelector('[name$="[grade]"]').value = seed.grade == null ? '' : seed.grade;
            yearRow.querySelector('[name$="[loyalty_flags][' + idx + '][flag]"]').value = seed.loyalty == null ? '' : seed.loyalty;
            yearRow.querySelector('[name$="[property_flags][' + idx + '][flag]"]').value = seed.property == null ? '' : seed.property;
        }
        syncYear(yearRow);

        return yearRow;
    }

    function addRow(seed) {
        var idx = rowIndex++;
        var wrap = document.createElement('div');
        wrap.innerHTML = rowTpl.replaceAll('__R__', idx);
        var card = wrap.firstElementChild;
        card.dataset.rowIndex = idx;
        card.dataset.yearCount = 0;
        container.appendChild(card);

        if (seed) {
            ['sn', 'employee_number', 'name', 'dob', 'qualification', 'current_post', 'feeder_post',
                'reservation_category_id', 'date_of_joining_service', 'date_of_posting_on_current_post',
                'date_of_posting_on_feeder_post', 'tribal_area_service_years', 'note'].forEach(function (field) {
                var input = card.querySelector('[name$="[' + field + ']"]');
                if (input) input.value = seed[field] == null ? '' : seed[field];
            });
            (seed.years || []).forEach(function (year) { addYear(card, year); });
        }

        if (Number(card.dataset.yearCount) === 0) addYear(card, null);

        return card;
    }

    container.addEventListener('click', function (e) {
        if (e.target.classList.contains('remove-eval-row')) e.target.closest('.eval-row-card').remove();
        if (e.target.classList.contains('remove-year')) e.target.closest('.year-row').remove();
        if (e.target.classList.contains('add-year')) addYear(e.target.closest('.eval-row-card'), null);
    });
    container.addEventListener('input', function (e) {
        if (e.target.classList.contains('year-input')) syncYear(e.target.closest('.year-row'));
    });
    document.getElementById('add-row').addEventListener('click', function () { addRow(null); });

    var seed = JSON.parse(container.dataset.seed || '[]');
    if (seed.length === 0) {
        addRow(null);
    } else {
        seed.forEach(function (row) { addRow(row); });
    }
})();
