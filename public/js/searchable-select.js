/*
 * Searchable dropdown, as a progressive enhancement over a native <select>.
 *
 * Mark any <select> with `data-searchable` and it gains a type-to-filter box.
 * The native <select> stays the source of truth — it keeps its name, its value
 * and its validation — so if this script fails to load, or is blocked, the field
 * degrades to an ordinary dropdown that still works and still submits. That
 * matters more than the widget does: this is a government portal and the form
 * behind it is a legal record.
 *
 * Deliberately dependency-free. The project self-hosts its assets and ships no
 * bundler for the admin screens, so pulling in a select library would mean
 * vendoring one and owning its upgrades for the sake of one filter box.
 *
 * Accessibility: the combobox input carries the ARIA combobox roles and drives a
 * listbox of options. Arrow keys move, Enter and click choose, Escape closes and
 * restores, Tab leaves. The underlying select is hidden from the accessibility
 * tree only while the enhancement is active.
 *
 * Applies to selects present at load and to any added later (the feeder grid adds
 * rows), via a MutationObserver.
 */
(function () {
    'use strict';

    var idSeed = 0;

    function enhance(select) {
        if (select.dataset.searchableReady === '1') {
            return;
        }
        select.dataset.searchableReady = '1';

        var id = 'ss-' + (++idSeed);
        var placeholder = select.dataset.searchablePlaceholder || 'Search…';

        var wrap = document.createElement('div');
        wrap.className = 'ss-wrap';

        var input = document.createElement('input');
        input.type = 'text';
        input.className = select.classList.contains('form-select-sm')
            ? 'form-control form-control-sm ss-input'
            : 'form-control ss-input';
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-controls', id);
        input.autocomplete = 'off';
        input.placeholder = placeholder;
        if (select.required) {
            input.setAttribute('aria-required', 'true');
        }
        if (select.disabled) {
            input.disabled = true;
        }

        // Carry the label association across, so clicking the <label> focuses the
        // box the user can actually type in.
        var label = select.id ? document.querySelector('label[for="' + select.id + '"]') : null;
        if (label) {
            input.id = select.id + '-search';
            label.setAttribute('for', input.id);
        }

        var list = document.createElement('div');
        list.className = 'ss-list d-none';
        list.id = id;
        list.setAttribute('role', 'listbox');

        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(select);
        wrap.appendChild(input);
        wrap.appendChild(list);

        select.classList.add('ss-native');
        select.setAttribute('aria-hidden', 'true');
        select.tabIndex = -1;

        var active = -1;

        /*
         * The options this combobox may offer.
         *
         * `hidden` and `disabled` are honoured, not just the empty placeholder.
         * A caller that hides an option means it — the feeder grid hides the
         * target post so a post cannot feed itself, and hides posts already
         * chosen on another row — and a list that showed them anyway would offer
         * a choice the server then rejects.
         */
        function options() {
            return Array.prototype.slice.call(select.options).filter(function (o) {
                return o.value !== '' && !o.hidden && !o.disabled;
            });
        }

        function syncInputFromSelect() {
            var chosen = select.options[select.selectedIndex];
            input.value = chosen && chosen.value !== '' ? chosen.text.trim() : '';
        }

        function render(term) {
            list.textContent = '';
            active = -1;
            var needle = term.trim().toLowerCase();
            var matches = options().filter(function (o) {
                return needle === '' || o.text.toLowerCase().indexOf(needle) !== -1;
            });

            if (matches.length === 0) {
                var none = document.createElement('div');
                none.className = 'ss-empty';
                none.textContent = 'No match';
                list.appendChild(none);
                return;
            }

            matches.forEach(function (o) {
                var item = document.createElement('div');
                item.className = 'ss-option';
                item.setAttribute('role', 'option');
                item.dataset.value = o.value;
                item.textContent = o.text.trim();
                if (o.selected) {
                    item.setAttribute('aria-selected', 'true');
                }
                item.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    choose(o.value);
                });
                list.appendChild(item);
            });
        }

        function open() {
            if (select.disabled) {
                return;
            }
            render(input.value === select.options[select.selectedIndex] ? '' : '');
            list.classList.remove('d-none');
            input.setAttribute('aria-expanded', 'true');
        }

        function close() {
            list.classList.add('d-none');
            input.setAttribute('aria-expanded', 'false');
            active = -1;
            syncInputFromSelect();
        }

        function choose(value) {
            select.value = value;
            // Tell the rest of the page: the feeder grid recomputes its total and
            // its eligible-post filtering from the select's change event.
            select.dispatchEvent(new Event('change', { bubbles: true }));
            close();
        }

        function items() {
            return Array.prototype.slice.call(list.querySelectorAll('.ss-option'));
        }

        function highlight(next) {
            var all = items();
            if (all.length === 0) {
                return;
            }
            active = (next + all.length) % all.length;
            all.forEach(function (el, i) {
                el.classList.toggle('is-active', i === active);
                if (i === active) {
                    el.scrollIntoView({ block: 'nearest' });
                }
            });
        }

        input.addEventListener('focus', open);
        input.addEventListener('input', function () {
            render(input.value);
            list.classList.remove('d-none');
            input.setAttribute('aria-expanded', 'true');
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (list.classList.contains('d-none')) { open(); }
                highlight(active + 1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                highlight(active - 1);
            } else if (e.key === 'Enter') {
                var all = items();
                if (!list.classList.contains('d-none') && active >= 0 && all[active]) {
                    e.preventDefault();
                    choose(all[active].dataset.value);
                }
            } else if (e.key === 'Escape') {
                close();
            }
        });
        input.addEventListener('blur', function () {
            // Let a mousedown on an option land first.
            window.setTimeout(close, 120);
        });

        // Keep the box in step when something else sets the select (repopulating
        // after a validation failure, or the grid disabling a row).
        select.addEventListener('change', syncInputFromSelect);
        select.addEventListener('ss:refresh', function () {
            input.disabled = select.disabled;
            syncInputFromSelect();
        });

        syncInputFromSelect();
    }

    function scan(root) {
        (root || document).querySelectorAll('select[data-searchable]').forEach(enhance);
    }

    document.addEventListener('DOMContentLoaded', function () {
        scan(document);

        new MutationObserver(function (mutations) {
            mutations.forEach(function (m) {
                m.addedNodes.forEach(function (node) {
                    if (node.nodeType !== 1) {
                        return;
                    }
                    if (node.matches && node.matches('select[data-searchable]')) {
                        enhance(node);
                    }
                    scan(node);
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
    });
})();
