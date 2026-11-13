/*
 * One social category per committee member (business clarification, 2026-09-05).
 *
 * > *A Committee Member may belong to ONLY ONE reserved social category … SC + ST
 * > is not allowed. SC + OBC is not allowed. ST + OBC is not allowed. If none is
 * > selected, the member is automatically treated as UR (General). There is NO UR
 * > checkbox.*
 *
 * **Checkboxes that behave as a choice, rather than radios.** Radios cannot be
 * unticked, so a "UR" radio would have to exist for an officer to undo a mistaken
 * SC — and the clarification says there is no UR control. Three checkboxes of which
 * ticking one clears the others gives both: exactly one at a time, and all three
 * clearable back to UR.
 *
 * **This guarantees nothing, and is not where the rule lives.**
 * `CommitteeComplianceService::socialCategoryFindings()` refuses a payload claiming
 * two whatever the browser did, and the replacement form's request repeats it. This
 * file exists so the officer sees the rule as they work instead of meeting it as an
 * error after they submit.
 *
 * **Delegated from `document`**, because the composition form builds its member
 * cards from a `<template>` as the officer presses "Add Member". Binding to the
 * boxes that exist at load would leave every card added afterwards unguarded, and
 * that is the common case — the first card is added by script too.
 *
 * Serves both screens that carry the boxes: the composition form
 * (`dpc-proposals/committee`), where cards repeat, and the replacement form
 * (`dpc-proposals/committee-replace`), where there is exactly one fieldset.
 */
(function () {
    'use strict';

    /* The badge that says "UR (General)". Shown while none of the three is ticked
       and hidden otherwise, so the officer can see what leaving them blank means
       rather than having to know. */
    function refreshBadge(fieldset) {
        var badge = fieldset.querySelector('.member-social-none');

        if (!badge) { return; }

        var anyChecked = Array.prototype.some.call(
            fieldset.querySelectorAll('.member-social-input'),
            function (box) { return box.checked; }
        );

        badge.classList.toggle('d-none', anyChecked);
    }

    /* One member per category (2026-11-05): a category one member of the committee
       already holds is not offered on the others — the box is disabled there, and
       freed again when that member is unticked or removed. The member who holds it
       keeps the box. Only the composition form repeats cards; the replacement
       form has none and is left alone. */
    function column(box) {
        var match = /\[(is_[a-z_]+)\]$/.exec(box.name || '');
        return match ? match[1] : null;
    }

    function lockCovered() {
        var cards = document.querySelectorAll('.member-card');
        var holders = {};

        Array.prototype.forEach.call(cards, function (card) {
            card.querySelectorAll('.member-attribute-input').forEach(function (box) {
                var key = column(box);
                if (key && box.checked) { (holders[key] = holders[key] || []).push(card); }
            });
        });

        Array.prototype.forEach.call(cards, function (card) {
            // The member's own social category, if one is ticked (MoM of 12 Nov 2026).
            var ownSocial = card.querySelector('.member-social-input:checked');

            card.querySelectorAll('.member-attribute-input').forEach(function (box) {
                var key = column(box);
                var taken = !box.checked && (holders[key] || []).some(function (holder) { return holder !== card; });
                var check = box.closest('.form-check');

                // SC, ST or OBC ticked: the other two are not offered to this member.
                var otherSocial = !box.checked && ownSocial !== null && box.classList.contains('member-social-input');
                var off = taken || otherSocial;

                if (box.disabled !== off) { box.disabled = off; }
                if (check) {
                    check.classList.toggle('opacity-50', off);
                    if (taken) {
                        check.title = box.getAttribute('data-covered-title') || '';
                    } else if (otherSocial) {
                        check.title = box.getAttribute('data-one-social-title') || '';
                    } else {
                        check.removeAttribute('title');
                    }
                }
            });
        });
    }

    /* SC, ST and OBC: with one ticked the other two are disabled, in every fieldset —
       the replacement form too, which has no member cards (MoM of 12 November 2026). */
    function disableOtherSocial(fieldset) {
        var boxes = fieldset.querySelectorAll('.member-social-input');
        var ticked = Array.prototype.filter.call(boxes, function (box) { return box.checked; });

        boxes.forEach(function (box) {
            if (box.closest('.member-card')) { return; } // the cards are handled by lockCovered()
            var off = ticked.length > 0 && !box.checked;
            box.disabled = off;
            var check = box.closest('.form-check');
            if (check) {
                check.classList.toggle('opacity-50', off);
                if (off) { check.title = box.getAttribute('data-one-social-title') || ''; } else { check.removeAttribute('title'); }
            }
        });
    }

    function refreshAll() {
        document.querySelectorAll('.member-social').forEach(function (fieldset) {
            refreshBadge(fieldset);
            disableOtherSocial(fieldset);
        });
        lockCovered();
    }

    document.addEventListener('change', function (e) {
        var box = e.target;

        if (!box.classList || !box.classList.contains('member-social-input')) { return; }

        var fieldset = box.closest('.member-social');

        if (!fieldset) { return; }

        /* Ticking one disables the other two (MoM of 12 November 2026); unticking it
           offers all three again — that is the officer returning the member to UR,
           a legitimate state and the default one. A pair already ticked (an old
           record) is cleared to the one just ticked. */
        if (box.checked) {
            fieldset.querySelectorAll('.member-social-input').forEach(function (other) {
                if (other !== box) { other.checked = false; }
            });
        }

        refreshBadge(fieldset);
        disableOtherSocial(fieldset);
    });

    document.addEventListener('change', function (e) {
        if (e.target.classList && e.target.classList.contains('member-attribute-input')) { lockCovered(); }
    });

    /* Cards are added after load, and are also filled from the saved committee on
       load. Neither fires `change`, so the badge is refreshed from the DOM itself.
       An observer rather than a hook into the card builder keeps the two files
       independent — this one knows about a CSS class and nothing else. */
    if (typeof MutationObserver === 'function') {
        new MutationObserver(refreshAll).observe(document.documentElement, {
            childList: true,
            subtree: true
        });
    }

    refreshAll();
})();
