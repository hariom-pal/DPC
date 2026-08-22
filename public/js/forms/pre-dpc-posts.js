/*
 * Pre-DPC form — repeating post cards, each with its own feeder-cadre grid
 * (M3, screen S8).
 *
 * Extracted from an inline `<script>` block on 2026-08-22 so that
 * `script-src 'self'` can be enforced rather than reported (TD-29). Logic
 * unchanged. Two facts used to be interpolated into the script and now arrive
 * on `#posts` as data: the saved posts (`data-seed`) and whether the department
 * has any cadre in the master at all (`data-has-cadres`).
 */
(function () {
    'use strict';

    var postsEl = document.getElementById('posts');
    var postTplEl = document.getElementById('post-template');
    var feederTplEl = document.getElementById('feeder-template');

    if (!postsEl || !postTplEl || !feederTplEl) { return; }

    var postTpl = postTplEl.innerHTML;
    var feederTpl = feederTplEl.innerHTML;
    var p = 0;

    function addPost() {
        var idx = p++;
        var wrap = document.createElement('div');
        wrap.innerHTML = postTpl.replaceAll('__P__', idx);
        postsEl.appendChild(wrap.firstElementChild);
        refreshEmpty(postsEl.lastElementChild.querySelector('tbody.feeders'));
        // A new card starts at DR 0 / PR 100, which is balanced — say so
        // now rather than leaving the ✓ blank until the user types.
        refreshSplit(postsEl.lastElementChild);
        return idx;
    }

    function addFeeder(postIdx, tbody) {
        var f = tbody.querySelectorAll('tr').length;
        var row = document.createElement('template');
        row.innerHTML = feederTpl.replaceAll('__P__', postIdx).replaceAll('__F__', f);
        tbody.appendChild(row.content.firstElementChild);
        refreshEmpty(tbody);
        return tbody.lastElementChild;
    }

    /* searchable-select.js enhances rows added after load by itself, via a
       MutationObserver — nothing here needs to attach it. What it cannot
       know is that we have *changed* a select's options or value after it
       was enhanced, so tell it to re-read them. */
    function enhance(scope) {
        scope.querySelectorAll('select[data-searchable]').forEach(function (select) {
            select.dispatchEvent(new CustomEvent('ss:refresh'));
        });
    }

    /* "No feeder cadre yet" belongs in the footer only while there is none. */
    function refreshEmpty(tbody) {
        var empty = tbody.parentElement.querySelector('.feeders-empty');
        if (empty) { empty.classList.toggle('d-none', tbody.querySelectorAll('tr').length > 0); }
    }

    /* Class and Pay Scale are the cadre's own attributes, so choosing the
       post fills them in. They stay editable: the Pre-DPC records what the
       Government Order says, and if the Order disagrees with the master the
       Order is what is being registered. */
    function applyCadreDefaults(card) {
        var select = card.querySelector('.post-cadre');
        var option = select.selectedOptions[0];
        if (!option || !option.value) { return; }

        var cls = card.querySelector('.post-class');
        var scale = card.querySelector('.post-pay-scale');
        if (option.dataset.class) { cls.value = option.dataset.class; }
        scale.value = option.dataset.payScale || '';
    }

    /* DR + PR per post card, advisory only — SavePreDpcProposalRequest
       enforces the same rule, and the approval that projects these posts
       into the Recruitment Rule master enforces it again there. */
    function refreshSplit(card) {
        var dr = parseFloat(card.querySelector('.dr-pct').value);
        var pr = parseFloat(card.querySelector('.pr-pct').value);
        var total = Math.round(((isNaN(dr) ? 0 : dr) + (isNaN(pr) ? 0 : pr)) * 100) / 100;
        var balanced = Math.abs(total - 100) < 0.01;
        var totalEl = card.querySelector('.split-total');

        totalEl.textContent = total + '%';
        totalEl.className = 'share-total split-total ' + (balanced ? 'is-balanced' : 'is-unbalanced');
        card.querySelector('.split-total-mark').textContent = balanced ? '✓' : '✗';
    }

    postsEl.addEventListener('input', function (e) {
        if (e.target.classList.contains('dr-pct') || e.target.classList.contains('pr-pct')) {
            refreshSplit(e.target.closest('.post-card'));
        }
    });

    postsEl.addEventListener('change', function (e) {
        if (e.target.classList.contains('post-cadre')) {
            applyCadreDefaults(e.target.closest('.post-card'));
        }
    });

    postsEl.addEventListener('click', function (e) {
        if (e.target.classList.contains('remove-post')) e.target.closest('.post-card').remove();
        if (e.target.classList.contains('remove-feeder')) {
            var tbody = e.target.closest('tbody.feeders');
            e.target.closest('tr').remove();
            refreshEmpty(tbody);
        }
        if (e.target.classList.contains('add-feeder')) {
            var card = e.target.closest('.post-card');
            addFeeder(card.querySelector('[name^="posts["]').name.match(/posts\[(\d+)\]/)[1], card.querySelector('tbody.feeders'));
        }
    });
    document.getElementById('add-post').addEventListener('click', function () { addPost(); });

    // Prefill from server data. With an empty Cadre master there is nothing
    // to select, so no blank card is opened — the warning above stands on
    // its own rather than sitting over a form that cannot be completed.
    var seed = JSON.parse(postsEl.dataset.seed || '[]');
    var hasCadres = postsEl.dataset.hasCadres === '1';

    if (seed.length === 0) {
        if (hasCadres) { addPost(); }
    } else {
        seed.forEach(function (post) {
            var idx = addPost();
            var card = postsEl.lastElementChild;

            /* Selected by id now, not by typed text.
               `set` is deliberate about a value the dropdown does not
               offer: a proposal drafted before the master was in use, or
               one naming a cadre since deactivated, would otherwise fall
               silently back to "— Select —" and the user would re-save a
               blank over real data. Instead the row keeps the value and
               says where it came from. */
            function set(el, value, label) {
                if (!el || value === null || value === undefined || value === '') { return; }
                el.value = value;
                if (el.value !== String(value) && label) {
                    var option = document.createElement('option');
                    option.value = value;
                    option.selected = true;
                    // textContent, never innerHTML: the label is a cadre name
                    // out of the database, and this is the one place on the
                    // screen where it would otherwise be parsed as markup.
                    option.textContent = label + ' (no longer in the master)';
                    el.insertBefore(option, el.firstChild);
                    el.value = value;
                }
            }

            set(card.querySelector('[name="posts[' + idx + '][cadre_id]"]'), post.cadre_id, post.post_name);
            if (post.class_level) card.querySelector('[name="posts[' + idx + '][class_level]"]').value = post.class_level;
            if (post.pay_scale_id) card.querySelector('[name="posts[' + idx + '][pay_scale_id]"]').value = post.pay_scale_id;
            card.querySelector('[name="posts[' + idx + '][direct_recruitment_pct]"]').value = post.direct_recruitment_pct == null ? 0 : post.direct_recruitment_pct;
            card.querySelector('[name="posts[' + idx + '][promotion_recruitment_pct]"]').value = post.promotion_recruitment_pct == null ? 100 : post.promotion_recruitment_pct;
            refreshSplit(card);

            var tbody = card.querySelector('tbody.feeders');
            (post.feeders || []).forEach(function (feeder, f) {
                var tr = addFeeder(idx, tbody);
                set(tr.querySelector('[name="posts[' + idx + '][feeders][' + f + '][feeder_cadre_id]"]'), feeder.feeder_cadre_id, feeder.feeder_name);
                tr.querySelector('[name="posts[' + idx + '][feeders][' + f + '][promotion_percentage]"]').value = feeder.promotion_percentage == null ? 100 : feeder.promotion_percentage;
                tr.querySelector('[name="posts[' + idx + '][feeders][' + f + '][min_experience_years]"]').value = feeder.min_experience_years == null ? 5 : feeder.min_experience_years;
                set(tr.querySelector('[name="posts[' + idx + '][feeders][' + f + '][qualification_id]"]'), feeder.qualification_id, feeder.min_qualification);
            });
            enhance(card);
        });
    }
})();
