/*
 * Searchable checkbox multiselect, as a progressive enhancement over a native
 * <select multiple>.
 *
 * Mark any <select multiple> with `data-multiselect` and it becomes a dropdown
 * whose panel holds a filter box and one checkbox per option. The native select
 * stays the source of truth — it keeps its name, its selected options and its
 * validation — so if this script fails to load, or is blocked by the CSP, the
 * field degrades to the ordinary multi-select it already was and still submits
 * `name[]` exactly as the server expects. Nothing here is required for the form
 * to work; it exists because ctrl-clicking a scrolling list is a trap, and an
 * officer who loses a selection by mis-clicking has silently changed a
 * recruitment rule.
 *
 * Sibling of public/js/searchable-select.js, which does the same job for a
 * single-value <select>. That one deliberately does *not* handle `multiple` — it
 * assigns `select.value`, which on a multiple select collapses the choice to one
 * option — so a multi-select must be marked `data-multiselect`, never
 * `data-searchable`.
 *
 * Dependency-free and Bootstrap-free on purpose. The project self-hosts its
 * assets, ships no bundler for these screens, and enforces a CSP that forbids
 * inline handlers; every listener below is attached in JavaScript.
 *
 * Accessibility: the toggle is a real <button> carrying aria-expanded and
 * aria-controls; the panel is a group of ordinary checkboxes, so a screen reader
 * and the keyboard both get the semantics for free. Escape closes and returns
 * focus to the toggle; Tab out of the panel closes it.
 *
 * Applies to selects present at load and to any added later (the feeder grids add
 * rows), via a MutationObserver.
 */
(function () {
    'use strict';

    var idSeed = 0;

    function enhance(select) {
        if (select.dataset.multiselectReady === '1') {
            return;
        }
        select.dataset.multiselectReady = '1';

        var data = select.dataset;
        var text = {
            none: data.multiselectNone || 'None selected',
            count: data.multiselectCount || ':count selected',
            noMatch: data.multiselectNoMatch || 'No match',
            search: data.multiselectSearch || 'Search…',
            clear: data.multiselectClear || 'Clear',
            done: data.multiselectDone || 'Done',
        };

        var small = select.classList.contains('form-select-sm');
        var panelId = 'ms-' + (++idSeed);

        var wrap = document.createElement('div');
        wrap.className = 'ms-wrap';

        var toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'form-select text-start ms-toggle' + (small ? ' form-select-sm' : '');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-haspopup', 'true');
        toggle.setAttribute('aria-controls', panelId);
        toggle.disabled = select.disabled;

        // Carry the <label> across, so clicking it opens the control the officer
        // can actually operate rather than the hidden select behind it.
        var label = select.id ? document.querySelector('label[for="' + select.id + '"]') : null;
        if (label) {
            toggle.id = select.id + '-toggle';
            label.setAttribute('for', toggle.id);
        }

        var panel = document.createElement('div');
        panel.className = 'ms-panel d-none';
        panel.id = panelId;
        panel.setAttribute('role', 'group');
        if (label) {
            panel.setAttribute('aria-label', label.textContent.trim());
        }

        var search = document.createElement('input');
        search.type = 'text';
        search.className = 'form-control form-control-sm ms-search';
        search.placeholder = text.search;
        search.autocomplete = 'off';
        search.setAttribute('aria-label', text.search);

        var list = document.createElement('div');
        list.className = 'ms-options';

        var empty = document.createElement('div');
        empty.className = 'ms-empty d-none';
        empty.textContent = text.noMatch;

        var footer = document.createElement('div');
        footer.className = 'ms-footer d-flex justify-content-between align-items-center gap-2 mt-2 pt-2';

        var count = document.createElement('span');
        count.className = 'ms-count small text-body-secondary';

        var clear = document.createElement('button');
        clear.type = 'button';
        clear.className = 'btn btn-link btn-sm p-0 ms-clear';
        clear.textContent = text.clear;

        var done = document.createElement('button');
        done.type = 'button';
        done.className = 'btn btn-primary btn-sm ms-done';
        done.textContent = text.done;

        var right = document.createElement('div');
        right.className = 'd-flex align-items-center gap-2';
        right.appendChild(clear);
        right.appendChild(done);

        footer.appendChild(count);
        footer.appendChild(right);

        panel.appendChild(search);
        panel.appendChild(list);
        panel.appendChild(empty);
        panel.appendChild(footer);

        /*
         * What is selected, spelled out under the control and always visible.
         *
         * Reported 2026-09-06: with five qualifications chosen the officer could see
         * only "5 selected", had to open the panel to find out which, and then had
         * to scroll a long master to find them. A chip strip answers both — it names
         * every choice without opening anything, and each chip removes its own.
         */
        var chips = document.createElement('div');
        chips.className = 'ms-chips d-flex flex-wrap gap-1 mt-1';

        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(select);
        wrap.appendChild(toggle);
        wrap.appendChild(chips);
        wrap.appendChild(panel);

        select.classList.add('ms-native');
        select.setAttribute('aria-hidden', 'true');
        select.tabIndex = -1;

        /*
         * One checkbox per option, built once and then kept in step.
         *
         * `hidden` and `disabled` options are skipped for the same reason the
         * single-value sibling skips them: a caller that hides an option means it,
         * and offering a choice the server then rejects is worse than not offering
         * it. The empty-valued option — a "— Select —" placeholder that a multiple
         * select should not really carry — is skipped too.
         */
        var rows = [];

        Array.prototype.slice.call(select.options).forEach(function (option) {
            if (option.value === '' || option.hidden || option.disabled) {
                return;
            }

            var row = document.createElement('div');
            row.className = 'form-check ms-option';

            var box = document.createElement('input');
            box.type = 'checkbox';
            box.className = 'form-check-input';
            box.id = panelId + '-' + option.value;
            box.checked = option.selected;
            box.disabled = select.disabled;

            var caption = document.createElement('label');
            caption.className = 'form-check-label';
            caption.setAttribute('for', box.id);
            caption.textContent = option.text.trim();

            box.addEventListener('change', function () {
                option.selected = box.checked;
                paint();
                // Tell the rest of the page. Nothing listens today; the feeder
                // grids listen to their other selects, and a rule that starts
                // depending on the qualification set should not have to discover
                // that this control was silent.
                select.dispatchEvent(new Event('change', { bubbles: true }));
            });

            /* The whole row toggles, not just the twelve pixels of the box. The
               label already does it natively, so a click that landed on either of
               them is left alone — forwarding it as well would toggle twice and
               look like nothing happened. */
            row.addEventListener('click', function (e) {
                if (e.target === box || e.target === caption) { return; }

                box.click();
            });

            row.appendChild(box);
            row.appendChild(caption);
            list.appendChild(row);

            rows.push({ option: option, box: box, row: row, haystack: caption.textContent.toLowerCase() });
        });

        /* The toggle's caption. Names while there are few enough to read at a
           glance, a count once there are not — the officer needs to know *what*
           they picked far more often than they need the number. */
        function paint() {
            var chosen = rows.filter(function (r) { return r.option.selected; });

            toggle.classList.toggle('ms-empty-selection', chosen.length === 0);

            /* A count, not the names. The chip strip below spells them out and does
               not truncate, so repeating them here would only compete with it — and
               a one-line button truncates exactly when the list gets long enough to
               matter. */
            toggle.textContent = chosen.length === 0
                ? text.none
                : text.count.replace(':count', String(chosen.length));

            toggle.title = chosen.map(function (r) { return r.option.text.trim(); }).join(', ');

            count.textContent = chosen.length === 0
                ? text.none
                : text.count.replace(':count', String(chosen.length));

            rows.forEach(function (r) {
                r.row.classList.toggle('is-chosen', r.option.selected);
            });

            /* DOM calls rather than innerHTML: these are master rows written by an
               administrator, and this is the one place they would be rendered
               unescaped by mistake. */
            chips.replaceChildren();

            chosen.forEach(function (r) {
                var chip = document.createElement('span');
                chip.className = 'badge ms-chip d-inline-flex align-items-center gap-1';

                var label = document.createElement('span');
                label.textContent = r.option.text.trim();
                chip.appendChild(label);

                if (!select.disabled) {
                    var drop = document.createElement('button');
                    drop.type = 'button';
                    drop.className = 'btn-close btn-close-sm ms-chip-drop';
                    drop.setAttribute('aria-label', text.clear + ': ' + r.option.text.trim());
                    drop.addEventListener('click', function () {
                        r.box.checked = false;
                        r.option.selected = false;
                        paint();
                        select.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                    chip.appendChild(drop);
                }

                chips.appendChild(chip);
            });
        }

        function filter() {
            var needle = search.value.trim().toLowerCase();
            var shown = 0;

            rows.forEach(function (r) {
                var match = needle === '' || r.haystack.indexOf(needle) !== -1;
                r.row.classList.toggle('d-none', !match);
                if (match) { shown++; }
            });

            empty.classList.toggle('d-none', shown > 0);
        }

        /*
         * Place the panel against the toggle, in viewport coordinates.
         *
         * **The panel is `position: fixed`, and this is why.** Both feeder grids sit
         * inside a `.table-responsive`, which is `overflow-x: auto` — and an
         * `overflow` other than `visible` clips absolutely-positioned descendants on
         * *both* axes. An absolutely-positioned panel was therefore cut off at the
         * bottom of its own table cell and looked, from the officer's side, as
         * though the dropdown simply did not open (reported 2026-09-06). `fixed`
         * takes it out of that clip entirely.
         *
         * The cost of `fixed` is that it does not travel with the page, so the
         * panel is repositioned on scroll and resize and closes if its toggle
         * scrolls out of view.
         */
        function place() {
            var box = toggle.getBoundingClientRect();
            var width = Math.max(box.width, 240);
            var room = window.innerHeight - box.bottom;

            panel.style.width = width + 'px';
            panel.style.left = Math.max(8, Math.min(box.left, window.innerWidth - width - 8)) + 'px';

            // Open upwards when there is more room above than below, so a control
            // near the foot of a long feeder grid is still usable.
            if (room < 240 && box.top > room) {
                panel.style.top = '';
                panel.style.bottom = (window.innerHeight - box.top + 4) + 'px';
            } else {
                panel.style.bottom = '';
                panel.style.top = (box.bottom + 4) + 'px';
            }
        }

        function open() {
            if (select.disabled) {
                return;
            }
            panel.classList.remove('d-none');
            toggle.setAttribute('aria-expanded', 'true');
            search.value = '';

            /* Chosen options to the top, **on open only**.
             *
             * With a long master the fifth thing an officer picked can be a hundred
             * rows down, and reopening the panel to check it means scrolling for it.
             * Sorting on open puts every choice in view immediately; sorting on
             * every toggle instead would make a row jump out from under the pointer
             * mid-click, which is worse than the problem.
             */
            rows.slice().reverse().forEach(function (r) {
                if (r.option.selected) { list.insertBefore(r.row, list.firstChild); }
            });

            filter();
            place();
            search.focus();
        }

        function close(returnFocus) {
            panel.classList.add('d-none');
            toggle.setAttribute('aria-expanded', 'false');
            if (returnFocus) { toggle.focus(); }
        }

        function isOpen() {
            return !panel.classList.contains('d-none');
        }

        toggle.addEventListener('click', function () {
            if (isOpen()) { close(false); } else { open(); }
        });

        search.addEventListener('input', filter);

        wrap.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && isOpen()) {
                e.preventDefault();
                close(true);
            }
        });

        clear.addEventListener('click', function () {
            rows.forEach(function (r) {
                r.option.selected = false;
                r.box.checked = false;
            });
            paint();
            select.dispatchEvent(new Event('change', { bubbles: true }));
            search.focus();
        });

        done.addEventListener('click', function () { close(true); });

        /*
         * Close when focus lands somewhere outside — `focusin`, never `focusout`.
         *
         * **This was the selection bug reported on 2026-09-06** ("display is fixed
         * but I cannot select"). `focusout` fires the moment focus *leaves* the
         * search box, which is on `mousedown`; a `<label>` does not take focus
         * until the *click* completes, so between the two the active element is
         * `<body>` and a deferred "has focus left the control?" check answered yes
         * and hid the panel. The click then landed on nothing. Every attempt to
         * tick a box closed the dropdown instead.
         *
         * `focusin` fires when focus has already arrived, so there is no
         * in-between state to misread.
         */
        document.addEventListener('focusin', function (e) {
            if (isOpen() && !wrap.contains(e.target)) {
                close(false);
            }
        });

        document.addEventListener('mousedown', function (e) {
            if (isOpen() && !wrap.contains(e.target) && !panel.contains(e.target)) {
                close(false);
            }
        });

        // `fixed` does not follow the page, so an open panel is re-placed as the
        // page or the table scrolls under it. Capture phase, because the scroll
        // that moves it is usually the table's own and does not bubble.
        window.addEventListener('scroll', function () {
            if (isOpen()) { place(); }
        }, true);

        window.addEventListener('resize', function () {
            if (isOpen()) { place(); }
        });

        /* Keep the checkboxes in step when something else changes the select —
           a row being repopulated after a validation failure, or disabled. */
        select.addEventListener('ms:refresh', function () {
            toggle.disabled = select.disabled;
            rows.forEach(function (r) {
                r.box.checked = r.option.selected;
                r.box.disabled = select.disabled;
            });
            paint();
        });

        paint();
    }

    function scan(root) {
        (root || document).querySelectorAll('select[multiple][data-multiselect]').forEach(enhance);
    }

    document.addEventListener('DOMContentLoaded', function () {
        scan(document);

        new MutationObserver(function (mutations) {
            mutations.forEach(function (m) {
                m.addedNodes.forEach(function (node) {
                    if (node.nodeType !== 1) {
                        return;
                    }
                    if (node.matches && node.matches('select[multiple][data-multiselect]')) {
                        enhance(node);
                    }
                    scan(node);
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
    });
})();
