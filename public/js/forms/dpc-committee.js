/*
 * DPC Committee form — repeating member cards (PPT S15).
 *
 * **Why this is a file and not a `<script>` block in the view.** It was an inline
 * block until 2026-08-22, which is what stopped the Content-Security-Policy being
 * enforced rather than merely reported (TD-29). Everything variable arrives on
 * `#members` as `data-` attributes, which a browser reads as data and never as
 * code, so there is nothing here for the policy to refuse.
 *
 * **Rewritten 2026-08-26 for change items 6 to 9.** There is no Commission Members
 * master to pick from any more. Each card offers the register's two paths:
 *
 *   1. **Search Existing User** — by e-mail or mobile, and selecting a result fills
 *      the card and records the account's id.
 *   2. **Create New Member** — type the person's details, and as the e-mail or
 *      mobile is entered the same lookup runs. If it finds somebody, the card says
 *      *"This user already exists"* and offers **Use Existing User** rather than
 *      letting a duplicate account be created.
 *
 * **Reworked 2026-09-06, because the screen was confusing.** The business put it
 * plainly: every card looked the same, so it read as though one member were being
 * added over and over, and there was no way to see whether the required combination
 * of attributes had been reached until Save refused it. Four things changed:
 *
 *   - **One open card at a time.** A completed card *locks* into a numbered one-line
 *      summary with an Edit button; its search box goes with it, because searching
 *      for somebody you have already seated is meaningless. "+ Add Member" is
 *      disabled while a card is open.
 *   - **Numbering.** Member 1, Member 2 … renumbered on every lock and removal.
 *   - **A live requirements checklist**, ticking as the committee comes to cover the
 *      reserved category, Female, Bhar Sadhak Sachiv and Vibhag Sachiv.
 *   - **Field validation as the officer types** — a ten-digit mobile, a `gov.in`
 *      address, and a duplicate check against the cards already on this form.
 *
 * **Nothing here is authoritative.** Every rule above is enforced again by
 * `SaveDpcCommitteeRequest` and `CommitteeComplianceService` on the server, which
 * are the checks that decide. This file makes the officer's life easier and
 * guarantees nothing: a form submitted from a stale page, or with JavaScript off,
 * is judged identically.
 *
 * **The CGPSC Chairman's seat is not here at all** (item 9). It is placed
 * server-side on every save and rendered read-only above the form, so there is no
 * card for the officer to edit and nothing to submit that could remove it.
 */
(function () {
    'use strict';

    var membersEl = document.getElementById('members');
    var templateEl = document.getElementById('member-template');

    // Rendered on one screen only, so leave every other page alone.
    if (!membersEl || !templateEl) { return; }

    var tpl = templateEl.innerHTML;
    var searchUrl = membersEl.dataset.searchUrl;
    var addButton = document.getElementById('add-member');
    var addHint = document.getElementById('add-member-hint');
    var requirementsEl = document.getElementById('committee-requirements');
    var allMetEl = document.getElementById('requirements-all-met');

    var d = membersEl.dataset;
    var text = {
        exists: d.textExists || 'This user already exists.',
        useExisting: d.textUseExisting || 'Use Existing User',
        noMatch: d.textNoMatch || 'No existing user matches that.',
        member: d.textMember || 'Member :n',
        incomplete: d.textIncomplete || 'Fill in this member before adding another.',
        unreserved: d.textUnreserved || 'UR (General)',
        duplicateInForm: d.textDuplicateInForm || 'This person is already on this committee as :member.',
        nameRequired: d.textNameRequired || 'Enter the member’s name.',
        designationRequired: d.textDesignationRequired || 'Choose the member’s designation.',
        mobileRequired: d.textMobileRequired || 'Enter a ten-digit mobile number.',
        mobileShape: d.textMobileShape || 'A mobile number is ten digits and begins with 6, 7, 8 or 9.',
        emailDomain: d.textEmailDomain || 'Use a government address ending in gov.in.'
    };

    var m = 0;

    // Mirrors the two server-side rules in `SaveDpcCommitteeRequest`. Kept as
    // literals rather than shipped from PHP: a regex crossing a data attribute is a
    // regex in two dialects, and the server's is the one that decides anyway.
    var MOBILE = /^[6-9]\d{9}$/;
    var GOV_EMAIL = /@(?:[A-Za-z0-9-]+\.)*gov\.in$/i;

    /* One in-flight lookup per card. A slow answer for "94" must never overwrite
       the answer for the full number the officer has since finished typing, which
       is what an unguarded debounce does. */
    function lookup(card, term, onResult) {
        if (!searchUrl || term.trim().length < 3) { onResult([]); return; }

        var token = (card._lookupToken || 0) + 1;
        card._lookupToken = token;

        fetch(searchUrl + '?q=' + encodeURIComponent(term), {
            headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            credentials: 'same-origin'
        })
            .then(function (r) { return r.ok ? r.json() : {members: []}; })
            .then(function (data) {
                if (card._lookupToken !== token) { return; }
                onResult(data.members || []);
            })
            .catch(function () {
                if (card._lookupToken !== token) { return; }
                onResult([]);
            });
    }

    function debounce(fn, wait) {
        var t;

        return function () {
            var args = arguments;
            var self = this;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(self, args); }, wait);
        };
    }

    function cards() {
        return Array.prototype.slice.call(membersEl.querySelectorAll('.member-card'));
    }

    function isLocked(card) {
        return card.dataset.locked === '1';
    }

    /* The last ten digits are what identify an Indian mobile number; what precedes
       them is a country code the officer may or may not type. Mirrors
       CommitteeMemberDirectory::normaliseMobile(). */
    function digits(value) {
        var only = String(value || '').replace(/\D+/g, '');

        return only.length < 10 ? '' : only.slice(-10);
    }

    function value(card, selector) {
        var el = card.querySelector(selector);

        return el ? String(el.value || '').trim() : '';
    }

    // ---------------------------------------------------------------- validation

    /*
     * Mark a field, and *show* the message.
     *
     * **`d-block` is what makes this work, and its absence is why "Save this member"
     * looked broken** (reported 2026-09-06: *"save button not working"*). Bootstrap
     * reveals an `.invalid-feedback` only as the sibling of an `.is-invalid`
     * control — and `searchable-select.js` moves the designation `<select>` into a
     * wrapper of its own, so the message was no longer its sibling and stayed
     * hidden. The button ran, the card refused to lock, and nothing on screen said
     * why. Shown explicitly here, the rule holds however the field is wrapped.
     *
     * The searchable enhancement's own visible input is marked too, since the
     * select it stands for is clipped out of view.
     */
    function setError(card, selector, message) {
        var field = card.querySelector(selector);
        var slot = card.querySelector(selector + '-error');

        if (!field) { return; }

        field.classList.toggle('is-invalid', Boolean(message));

        var proxy = field.parentNode && field.parentNode.querySelector
            ? field.parentNode.querySelector('.ss-input')
            : null;

        if (proxy) { proxy.classList.toggle('is-invalid', Boolean(message)); }

        if (slot) {
            slot.textContent = message || '';
            slot.classList.toggle('d-block', Boolean(message));
        }
    }

    function clearErrors(card) {
        ['.member-name', '.member-designation', '.member-mobile', '.member-email']
            .forEach(function (selector) { setError(card, selector, ''); });
    }

    /*
     * Whoever else on this form is already this person.
     *
     * Checked on mobile and e-mail, the two handles the register asks the portal to
     * check, and normalised the same way the server normalises them — so
     * `+91 94250-12345` and `9425012345` are one number here as well as there.
     * A name is deliberately not compared: two officers may share one.
     */
    function duplicateOf(card) {
        var mobile = digits(value(card, '.member-mobile'));
        var email = value(card, '.member-email').toLowerCase();
        var userId = value(card, '.member-user-id');

        return cards().filter(function (other) { return other !== card; }).find(function (other) {
            if (userId !== '' && value(other, '.member-user-id') === userId) { return true; }
            if (mobile !== '' && digits(value(other, '.member-mobile')) === mobile) { return true; }

            return email !== '' && value(other, '.member-email').toLowerCase() === email;
        }) || null;
    }

    /*
     * Is this card complete enough to lock?
     *
     * Name, designation and a valid mobile — the three a committee minute needs to
     * identify who sat. E-mail is optional, and validated only if given.
     *
     * **Deliberately stricter than the server.** `SaveDpcCommitteeRequest` leaves
     * designation and mobile nullable, because a member seated from an existing
     * account inherits whatever that account holds and an account may hold neither.
     * This gate governs the browser's own "the row is finished" step, and nothing
     * here can refuse a committee the server would accept — Save is not blocked by
     * it.
     */
    function validate(card) {
        clearErrors(card);

        var ok = true;
        var mobile = value(card, '.member-mobile');
        var email = value(card, '.member-email');

        if (value(card, '.member-name') === '') {
            setError(card, '.member-name', text.nameRequired);
            ok = false;
        }

        if (value(card, '.member-designation') === '') {
            setError(card, '.member-designation', text.designationRequired);
            ok = false;
        }

        if (mobile === '') {
            setError(card, '.member-mobile', text.mobileRequired);
            ok = false;
        } else if (!MOBILE.test(mobile)) {
            setError(card, '.member-mobile', text.mobileShape);
            ok = false;
        }

        if (email !== '' && !GOV_EMAIL.test(email)) {
            setError(card, '.member-email', text.emailDomain);
            ok = false;
        }

        var twin = duplicateOf(card);

        if (twin) {
            var where = twin.querySelector('.member-index').textContent
                || twin.querySelector('.member-summary-name').textContent;
            setError(card, '.member-mobile', text.duplicateInForm.replace(':member', where));
            ok = false;
        }

        return ok;
    }

    // -------------------------------------------------------------- lock / unlock

    function attributeBadges(card) {
        var labels = [];

        card.querySelectorAll('input[type="checkbox"]:checked').forEach(function (box) {
            var label = card.querySelector('label[for="' + box.id + '"]');

            if (label) { labels.push(label.textContent.trim()); }
        });

        return labels;
    }

    function paintSummary(card) {
        var designation = card.querySelector('.member-designation');
        var chosen = designation.options[designation.selectedIndex];
        var mobile = value(card, '.member-mobile');
        var email = value(card, '.member-email');
        var contact = [mobile, email].filter(Boolean).join(' · ');

        card.querySelector('.member-summary-name').textContent = value(card, '.member-name');
        card.querySelector('.member-summary-designation').textContent =
            chosen && chosen.value !== '' ? ' — ' + chosen.text.trim() : '';
        card.querySelector('.member-summary-contact').textContent = contact ? ' · ' + contact : '';

        /* Built with DOM calls rather than innerHTML: these labels are translated
           strings and a member's own name, and this is the one place they would be
           rendered unescaped by mistake. */
        var badges = card.querySelector('.member-summary-badges');
        badges.replaceChildren();

        var labels = attributeBadges(card);

        if (labels.length === 0) { labels = [text.unreserved]; }

        labels.forEach(function (label) {
            var badge = document.createElement('span');
            badge.className = 'badge text-bg-light ms-1';
            badge.textContent = label;
            badges.appendChild(badge);
        });
    }

    function lock(card) {
        card.dataset.locked = '1';
        card.querySelector('.member-body').classList.add('d-none');
        card.querySelector('.member-summary').classList.remove('d-none');
        paintSummary(card);
        card.querySelector('.member-search-results').replaceChildren();
        card.querySelector('.member-search').value = '';
    }

    function unlock(card) {
        card.dataset.locked = '';
        card.querySelector('.member-summary').classList.add('d-none');
        card.querySelector('.member-body').classList.remove('d-none');
    }

    function openCard() {
        return cards().find(function (card) { return !isLocked(card); }) || null;
    }

    // ------------------------------------------------------- numbering and state

    /*
     * Number the cards, and — more importantly — renumber what they post.
     *
     * **This is what made a removed member take the whole form with it** (reported
     * 2026-09-06: *"I added two, saved, and because validation was not satisfied the
     * ones I had added were gone as well"*).
     *
     * Cards were named from a counter that only ever went up, so removing the second
     * of three left `members[0]` and `members[2]`. PHP receives that as the array
     * `[0 => …, 2 => …]`, `json_encode` writes a non-sequential array as a JSON
     * **object**, and the seed this file reads back on the next render was therefore
     * `{"0":…,"2":…}` — which has no `forEach`. The script threw on the first line of
     * `render()` and not one card was drawn. The members were never lost; the form
     * that should have shown them died before it could.
     *
     * Renaming on every change keeps the posted array a list, and has a second
     * benefit worth having: a server-side error on `members.1.mobile` now points at
     * the card the officer sees as Member 2.
     */
    function renumber() {
        cards().forEach(function (card, i) {
            var label = text.member.replace(':n', String(i + 1));

            card.querySelectorAll('.member-index').forEach(function (el) {
                el.textContent = label;
            });

            card.querySelectorAll('[name^="members["]').forEach(function (field) {
                field.name = field.name.replace(/^members\[\d+\]/, 'members[' + i + ']');
            });
        });
    }

    /*
     * The requirements checklist, recomputed from the cards on the form.
     *
     * **This is the browser's copy of `CommitteeComplianceService`, and it asks the
     * same question that class asks: is each requirement covered by the committee
     * as a whole?** Never by a member individually — the register's own example is
     * three members satisfying five requirements, and a panel that counted
     * attributes instead of covering them would tell the officer they were short
     * when they were not.
     *
     * The Chairman's seat is not on this form, and is therefore not counted here.
     * That is the conservative direction: a Chairman who does represent something
     * only ever makes the committee *more* compliant, so this panel can understate
     * coverage but never overstate it.
     */
    function paintRequirements() {
        if (!requirementsEl) { return; }

        var min = parseInt(requirementsEl.dataset.min, 10) || 0;
        var max = parseInt(requirementsEl.dataset.max, 10) || 0;
        var met = requirementsEl.dataset.textMet || 'Covered';
        var pending = requirementsEl.dataset.textPending || 'Still needed';
        var all = true;

        requirementsEl.querySelectorAll('.requirement').forEach(function (row) {
            var satisfied;

            if (row.dataset.requirement === 'size') {
                var count = cards().length;
                satisfied = count >= min && count <= max;
            } else {
                // One row, several columns: "reserved" lists all three social
                // categories and is satisfied by any one of them, which is exactly
                // the difference between it and the each-of rows below it.
                var columns = (row.dataset.columns || '').split(',').filter(Boolean);

                satisfied = columns.some(function (column) {
                    return cards().some(function (card) {
                        var box = card.querySelector('[name$="[' + column + ']"]');

                        return Boolean(box && box.checked);
                    });
                });
            }

            all = all && satisfied;

            var badge = row.querySelector('.requirement-state');
            badge.textContent = satisfied ? met : pending;
            badge.className = 'requirement-state badge ' + (satisfied ? 'text-bg-success' : 'text-bg-warning');
        });

        if (allMetEl) {
            allMetEl.textContent = requirementsEl.dataset.textAllMet || '';
            allMetEl.classList.toggle('d-none', !all);
        }
    }

    /* "+ Add Member" is disabled while a card is open. A blank row stacked on a
       blank row is how this screen came to look like one member being entered over
       and over. */
    function paintAddButton() {
        var open = openCard() !== null;

        addButton.disabled = open;

        if (addHint) { addHint.textContent = open ? text.incomplete : ''; }
    }

    function refresh() {
        renumber();
        paintRequirements();
        paintAddButton();
    }

    // ------------------------------------------------------------- search, fill

    function hideDuplicate(card) {
        var box = card.querySelector('.member-duplicate');
        box.classList.add('d-none');
        box.replaceChildren();
    }

    /* Item 6: warn, and offer the existing account instead of a second one.
       Built with DOM calls rather than innerHTML because `member.name` is somebody
       else's data and this is the one place it is rendered unescaped by mistake. */
    function showDuplicate(card, member) {
        var box = card.querySelector('.member-duplicate');
        box.replaceChildren();

        var message = document.createElement('span');
        message.textContent = text.exists + ' ';
        box.appendChild(message);

        var who = document.createElement('strong');
        who.textContent = member.name + (member.email ? ' (' + member.email + ')' : '');
        box.appendChild(who);

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-sm btn-outline-primary ms-2';
        button.textContent = text.useExisting;
        button.addEventListener('click', function () { useExisting(card, member); });
        box.appendChild(button);

        box.classList.remove('d-none');
    }

    /* Adopt an account into this card. The id is what the server reads; the
       visible fields are filled in so the officer can see who they picked. */
    function useExisting(card, member) {
        card.querySelector('.member-user-id').value = member.id;
        card.querySelector('.member-name').value = member.name || '';
        setDesignation(card, member.designation_id);
        card.querySelector('.member-mobile').value = member.mobile || '';
        card.querySelector('.member-email').value = member.email || '';
        card.querySelector('.member-search-results').replaceChildren();
        card.querySelector('.member-search').value = '';
        clearErrors(card);
        hideDuplicate(card);
    }

    function renderResults(card, members) {
        var list = card.querySelector('.member-search-results');
        list.replaceChildren();

        if (members.length === 0) {
            var none = document.createElement('span');
            none.className = 'text-body-secondary';
            none.textContent = text.noMatch;
            list.appendChild(none);

            return;
        }

        members.forEach(function (member) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-sm btn-outline-secondary me-1 mb-1';
            button.textContent = member.name + (member.mobile ? ' · ' + member.mobile : '');
            button.addEventListener('click', function () { useExisting(card, member); });
            list.appendChild(button);
        });
    }

    function addMember() {
        var idx = m++;
        var wrap = document.createElement('div');
        wrap.innerHTML = tpl.replaceAll('__M__', idx);
        var card = wrap.firstElementChild;
        membersEl.appendChild(card);

        card.querySelector('.member-search').addEventListener('input', debounce(function (e) {
            lookup(card, e.target.value, function (members) { renderResults(card, members); });
        }, 300));

        // Digits only, as they are typed. `maxlength` caps the length; this stops a
        // pasted "+91 94250 12345" arriving as anything but ten digits.
        card.querySelector('.member-mobile').addEventListener('input', function (e) {
            var only = e.target.value.replace(/\D+/g, '').slice(-10);

            if (only !== e.target.value) { e.target.value = only; }
        });

        /* The duplicate check runs on the two fields the register names, and on
           those only: warning on a name would tell the officer that "Shri R. Sahu"
           already exists because a different one does, and they would seat the
           wrong person. */
        ['.member-email', '.member-mobile'].forEach(function (selector) {
            card.querySelector(selector).addEventListener('input', debounce(function (e) {
                if (card.querySelector('.member-user-id').value) { return; }

                lookup(card, e.target.value, function (members) {
                    var exact = members.filter(function (member) {
                        var typed = e.target.value.trim().toLowerCase();

                        return (member.email || '').toLowerCase() === typed
                            || digits(member.mobile) === digits(typed);
                    });

                    if (exact.length > 0) { showDuplicate(card, exact[0]); } else { hideDuplicate(card); }
                });
            }, 400));
        });

        /* The checklist follows the attributes live, so the officer can see a gap
           close as they tick the box that closes it.

           Deferred by a tick because `committee-social-category.js` listens for the
           same event at `document`, and therefore *after* this one — it is what
           clears the other two social boxes, and repainting before it ran would
           read the state the officer is leaving rather than the one they chose. */
        card.addEventListener('change', function (e) {
            if (e.target.type === 'checkbox') { window.setTimeout(paintRequirements, 0); }
        });

        return card;
    }

    /* Designation is a master dropdown, not a text box (DPC-MODULE-FIX-001,
       second pass), so this sets an option rather than a string — and tells
       searchable-select.js to redraw its filter box, which caches the chosen
       option's text and would otherwise keep showing the previous member's rank.

       An id the master no longer offers leaves the field blank rather than
       inventing an option: better an obviously empty field than a committee row
       that silently records a designation nobody picked. */
    function setDesignation(card, id) {
        var select = card.querySelector('.member-designation');

        select.value = id ? String(id) : '';
        select.dispatchEvent(new CustomEvent('ss:refresh'));
    }

    function fill(card, member) {
        card.querySelector('.member-user-id').value = member.user_id || '';
        card.querySelector('.member-name').value = member.name || '';
        setDesignation(card, member.designation_id);
        card.querySelector('.member-mobile').value = member.mobile || '';
        card.querySelector('.member-email').value = member.email || '';

        card.querySelectorAll('input[type="checkbox"]').forEach(function (box) {
            var column = (box.name.match(/\[(is_[a-z_]+)\]$/) || [])[1];
            box.checked = Boolean(column && member[column]);
        });
    }

    function render(rows) {
        membersEl.replaceChildren();
        m = 0;

        if (rows.length === 0) {
            addMember();
            refresh();

            return;
        }

        /* A member already on the record opens locked. They were entered and saved
           once already; showing six open forms on load is the state the business
           called confusing, and it is also how an officer reopening the screen to
           add one member ends up re-editing five they did not mean to touch.

           **Locked because it has a name, not because it passes today's card
           check.** The check `member-done` applies is stricter than the server's on
           purpose (see `validate()`), and a committee seated before that gate
           existed may hold members with no mobile on record. Opening all six of
           them, and disabling "+ Add Member" until each is completed, would punish
           the officer for a rule that did not exist when the committee was saved.
           A nameless row is a genuinely unfinished one and opens. */
        rows.forEach(function (member) {
            var card = addMember();
            fill(card, member);

            if (String(member.name || '').trim() !== '') { lock(card); }
        });

        refresh();
    }

    membersEl.addEventListener('click', function (e) {
        var card = e.target.closest('.member-card');

        if (!card) { return; }

        if (e.target.classList.contains('remove-member')) {
            card.remove();

            // Never leave the officer with nothing to type into.
            if (cards().length === 0) { addMember(); }

            refresh();

            return;
        }

        if (e.target.classList.contains('member-edit')) {
            unlock(card);
            refresh();

            return;
        }

        if (e.target.classList.contains('member-done')) {
            if (!validate(card)) {
                /* Put the officer on the field that refused. Without this the only
                   feedback on a long card is a red outline somewhere above the fold,
                   which is indistinguishable from a button that did nothing. */
                var firstBad = card.querySelector('.is-invalid');

                if (firstBad) {
                    firstBad.scrollIntoView({ block: 'center', behavior: 'smooth' });

                    if (typeof firstBad.focus === 'function') { firstBad.focus({ preventScroll: true }); }
                }

                return;
            }

            lock(card);
            refresh();
        }
    });

    addButton.addEventListener('click', function () {
        if (openCard() !== null) { return; }

        addMember();
        refresh();
    });

    render(JSON.parse(membersEl.dataset.seed || '[]'));
})();
