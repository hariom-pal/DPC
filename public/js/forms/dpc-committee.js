/*
 * DPC Committee form — repeating member cards (M4, screen S13).
 *
 * **Why this is a file and not a `<script>` block in the view.** It was an
 * inline block until 2026-08-22, which is what stopped the Content-Security-
 * Policy being enforced rather than merely reported (TD-29). The logic below is
 * unchanged; the only difference is where the seed data comes from. It used to
 * be interpolated with `@json($membersData)` straight into the script; it now
 * arrives on `#members[data-seed]`, which a browser reads as data and never as
 * code. A `data-` attribute is exempt from `script-src` by construction, so
 * there is nothing here for the policy to refuse.
 *
 * The view is frozen M4. This file changes no behaviour on it — a card is
 * added, removed, and prefilled exactly as before.
 */
(function () {
    'use strict';

    var membersEl = document.getElementById('members');
    var templateEl = document.getElementById('member-template');

    // Rendered on one screen only, so leave every other page alone.
    if (!membersEl || !templateEl) { return; }

    var tpl = templateEl.innerHTML;
    var m = 0;

    function toggleExternal(card) {
        var select = card.querySelector('.user-select');
        card.querySelector('.external-fields').style.display = select.value ? 'none' : 'flex';
    }

    function addMember() {
        var idx = m++;
        var wrap = document.createElement('div');
        wrap.innerHTML = tpl.replaceAll('__M__', idx);
        var card = wrap.firstElementChild;
        membersEl.appendChild(card);
        toggleExternal(card);
        return card;
    }

    membersEl.addEventListener('click', function (e) {
        if (e.target.classList.contains('remove-member')) e.target.closest('.member-card').remove();
    });
    membersEl.addEventListener('change', function (e) {
        if (e.target.classList.contains('user-select')) toggleExternal(e.target.closest('.member-card'));
    });
    document.getElementById('add-member').addEventListener('click', function () { addMember(); });

    var seed = JSON.parse(membersEl.dataset.seed || '[]');
    if (seed.length === 0) {
        var card = addMember();
        card.querySelector('[name$="[capacity]"]').value = 'chairman';
    } else {
        seed.forEach(function (member) {
            var card = addMember();
            if (member.user_id) card.querySelector('.user-select').value = member.user_id;
            card.querySelector('[name$="[capacity]"]').value = member.capacity || 'member';
            card.querySelector('[name$="[name]"]').value = member.name || '';
            card.querySelector('[name$="[designation]"]').value = member.designation || '';
            card.querySelector('[name$="[mobile]"]').value = member.mobile || '';
            card.querySelector('[name$="[email]"]').value = member.email || '';
            toggleExternal(card);
        });
    }
})();
