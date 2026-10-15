/*
 * Department Post Mapping — the Government Orders repeater (change register of
 * 2026-08-26, item 10's "Add More").
 *
 * **A file rather than an inline block**, for the reason TD-29 records: an inline
 * `<script>` is what stopped the Content-Security-Policy being enforced rather than
 * merely reported. There is nothing variable here at all — the row markup comes from
 * a `<template>` the server rendered — so the policy has nothing to refuse.
 *
 * **The index only ever increases.** Removing the third of four rows leaves 0, 1, 3
 * and the next Add produces 4, not 3. Re-indexing would move a validation error
 * reported against row 3 onto a different row, and — worse here than on other
 * repeaters — would pair an order's number with another row's uploaded file, because
 * the file input's name carries the index.
 */
(function () {
    'use strict';

    var container = document.getElementById('government-orders');
    var template = document.getElementById('government-order-template');
    var addButton = document.getElementById('add-government-order');

    // Rendered on two screens only, so leave every other page alone.
    if (!container || !template || !addButton) { return; }

    /* Continue from the highest index already on the page rather than from the
       number of rows: a failed submission may have posted back 0, 1 and 3. */
    var next = (function () {
        var highest = -1;

        container.querySelectorAll('[name^="orders["]').forEach(function (input) {
            var match = input.name.match(/^orders\[(\d+)\]/);

            if (match) { highest = Math.max(highest, parseInt(match[1], 10)); }
        });

        return highest + 1;
    })();

    addButton.addEventListener('click', function () {
        var wrap = document.createElement('div');
        wrap.innerHTML = template.innerHTML.replaceAll('__I__', next++);

        var row = wrap.firstElementChild;

        if (row) { container.appendChild(row); }
    });

    container.addEventListener('click', function (e) {
        if (!e.target.classList.contains('remove-government-order')) { return; }

        var rows = container.querySelectorAll('.government-order-row');

        /* The last row is emptied rather than removed. A repeater with no rows has
           no way back — there would be nothing to type into and the Add button
           would be the only route, which reads as a broken form. */
        if (rows.length === 1) {
            rows[0].querySelectorAll('input').forEach(function (input) { input.value = ''; });

            return;
        }

        e.target.closest('.government-order-row').remove();
    });
})();
