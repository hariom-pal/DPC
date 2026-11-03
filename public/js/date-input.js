/*
 * Dates typed and shown as DD-MM-YYYY, everywhere (UAT, 2026-11-02).
 *
 * Reported: "ye dateselector better karo ye sach me confusion hai". A native
 * `<input type="date">` shows the *browser's* format — MM/DD/YYYY on most machines —
 * while every screen and printout of this portal writes DD-MM-YYYY, so 03/04 was read
 * both ways.
 *
 * Each date field gets a text box in DD-MM-YYYY (dashes added while typing; `/` and
 * `.` accepted) and a calendar button that opens the browser's own picker. The
 * original input stays in the form, hidden, and is the one that submits — still
 * YYYY-MM-DD, so nothing on the server changes. Its `min`, `max` and `required` are
 * checked on the visible box, where the browser can show the message.
 *
 * Opt out with `data-native-date`. Dependency-free and CSP-clean; without this file
 * every date field is the browser's native one, as before.
 */
(function () {
    'use strict';

    var hint = document.documentElement.lang === 'hi' ? 'दिन-माह-वर्ष' : 'DD-MM-YYYY';

    function pad(n) { return (n < 10 ? '0' : '') + n; }

    function toDisplay(iso) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');

        return m ? m[3] + '-' + m[2] + '-' + m[1] : '';
    }

    /** DD-MM-YYYY (or with / or .) to YYYY-MM-DD; '' when not a real date. */
    function toIso(text) {
        var m = /^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/.exec(text.trim());

        if (!m) { return ''; }

        var d = parseInt(m[1], 10), mo = parseInt(m[2], 10), y = parseInt(m[3], 10);
        var date = new Date(y, mo - 1, d);

        if (date.getFullYear() !== y || date.getMonth() !== mo - 1 || date.getDate() !== d) { return ''; }

        return y + '-' + pad(mo) + '-' + pad(d);
    }

    /**
     * Digits typed straight through get their dashes: 01022026 → 01-02-2026. Left
     * alone when the reader wrote their own separators somewhere else (2-1-2026,
     * 02/01/2026), which toIso() reads as they are.
     */
    function autoDash(text) {
        if (/[^\d-]/.test(text)) { return text; }

        for (var i = 0; i < text.length; i++) {
            if (text[i] === '-' && i !== 2 && i !== 5) { return text; }
        }

        var digits = text.replace(/\D/g, '').slice(0, 8);

        if (digits.length > 4) { return digits.slice(0, 2) + '-' + digits.slice(2, 4) + '-' + digits.slice(4); }
        if (digits.length > 2) { return digits.slice(0, 2) + '-' + digits.slice(2); }

        return digits;
    }

    function enhance(native) {
        if (native.dataset.dateReady === '1' || native.hasAttribute('data-native-date') || native.type !== 'date') {
            return;
        }
        native.dataset.dateReady = '1';

        var box = document.createElement('input');
        box.type = 'text';
        box.inputMode = 'numeric';
        box.autocomplete = 'off';
        box.maxLength = 10;
        box.placeholder = hint;
        box.className = native.className;
        box.value = toDisplay(native.value);
        box.disabled = native.disabled;
        box.readOnly = native.readOnly;
        box.required = native.required;
        box.setAttribute('aria-describedby', native.getAttribute('aria-describedby') || '');
        if (native.autofocus) { box.autofocus = true; }

        // The label points at the box people type in.
        if (native.id) {
            box.id = native.id;
            native.id = native.id + '__iso';
        }

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-outline-secondary date-input-button' + (/form-control-sm/.test(native.className) ? ' btn-sm' : '');
        button.setAttribute('aria-label', document.documentElement.lang === 'hi' ? 'कैलेंडर खोलें' : 'Open calendar');
        button.textContent = '📅';
        button.disabled = native.disabled || native.readOnly;

        var group = document.createElement('div');
        group.className = 'input-group flex-nowrap date-input' + (/form-control-sm/.test(native.className) ? ' input-group-sm' : '');
        native.parentNode.insertBefore(group, native);
        group.appendChild(box);
        group.appendChild(button);
        group.appendChild(native);

        // Hidden but still in the form, and still able to open its picker.
        native.required = false;
        native.tabIndex = -1;
        native.setAttribute('aria-hidden', 'true');
        native.classList.add('date-input-native');

        function validate() {
            var text = box.value.trim();
            var iso = toIso(text);
            var message = '';

            if (text !== '' && iso === '') {
                message = document.documentElement.lang === 'hi' ? 'तिथि दिन-माह-वर्ष (DD-MM-YYYY) में लिखें।' : 'Enter the date as DD-MM-YYYY.';
            } else if (iso !== '' && native.min && iso < native.min) {
                message = (document.documentElement.lang === 'hi' ? 'तिथि इससे पहले नहीं हो सकती: ' : 'The date cannot be before ') + toDisplay(native.min) + '.';
            } else if (iso !== '' && native.max && iso > native.max) {
                message = (document.documentElement.lang === 'hi' ? 'तिथि इसके बाद नहीं हो सकती: ' : 'The date cannot be after ') + toDisplay(native.max) + '.';
            }

            box.setCustomValidity(message);

            return iso;
        }

        function push(iso) {
            if (native.value === iso) { return; }
            native.value = iso;
            native.dispatchEvent(new Event('input', {bubbles: true}));
            native.dispatchEvent(new Event('change', {bubbles: true}));
        }

        box.addEventListener('input', function (event) {
            // Deleting is left alone, or a dash could never be removed.
            var dashed = event.inputType && event.inputType.indexOf('delete') === 0 ? box.value : autoDash(box.value);
            if (dashed !== box.value) { box.value = dashed; }
            push(validate());
        });

        box.addEventListener('blur', function () {
            var iso = validate();
            if (iso !== '') { box.value = toDisplay(iso); }
        });

        native.addEventListener('change', function () {
            var shown = toDisplay(native.value);
            if (toIso(box.value) !== native.value) { box.value = shown; }
            validate();
        });

        button.addEventListener('click', function () {
            try {
                if (typeof native.showPicker === 'function') { native.showPicker(); return; }
            } catch (e) { /* falls through to focusing the box */ }
            box.focus();
        });

        validate();
    }

    function scan(root) {
        if (root.matches && root.matches('input[type="date"]')) { enhance(root); }
        if (root.querySelectorAll) { root.querySelectorAll('input[type="date"]').forEach(enhance); }
    }

    scan(document);

    // Rows added later (feeder grids, repeaters) are enhanced too.
    new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
            mutation.addedNodes.forEach(function (node) {
                if (node.nodeType === 1) { scan(node); }
            });
        });
    }).observe(document.body, {childList: true, subtree: true});
})();
