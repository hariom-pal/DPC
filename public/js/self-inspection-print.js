/*
 * Self-Inspection printable record (S51's PRINT) — M11.
 *
 * An external file rather than an inline <script>, so this screen satisfies
 * `script-src 'self'` even when the Content-Security-Policy is enforced. The rest
 * of the application does not yet (TD-29); new code is not going to add to that.
 */
(function () {
    'use strict';

    // The Print button. A delegated listener rather than an `onclick` attribute,
    // which an enforced CSP would refuse.
    document.addEventListener('click', function (event) {
        var target = event.target;

        if (target instanceof Element && target.closest('[data-print]')) {
            window.print();
        }
    });

    // Opened in a new tab from the record screen, so it offers the print dialogue
    // straight away. `?noprint` suppresses that, because this view is also directly
    // addressable and somebody reading it should not be ambushed by a dialogue.
    if (window.location.search.indexOf('noprint') === -1) {
        window.addEventListener('load', function () {
            window.print();
        });
    }
})();
