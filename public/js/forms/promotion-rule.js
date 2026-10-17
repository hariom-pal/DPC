/*
 * Promotion rule form: show only the years the counts ask for, and label each one
 * with its calendar year. Without this script every row stays visible and the
 * server ignores the rows beyond the counts, so nothing depends on it.
 */
(function () {
    'use strict';

    var form = document.getElementById('promotion-rule-form');
    if (!form) {
        return;
    }

    var field = function (name) { return form.querySelector('[name="' + name + '"]'); };
    var number = function (name) { var el = field(name); return el ? parseInt(el.value, 10) || 0 : 0; };

    function refresh() {
        var cr = number('cr_years');
        var loyalty = number('loyalty_years');
        var property = number('property_years');
        var span = Math.max(cr, loyalty, property);
        var year = number('effective_from_year');

        form.querySelectorAll('.js-year-row').forEach(function (row) {
            var before = parseInt(row.getAttribute('data-before'), 10);
            row.hidden = before > span;

            row.querySelectorAll('.js-cr-field').forEach(function (el) { el.disabled = before > cr; });
            row.querySelectorAll('.js-loyalty-field').forEach(function (el) { el.disabled = before > loyalty; });
            row.querySelectorAll('.js-property-field').forEach(function (el) { el.disabled = before > property; });

            var label = row.querySelector('.js-year-label');
            if (label && year > 0) {
                label.textContent = label.textContent.replace(/\d{4}(?=\D*$)/, String(year - before));
            }
        });
    }

    ['cr_years', 'loyalty_years', 'property_years', 'effective_from_year'].forEach(function (name) {
        var el = field(name);
        if (el) {
            el.addEventListener('input', refresh);
        }
    });

    refresh();
}());
