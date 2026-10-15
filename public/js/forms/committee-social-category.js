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

    function refreshAll() {
        document.querySelectorAll('.member-social').forEach(refreshBadge);
    }

    document.addEventListener('change', function (e) {
        var box = e.target;

        if (!box.classList || !box.classList.contains('member-social-input')) { return; }

        var fieldset = box.closest('.member-social');

        if (!fieldset) { return; }

        /* Ticking one clears the other two. Unticking clears nothing — that is the
           officer returning the member to UR, which is a legitimate state and the
           default one. */
        if (box.checked) {
            fieldset.querySelectorAll('.member-social-input').forEach(function (other) {
                if (other !== box) { other.checked = false; }
            });
        }

        refreshBadge(fieldset);
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
