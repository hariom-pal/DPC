// Whole-number boxes (2026-10-28): no decimal point, no minus sign, no exponent —
// typed, pasted or stepped — and never outside the box's own min and max.
(function () {
    'use strict';

    var blocked = ['-', '+', '.', ',', 'e', 'E'];

    function clamp(input) {
        var digits = input.value.replace(/[^0-9]/g, '');
        if (digits === '') {
            input.value = '';
            return;
        }
        var value = parseInt(digits, 10);
        var min = input.min !== '' ? parseInt(input.min, 10) : 0;
        var max = input.max !== '' ? parseInt(input.max, 10) : null;
        if (value < Math.max(0, min)) {
            value = Math.max(0, min);
        }
        if (max !== null && value > max) {
            value = max;
        }
        input.value = String(value);
    }

    document.addEventListener('keydown', function (event) {
        if (event.target.matches && event.target.matches('input[data-whole-number]') && blocked.indexOf(event.key) !== -1) {
            event.preventDefault();
        }
    });

    document.addEventListener('paste', function (event) {
        var input = event.target;
        if (!(input.matches && input.matches('input[data-whole-number]'))) {
            return;
        }
        var text = (event.clipboardData || window.clipboardData).getData('text');
        if (!/^[0-9]+$/.test(text.trim())) {
            event.preventDefault();
        }
    });

    document.addEventListener('change', function (event) {
        if (event.target.matches && event.target.matches('input[data-whole-number]')) {
            clamp(event.target);
        }
    });
})();
