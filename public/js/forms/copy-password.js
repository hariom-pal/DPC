/**
 * Copy a temporary password to the clipboard (business decision, 2026-10-26).
 *
 * The password is read from the element named by `data-copy-target` and written
 * only to the clipboard: never to localStorage, sessionStorage, a URL or the
 * console. No inline handler — the enforced CSP forbids them.
 */
(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-copy-target]');
        if (!button) { return; }

        var source = document.getElementById(button.dataset.copyTarget);
        if (!source) { return; }

        var text = source.textContent.trim();
        var done = function () {
            var label = button.textContent;
            button.textContent = button.dataset.copiedLabel || label;
            window.setTimeout(function () { button.textContent = label; }, 2000);
        };

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done);
            return;
        }

        var range = document.createRange();
        range.selectNodeContents(source);
        var selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
        try { document.execCommand('copy'); done(); } catch (e) { /* the text stays selected for a manual copy */ }
    });
})();
