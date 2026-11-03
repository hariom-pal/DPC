/**
 * Employee ID first (UAT, 2026-11-02 / 2026-11-03). When the ID typed is an officer
 * of the department's employee database, the rest of the row is filled in: the name,
 * and wherever the form has them, the mobile, date of birth, qualification and dates.
 *
 * Markup: the ID box carries `data-employee-lookup="<url>"`; the fields to fill carry
 * `data-employee-field="<key of the JSON answer>"`, inside one `[data-employee-row]`.
 * A `[data-employee-found]` note in the row is shown on a match.
 *
 * Nothing the officer typed is overwritten: a field is filled only while it is empty
 * or still holds what this script put there. A changed ID first empties what an
 * earlier match filled, so one officer's details never stay beside another's.
 * Delegated from the document, so rows added later (repeaters) work too.
 *
 * External file with no inline script, because the CSP forbids one (TD-29).
 */
(function () {
    'use strict';

    var tokens = new WeakMap();

    function set(field, value) {
        field.value = value;
        // A date field is shown by date-input.js, which listens for this.
        field.dispatchEvent(new Event('input', {bubbles: true}));
        field.dispatchEvent(new Event('change', {bubbles: true}));
    }

    /** Whether the field still holds exactly what this script put there. */
    function ours(field) {
        return field.dataset.employeeFilled !== undefined && field.dataset.employeeFilled === field.value;
    }

    function fields(row) {
        return Array.prototype.filter.call(row.querySelectorAll('[data-employee-field]'), function (field) {
            return !field.disabled && !field.readOnly;
        });
    }

    document.addEventListener('change', function (event) {
        var input = event.target;

        if (!(input instanceof HTMLInputElement) || !input.hasAttribute('data-employee-lookup')) { return; }

        var row = input.closest('[data-employee-row]');
        var note = row ? row.querySelector('[data-employee-found]') : null;
        var number = input.value.trim();
        var mine = (tokens.get(input) || 0) + 1;

        tokens.set(input, mine);
        if (!row) { return; }
        if (note) { note.classList.add('d-none'); }

        fields(row).forEach(function (field) {
            if (ours(field)) { set(field, ''); }
            delete field.dataset.employeeFilled;
        });

        if (number === '') { return; }

        fetch(input.getAttribute('data-employee-lookup') + '?employee_number=' + encodeURIComponent(number), {
            headers: {Accept: 'application/json'},
            credentials: 'same-origin',
        })
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (data) {
                if (tokens.get(input) !== mine || !data || !data.found) { return; }

                fields(row).forEach(function (field) {
                    var value = data[field.getAttribute('data-employee-field')];

                    if (value === null || value === undefined || value === '' || field.value.trim() !== '') { return; }
                    set(field, String(value));
                    field.dataset.employeeFilled = field.value;
                });

                if (note) { note.classList.remove('d-none'); }
            })
            .catch(function () { /* the officer types the details */ });
    });
})();
