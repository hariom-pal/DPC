/**
 * The CR year form (MoM of 12 November 2026, point 7): as many CR blocks as the
 * officer says were written that year (up to four), and the NRC upload shown right
 * there as soon as a CR lacks one of the three authorities' remarks. The server
 * checks both again; this only shows the officer what is asked as they work.
 *
 * External file with no inline script, because the CSP forbids one (TD-29).
 */
(function () {
    'use strict';

    function init(root) {
    root.querySelectorAll('[data-cr-reports]').forEach(function (box) {
        var count = box.querySelector('[data-cr-count]');
        var nrc = box.querySelector('[data-cr-nrc]');
        if (!count) { return; }

        var sync = function () {
            var shown = parseInt(count.value, 10) || 1;
            var needsNrc = false;

            box.querySelectorAll('[data-cr-report]').forEach(function (report) {
                var index = parseInt(report.getAttribute('data-cr-report'), 10);
                var visible = index < shown;
                report.hidden = !visible;
                report.querySelectorAll('input, select').forEach(function (field) { field.disabled = !visible; });

                if (visible) {
                    var boxes = report.querySelectorAll('[data-cr-authority]');
                    var ticked = Array.prototype.filter.call(boxes, function (b) { return b.checked; }).length;
                    if (ticked > 0 && ticked < boxes.length) { needsNrc = true; }
                }
            });

            if (nrc) { nrc.hidden = !needsNrc; }
        };

        box.addEventListener('change', sync);
        sync();
    });
    }

    init(document);
    document.addEventListener('edpc:content', function (event) { init(event.detail.root); });
})();
