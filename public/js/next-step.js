/**
 * The next step (MoM of 12 November 2026, point 1): flags the element the banner names
 * with a green, continuously pulsing outline, and opens the pop-up shown after an action.
 *
 * External file with no inline script, because the CSP forbids one (TD-29).
 */
(function () {
    'use strict';

    var banner = document.querySelector('[data-next-step-target]');
    if (banner) {
        var target = document.getElementById(banner.getAttribute('data-next-step-target'));
        if (target) { target.classList.add('next-step-flag'); }
    }

    var modal = document.querySelector('[data-next-step-modal]');
    if (modal && window.bootstrap && window.bootstrap.Modal) {
        window.bootstrap.Modal.getOrCreateInstance(modal).show();
    }
})();
