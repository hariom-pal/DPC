/*
 * Recruitment Rule form — the feeder-cadre grid (M2, Master Data).
 *
 * Extracted from an inline `<script>` block on 2026-08-22 so that
 * `script-src 'self'` can be enforced rather than reported (TD-29). Logic
 * unchanged; the one interpolated value — the next free row index — now arrives
 * on the table body as `data-next-index`.
 */
/*
 * Feeder grid behaviour, all of it advisory: every rule enforced here is
 * enforced again server-side in RecruitmentRuleRequest. This exists so the
 * user is told before they press Save, not so the server can trust the page.
 *
 *   - Add / remove rows.
 *   - Keep the running Promotion Share total visible, with a ✓ at 100%.
 *   - Offer only cadres in the target's department, minus the target itself
 *     and minus cadres already chosen on another row — which is what makes
 *     a duplicate hard to create rather than merely rejected.
 */
(function () {
    'use strict';

    var form = document.getElementById('rule-form');
    var tbody = document.querySelector('#feeders-table tbody');
    var templateEl = document.getElementById('feeder-row-template');

    if (!form || !tbody || !templateEl) { return; }

    var template = templateEl.innerHTML;
    var target = document.getElementById('cadre_id');
    var promotionPct = document.getElementById('promotion_recruitment_pct');
    var totalEl = document.getElementById('share-total');
    var markEl = document.getElementById('share-total-mark');
    var hintEl = document.getElementById('share-total-hint');
    var emptyRow = document.getElementById('feeders-empty');
    var directPct = document.getElementById('direct_recruitment_pct');
    var splitEl = document.getElementById('split-total');
    var splitMarkEl = document.getElementById('split-total-mark');
    var splitHintEl = document.getElementById('split-total-hint');
    // The next free row index, so a re-rendered form after a validation
    // failure does not collide with the rows already on the page. It used to be
    // interpolated into this script; it now arrives as data (TD-29).
    var index = Number(tbody.dataset.nextIndex || 0);

    /* DR + PR, shown the same way as the feeder share total so the two
       "must equal 100%" rules on this screen read identically. */
    function refreshSplit() {
        var dr = parseFloat(directPct.value);
        var pr = parseFloat(promotionPct.value);
        var total = (isNaN(dr) ? 0 : dr) + (isNaN(pr) ? 0 : pr);
        var rounded = Math.round(total * 100) / 100;
        var balanced = Math.abs(rounded - 100) < 0.01;

        splitEl.textContent = rounded + '%';
        splitEl.className = 'share-total ' + (balanced ? 'is-balanced' : 'is-unbalanced');
        splitMarkEl.textContent = balanced ? '✓' : '✗';
        splitHintEl.textContent = balanced
            ? 'Every post is accounted for.'
            : (rounded < 100
                ? (Math.round((100 - rounded) * 100) / 100) + '% of the posts is unaccounted for.'
                : 'Over-allocated by ' + (Math.round((rounded - 100) * 100) / 100) + '%.');
    }

    function rows() {
        return Array.prototype.slice.call(tbody.querySelectorAll('tr.feeder-row'));
    }

    function refreshTotal() {
        var all = rows();
        var total = 0;
        all.forEach(function (row) {
            var v = parseFloat(row.querySelector('.share-input').value);
            if (!isNaN(v)) { total += v; }
        });

        var rounded = Math.round(total * 100) / 100;
        var balanced = Math.abs(rounded - 100) < 0.01;
        var promotes = parseFloat(promotionPct.value) > 0;

        totalEl.textContent = rounded + '%';
        emptyRow.classList.toggle('d-none', all.length > 0);

        if (all.length === 0) {
            totalEl.className = 'share-total';
            markEl.textContent = '';
            hintEl.textContent = promotes
                ? 'A promotion quota above 0% needs at least one feeder cadre.'
                : 'No feeder cadre needed — this post is filled entirely by direct recruitment.';
            return;
        }

        totalEl.className = 'share-total ' + (balanced ? 'is-balanced' : 'is-unbalanced');
        markEl.textContent = balanced ? '✓' : '✗';
        hintEl.textContent = balanced
            ? 'The promotion quota is fully allocated.'
            : (rounded < 100
                ? 'Must total exactly 100% — ' + (Math.round((100 - rounded) * 100) / 100) + '% still unallocated.'
                : 'Must total exactly 100% — over-allocated by ' + (Math.round((rounded - 100) * 100) / 100) + '%.');
    }

    /* Hide cadres that cannot legally be chosen on this row. */
    function refreshCadreOptions() {
        var department = target.selectedOptions[0] ? target.selectedOptions[0].dataset.department : null;
        var taken = rows().map(function (r) { return r.querySelector('.cadre-select').value; })
                          .filter(function (v) { return v !== ''; });

        rows().forEach(function (row) {
            var select = row.querySelector('.cadre-select');
            var mine = select.value;

            Array.prototype.slice.call(select.options).forEach(function (option) {
                if (option.value === '') { return; }
                var sameDepartment = department !== null && option.dataset.department === department;
                var isTarget = option.value === target.value;
                var takenElsewhere = taken.indexOf(option.value) !== -1 && option.value !== mine;
                option.hidden = !sameDepartment || isTarget || takenElsewhere;
                option.disabled = option.hidden;
            });

            // A cadre that has become ineligible (the target changed under it)
            // is cleared rather than left showing a value that cannot be saved.
            if (mine !== '' && select.options[select.selectedIndex] && select.options[select.selectedIndex].hidden) {
                select.value = '';
            }

            select.dispatchEvent(new CustomEvent('ss:refresh'));
        });
    }

    function refresh() {
        refreshCadreOptions();
        refreshTotal();
        refreshSplit();
    }

    document.getElementById('add-feeder').addEventListener('click', function () {
        tbody.insertAdjacentHTML('beforeend', template.replaceAll('__INDEX__', index++));
        refresh();
        var added = rows()[rows().length - 1];
        var input = added.querySelector('.ss-input') || added.querySelector('.cadre-select');
        if (input) { input.focus(); }
    });

    tbody.addEventListener('click', function (e) {
        if (e.target.closest('.remove-feeder')) {
            e.target.closest('tr.feeder-row').remove();
            refresh();
        }
    });

    tbody.addEventListener('input', function (e) {
        if (e.target.classList.contains('share-input')) { refreshTotal(); }
    });
    tbody.addEventListener('change', function (e) {
        if (e.target.classList.contains('cadre-select')) { refresh(); }
    });

    target.addEventListener('change', refresh);
    promotionPct.addEventListener('input', function () { refreshTotal(); refreshSplit(); });
    directPct.addEventListener('input', refreshSplit);

    /* Last line of defence before a pointless round trip. The server checks
       the same thing; this just saves the user the wait. */
    form.addEventListener('submit', function (e) {
        /* DR + PR first: it sits above the feeder grid, so reporting it
           first means the user is never scrolled past an error to reach
           a later one. */
        var dr = parseFloat(directPct.value);
        var pr = parseFloat(promotionPct.value);
        var split = (isNaN(dr) ? 0 : dr) + (isNaN(pr) ? 0 : pr);

        if (Math.abs(Math.round(split * 100) / 100 - 100) >= 0.01) {
            e.preventDefault();
            splitEl.scrollIntoView({ block: 'center', behavior: 'smooth' });
            splitHintEl.classList.add('fw-semibold');

            return;
        }

        var total = 0;
        rows().forEach(function (row) {
            var v = parseFloat(row.querySelector('.share-input').value);
            if (!isNaN(v)) { total += v; }
        });
        var promotes = parseFloat(promotionPct.value) > 0;

        if (promotes && rows().length > 0 && Math.abs(Math.round(total * 100) / 100 - 100) >= 0.01) {
            e.preventDefault();
            totalEl.scrollIntoView({ block: 'center', behavior: 'smooth' });
            hintEl.classList.add('fw-semibold');
        }
    });

    refresh();
})();
