/**
 * Saved entries are shown read-only, with an Edit button against them (MoM of
 * 12 November 2026, point 10): a form marked `data-saved-lock` opens with its fields
 * disabled and its save button hidden; Edit enables them, and Cancel puts them back
 * as saved. Nothing changes until Edit is pressed.
 *
 * Without script the form stays editable, as before. External file with no inline
 * script, because the CSP forbids one (TD-29).
 */
(function () {
    'use strict';

    function init(root) {
    root.querySelectorAll('form[data-saved-lock]').forEach(function (form) {
        // The labels come from the page, in its language.
        var text = {
            edit: form.getAttribute('data-label-edit') || 'Edit',
            cancel: form.getAttribute('data-label-cancel') || 'Cancel',
            saved: form.getAttribute('data-label-saved') || 'Saved'
        };
        var fields = Array.prototype.filter.call(form.elements, function (el) {
            return el.type !== 'hidden' && el.tagName !== 'BUTTON' && !el.disabled;
        });
        var buttons = form.querySelectorAll('button[type="submit"], button:not([type])');

        var bar = document.createElement('div');
        bar.className = 'd-flex align-items-center gap-2 mb-2 saved-lock-bar';
        var badge = document.createElement('span');
        badge.className = 'badge text-bg-success';
        badge.textContent = text.saved;
        var edit = document.createElement('button');
        edit.type = 'button';
        edit.className = 'btn btn-outline-primary btn-sm';
        edit.textContent = text.edit;
        edit.setAttribute('data-saved-lock-edit', '');
        bar.appendChild(badge);
        bar.appendChild(edit);
        form.insertBefore(bar, form.firstElementChild ? form.firstElementChild.nextSibling : null);

        var lock = function (locked) {
            fields.forEach(function (el) { el.disabled = locked; });
            buttons.forEach(function (b) { b.hidden = locked; });
            badge.hidden = !locked;
            edit.textContent = locked ? text.edit : text.cancel;
        };

        edit.addEventListener('click', function () {
            if (edit.textContent === text.cancel) {
                form.reset();
                lock(true);
            } else {
                lock(false);
            }
        });

        lock(true);
    });
    }

    init(document);
    // Drawn again after a section is saved without a page load (2026-11-14).
    document.addEventListener('edpc:content', function (event) { init(event.detail.root); });
})();
