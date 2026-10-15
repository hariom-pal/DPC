/**
 * Pre-DPC form — the Promotion From Post rows, and what each post determines
 * (change item 5).
 *
 * Two jobs:
 *
 *  1. **Add and remove Promotion From Post rows.** A promotion may draw on several
 *     feeders — Deputy Director from Assistant Director, Senior Lecturer and
 *     Research Officer — which is what Schedule-IV says on most rungs.
 *  2. **Show each post's own Pay Scale and Class.** Promotion eligibility is judged
 *     on the officer's *current* post, so the figures beside a feeder are that
 *     feeder's own. Until 2026-08-25 the screen showed the Promotion To Post's
 *     scale and class beside everything, which stated the scale of the post nobody
 *     had been promoted into yet.
 *
 * The values are asked of the server rather than computed here, and that is the
 * point: `PostAttributeResolver` is the one place the derivation lives and is also
 * what the save path uses, so what is shown cannot disagree with what is stored.
 *
 * Nothing here is authoritative. A user with a debugger can display whatever they
 * like in these read-only cells; the server re-derives them on save from the
 * Department Post master and ignores anything the form might have sent.
 *
 * **Every string it writes comes from the page, not from here.** The portal is
 * bilingual; a script with English baked into it would put English into a Hindi
 * screen. The four labels arrive as `data-` attributes on the table, rendered from
 * the same catalogue keys the server-side row uses.
 *
 * No inline handler and no inline script — the enforced CSP forbids both, and
 * `ViewsAreCspCleanTest` fails the build if either comes back.
 */
(function () {
    'use strict';

    var toPost = document.getElementById('promotion_to_post_id');
    var table = document.getElementById('promotion-sources-table');

    if (!toPost || !table) { return; }

    var url = toPost.dataset.attributesUrl;
    var body = table.querySelector('tbody');
    var template = document.getElementById('promotion-source-row-template');
    var addButton = document.getElementById('add-promotion-source');
    var toAttributes = document.getElementById('to_post_attributes');

    if (!url || !body) { return; }

    var text = {
        payScale: table.dataset.labelPayScale || 'Pay Scale',
        klass: table.dataset.labelClass || 'Class',
        empty: table.dataset.textEmpty || '',
        notLive: table.dataset.textNotLive || '',
        unavailable: table.dataset.textUnavailable || '',
    };

    /* The request in flight per cell, so a fast pair of changes cannot land out of
       order and leave the older answer on screen. Keyed by the element itself. */
    var pending = new WeakMap();

    function labelFor(data) {
        var scale = data.pay_scale_label || '—';
        var klass = data.class_label || '—';

        return {scale: scale, klass: klass};
    }

    /** Ask the server about one post and hand the answer to a callback. */
    function lookup(cell, postId, render) {
        var token = (pending.get(cell) || 0) + 1;
        pending.set(cell, token);

        fetch(url + '?post_id=' + encodeURIComponent(postId), {
            headers: {Accept: 'application/json'},
            credentials: 'same-origin',
        })
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (data) {
                if (pending.get(cell) !== token) { return; }

                var post = data && data.posts && data.posts.length ? data.posts[0] : null;

                if (!post) {
                    /* A post that is not a live post of this department resolves to
                       nothing. Saying so beats showing a stale value. */
                    render(null);

                    return;
                }

                render(post);
            })
            .catch(function () {
                if (pending.get(cell) !== token) { return; }

                /* A failed lookup must not look like an answer. The save path
                   derives these server-side regardless, so the order is still
                   correct — only the preview is missing. */
                render(undefined);
            });
    }

    /** Fill one feeder row's Pay Scale and Class cell. */
    function refreshRow(row) {
        var select = row.querySelector('.source-post');
        var cell = row.querySelector('.source-attributes');

        if (!select || !cell) { return; }

        if (!select.value) {
            cell.textContent = text.empty;

            return;
        }

        cell.textContent = '…';

        lookup(cell, select.value, function (post) {
            if (post === null) {
                cell.textContent = text.notLive;

                return;
            }

            if (post === undefined) {
                cell.textContent = text.unavailable;

                return;
            }

            var parts = labelFor(post);
            cell.textContent = '';

            var scaleLine = document.createElement('span');
            scaleLine.className = 'd-block';
            scaleLine.textContent = text.payScale + ': ' + parts.scale;

            var classLine = document.createElement('span');
            classLine.className = 'd-block';
            classLine.textContent = text.klass + ': ' + parts.klass;

            /* Built as elements with textContent rather than assigned as an HTML
               string: a post name is a database value, and this is the one place on
               the screen where it would otherwise be parsed as markup. */
            cell.appendChild(scaleLine);
            cell.appendChild(classLine);
        });
    }

    function refreshToPost() {
        if (!toAttributes) { return; }

        if (!toPost.value) {
            toAttributes.textContent = '—';

            return;
        }

        toAttributes.textContent = '…';

        lookup(toAttributes, toPost.value, function (post) {
            if (!post) {
                toAttributes.textContent = post === null ? text.notLive : text.unavailable;

                return;
            }

            var parts = labelFor(post);
            toAttributes.textContent = text.payScale + ': ' + parts.scale
                + ' · ' + text.klass + ': ' + parts.klass;
        });
    }

    function rows() {
        return body.querySelectorAll('.promotion-source-row');
    }

    /** The last row may not be removed: an order names at least one feeder. */
    function syncRemoveButtons() {
        var all = rows();

        all.forEach(function (row) {
            var button = row.querySelector('.remove-promotion-source');

            if (button) { button.disabled = all.length <= 1; }
        });
    }

    if (addButton && template) {
        addButton.addEventListener('click', function () {
            var index = parseInt(body.dataset.nextIndex || String(rows().length), 10);
            var markup = template.innerHTML.split('__INDEX__').join(String(index));

            /* The template wraps its row in a table so the browser keeps the `<tr>`
               while parsing; only the row itself is taken out of it. */
            var holder = document.createElement('tbody');
            holder.innerHTML = markup;

            var row = holder.querySelector('tr');

            if (!row) { return; }

            body.appendChild(row);
            body.dataset.nextIndex = String(index + 1);

            syncRemoveButtons();
            row.querySelector('.source-post').focus();
        });
    }

    body.addEventListener('change', function (event) {
        if (event.target.classList.contains('source-post')) {
            refreshRow(event.target.closest('.promotion-source-row'));
        }
    });

    body.addEventListener('click', function (event) {
        if (!event.target.classList.contains('remove-promotion-source')) { return; }

        if (rows().length <= 1) { return; }

        event.target.closest('.promotion-source-row').remove();
        syncRemoveButtons();
    });

    /* ---------------------------------------------------------------------
     * The feeder shares, and the Save button they govern.
     *
     * Business instruction of 2026-09-06: *"share should always be calculated to
     * 100; if it is under 100 do not let it submit"*.
     *
     * **This is a courtesy, not the rule.**
     * `SavePreDpcProposalRequest::assertSourceSharesTotalOneHundred()` refuses the
     * same thing on the server and is what decides — a form submitted with
     * JavaScript off is judged identically. What this adds is the answer *while the
     * officer is typing*, and which way they are out, instead of after a round trip.
     *
     * Totalled in integer paise for the same reason the server totals them that way:
     * `33.33 + 33.33 + 33.34` is a legitimate three-way split, and floating-point
     * noise must not be reported as a shortfall.
     * ------------------------------------------------------------------- */
    var totalEl = document.getElementById('share-total');
    var saveButton = document.getElementById('save-pre-dpc');

    function shareTotal() {
        var paise = 0;

        body.querySelectorAll('input[name$="[promotion_percentage]"]').forEach(function (input) {
            paise += Math.round((parseFloat(input.value) || 0) * 100);
        });

        return paise;
    }

    function refreshShareTotal() {
        if (!totalEl) { return; }

        var paise = shareTotal();
        var total = (paise / 100).toFixed(2).replace(/\.00$/, '');
        var exact = paise === 10000;

        totalEl.className = 'small ' + (exact ? 'text-success' : 'text-danger');

        if (exact) {
            totalEl.textContent = totalEl.dataset.textOk || '';
        } else if (paise < 10000) {
            totalEl.textContent = (totalEl.dataset.textShort || '')
                .replace(':total', total)
                .replace(':short', ((10000 - paise) / 100).toFixed(2).replace(/\.00$/, ''));
        } else {
            totalEl.textContent = (totalEl.dataset.textOver || '')
                .replace(':total', total)
                .replace(':over', ((paise - 10000) / 100).toFixed(2).replace(/\.00$/, ''));
        }

        /* Save is blocked, but only on the share.
         *
         * `disabled` rather than a submit-time alert, because the officer asked for
         * "do not let it submit" — and a disabled button beside a sentence saying
         * how far out the total is explains itself, where a blocked submit does not.
         */
        if (saveButton && !saveButton.hasAttribute('data-no-posts')) {
            saveButton.disabled = !exact;
        }
    }

    body.addEventListener('input', function (event) {
        if (event.target.name && event.target.name.indexOf('[promotion_percentage]') !== -1) {
            refreshShareTotal();
        }
    });

    // A row added or removed changes the total as surely as a keystroke does.
    body.addEventListener('click', function (event) {
        if (event.target.classList.contains('remove-promotion-source')) {
            window.setTimeout(refreshShareTotal, 0);
        }
    });

    if (addButton) {
        addButton.addEventListener('click', function () { window.setTimeout(refreshShareTotal, 0); });
    }

    toPost.addEventListener('change', refreshToPost);

    syncRemoveButtons();
    refreshShareTotal();
})();
