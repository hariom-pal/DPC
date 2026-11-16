/**
 * Gmail on the SMTP settings screen (2026-11-15): "Fill Gmail settings" puts in
 * Google's server, port and encryption, and the App Password hint shows while the
 * host is Gmail. External file, no inline script (CSP, TD-29).
 */
(function () {
    'use strict';

    var form = document.getElementById('smtp-settings');

    if (!form) { return; }

    var host = form.querySelector('[name="host"]');
    var port = form.querySelector('[name="port"]');
    var encryption = form.querySelector('[name="encryption"]');
    var username = form.querySelector('[name="username"]');
    var from = form.querySelector('[name="from_address"]');

    function isGmail() {
        var value = (host.value || '').trim().toLowerCase();

        return value === 'smtp.gmail.com' || value === 'smtp.googlemail.com';
    }

    function sync() {
        form.querySelectorAll('[data-gmail-only]').forEach(function (el) { el.hidden = !isGmail(); });
    }

    var preset = document.querySelector('[data-gmail-preset]');

    if (preset) {
        preset.addEventListener('click', function () {
            host.value = 'smtp.gmail.com';
            port.value = '587';
            encryption.value = 'tls';
            sync();
            username.focus();
        });
    }

    // Gmail sends only from the signed-in address: copy it to an empty From address.
    username.addEventListener('change', function () {
        if (isGmail() && from && !from.value && username.value.indexOf('@') > 0) {
            from.value = username.value.trim();
        }
    });

    host.addEventListener('input', sync);
    sync();
})();
