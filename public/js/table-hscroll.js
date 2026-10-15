/*
 * Wide tables that can be scrolled sideways without hunting for the scrollbar.
 *
 * Reported 2026-09-06: *"wherever there is a horizontal scroll bar we have to go
 * all the way down, then scroll — give something better, this is painful."* That is
 * exactly what a `.table-responsive` does on a long table: the scrollbar belongs to
 * the wrapper, so it sits at the *bottom of the table*, which on a hundred-row
 * evaluation chart is several screens below whatever the reader is looking at.
 *
 * Three things are added, none of which replaces the native scrollbar:
 *
 *   1. **A floating scrollbar pinned to the bottom of the viewport**, shown only
 *      while the table is on screen *and* its own scrollbar is not. It is a real
 *      scrollbar over a spacer of the table's width — not a drawn one — so it
 *      behaves the way the operating system's does, and the two are kept in step in
 *      both directions.
 *   2. **Drag to pan.** Grab the table anywhere that is not text or a control and
 *      move it. This is what people try first.
 *   3. **Edge shadows**, so it is visible that there is more to the right at all.
 *      A table that scrolls and does not say so is a table whose last four columns
 *      nobody ever reads.
 *
 * Shift+wheel already works natively and is left alone.
 *
 * Applies to every `.table-responsive` on the page, and to any added later (the
 * feeder grids add rows, and a re-render can replace a whole wrapper).
 *
 * Dependency-free and CSP-clean: no inline handlers, no library. If this file fails
 * to load, every table keeps its ordinary scrollbar and nothing is lost.
 */
(function () {
    'use strict';

    function enhance(wrap) {
        if (wrap.dataset.hscrollReady === '1') {
            return;
        }
        wrap.dataset.hscrollReady = '1';

        // A wrapper whose content fits needs none of this, and the check is redone
        // on resize because a window can always be made narrower.
        function overflows() {
            return wrap.scrollWidth - wrap.clientWidth > 1;
        }

        // ---- 1. the floating scrollbar ------------------------------------
        var bar = document.createElement('div');
        bar.className = 'hscroll-bar d-none';
        bar.setAttribute('aria-hidden', 'true');

        var spacer = document.createElement('div');
        spacer.className = 'hscroll-spacer';
        bar.appendChild(spacer);
        document.body.appendChild(bar);

        // Guards against the two elements chasing each other: assigning
        // `scrollLeft` fires `scroll`, which would assign it back.
        var syncing = false;

        function mirror(from, to) {
            if (syncing) { return; }
            syncing = true;
            to.scrollLeft = from.scrollLeft;
            window.requestAnimationFrame(function () { syncing = false; });
        }

        wrap.addEventListener('scroll', function () {
            mirror(wrap, bar);
            paintEdges();
        });
        bar.addEventListener('scroll', function () { mirror(bar, wrap); });

        /*
         * Show the floating bar only when it is the only way to scroll.
         *
         * That means: some of the table is on screen, and the wrapper's own
         * scrollbar — which sits on its bottom edge — is not. Showing it while the
         * real one is visible would put two scrollbars a few pixels apart.
         */
        function place() {
            if (!overflows()) {
                bar.classList.add('d-none');

                return;
            }

            var box = wrap.getBoundingClientRect();
            var viewport = window.innerHeight;
            var onScreen = box.top < viewport && box.bottom > 0;
            var ownBarVisible = box.bottom <= viewport;

            bar.classList.toggle('d-none', !onScreen || ownBarVisible);

            if (bar.classList.contains('d-none')) {
                return;
            }

            bar.style.left = Math.max(0, box.left) + 'px';
            bar.style.width = Math.min(box.width, window.innerWidth - Math.max(0, box.left)) + 'px';
            spacer.style.width = wrap.scrollWidth + 'px';
            bar.scrollLeft = wrap.scrollLeft;
        }

        // ---- 2. drag to pan ------------------------------------------------
        var dragging = false;
        var startX = 0;
        var startScroll = 0;

        wrap.addEventListener('pointerdown', function (e) {
            // Never steal a click from something the reader meant to operate, and
            // never from a text selection.
            if (e.button !== 0 || !overflows()) { return; }
            if (e.target.closest('a, button, input, select, textarea, label, [contenteditable]')) { return; }

            dragging = true;
            startX = e.clientX;
            startScroll = wrap.scrollLeft;
            wrap.classList.add('is-panning');
        });

        wrap.addEventListener('pointermove', function (e) {
            if (!dragging) { return; }

            var moved = e.clientX - startX;

            // Below a few pixels this is a click, not a drag. Panning on a 2px
            // twitch would make every cell feel unstable.
            if (Math.abs(moved) < 3) { return; }

            e.preventDefault();
            wrap.scrollLeft = startScroll - moved;
        });

        ['pointerup', 'pointerleave', 'pointercancel'].forEach(function (event) {
            wrap.addEventListener(event, function () {
                dragging = false;
                wrap.classList.remove('is-panning');
            });
        });

        // ---- 3. edge shadows ------------------------------------------------
        function paintEdges() {
            var max = wrap.scrollWidth - wrap.clientWidth;

            wrap.classList.toggle('has-more-left', wrap.scrollLeft > 1);
            wrap.classList.toggle('has-more-right', wrap.scrollLeft < max - 1);
        }

        wrap._hscrollRefresh = function () {
            paintEdges();
            place();
        };

        wrap.classList.add('hscroll');
        paintEdges();
        place();
    }

    function refreshAll() {
        document.querySelectorAll('.table-responsive').forEach(function (wrap) {
            if (typeof wrap._hscrollRefresh === 'function') { wrap._hscrollRefresh(); }
        });
    }

    function scan(root) {
        (root || document).querySelectorAll('.table-responsive').forEach(enhance);
    }

    document.addEventListener('DOMContentLoaded', function () {
        scan(document);
        refreshAll();

        window.addEventListener('scroll', refreshAll, true);
        window.addEventListener('resize', refreshAll);

        new MutationObserver(function () {
            scan(document);
            refreshAll();
        }).observe(document.body, { childList: true, subtree: true });
    });
})();
