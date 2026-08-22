/*
 * Evaluation / Tabulation Chart — the per-year grid (M4, screen S18).
 *
 * **Much smaller since the business directive of 2026-08-22.** This used to be a
 * repeater of officer cards, each holding a repeater of year rows, seeded with
 * every officer's identity from a `data-seed` attribute. The officer set is now
 * fixed by the approved Seniority List and the cards are rendered server-side, so
 * all that is left is the year grid inside a card — which is genuinely variable,
 * because how many CR years an officer has is a fact about the officer.
 *
 * What went away, and why it matters: there is no longer an "add officer" button
 * whose result the server would refuse, and no identity input on the page at all.
 * A screen that cannot express an invalid state is better than one that expresses
 * it and is then corrected.
 *
 * Extracted from an inline block on 2026-08-22 so `script-src 'self'` can be
 * enforced (TD-29); still no inline script here.
 */
(function () {
    'use strict';

    var container = document.getElementById('rows');
    var yearTplEl = document.getElementById('year-template');

    if (!container || !yearTplEl) { return; }

    var yearTpl = yearTplEl.innerHTML;

    /*
     * The three per-year tables share one visible Year input; the two hidden
     * mirrors keep loyalty and property aligned to the same year. Without this,
     * typing 2015 into the CR column would leave the loyalty and property rows
     * pointing at a blank year, and the two flags would be silently dropped.
     */
    function syncYear(yearRow) {
        var value = yearRow.querySelector('.year-input').value;
        yearRow.querySelectorAll('.year-mirror').forEach(function (mirror) {
            mirror.value = value;
        });
    }

    function addYear(card) {
        var body = card.querySelector('.year-rows');

        /*
         * Counted from the card's own running total rather than from the number
         * of rows on screen. Deleting the middle row of three and then adding one
         * would otherwise reuse an index still held by the last row, and PHP
         * would keep only one of the two.
         */
        var index = Number(card.dataset.yearCount || 0);
        card.dataset.yearCount = index + 1;

        var tbody = document.createElement('tbody');
        tbody.innerHTML = yearTpl
            .replaceAll('__R__', card.dataset.rowIndex)
            .replaceAll('__Y__', index);

        var yearRow = tbody.firstElementChild;
        body.appendChild(yearRow);
        syncYear(yearRow);

        return yearRow;
    }

    container.addEventListener('click', function (e) {
        if (e.target.classList.contains('add-year')) {
            addYear(e.target.closest('.eval-row-card'));
        }

        if (e.target.classList.contains('remove-year')) {
            e.target.closest('.year-row').remove();
        }
    });

    container.addEventListener('input', function (e) {
        if (e.target.classList.contains('year-input')) {
            syncYear(e.target.closest('.year-row'));
        }
    });
})();
