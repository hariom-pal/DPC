/**
 * Raise DPC form — narrow the Promotion From Post list to the chosen order.
 *
 * A Pre-DPC may sanction promotion from several posts, and a DPC runs one of them.
 * Every source of every order the department may raise from is rendered into a
 * single `<select>`, tagged with `data-pre-dpc`; this hides the ones that belong to
 * other orders as the order changes.
 *
 * **All of it is a convenience.** The list is filtered here, but what may actually
 * be raised is settled server-side twice over: `SaveDpcProposalRequest` rejects a
 * feeder the chosen order does not name, and `DpcProposalService::sourceFor()`
 * refuses the same thing for callers that never touch a form. Unhiding an option in
 * a debugger buys nothing.
 *
 * **An order naming exactly one feeder still selects it here**, rather than being
 * left blank for the server to infer. The server does infer it, but a form that
 * shows the single sanctioned feeder is telling the truth about what will be
 * raised, and one that shows "Choose an order first" after an order was chosen is
 * not.
 *
 * No inline handler and no inline script — the enforced CSP forbids both.
 */
(function () {
    'use strict';

    var order = document.getElementById('pre_dpc_proposal_id');
    var feeder = document.getElementById('promotion_from_post_id');

    if (!order || !feeder) { return; }

    var placeholder = feeder.querySelector('option[value=""]');
    var options = Array.prototype.slice.call(feeder.querySelectorAll('option[data-pre-dpc]'));

    function sync() {
        var chosen = order.value;
        var visible = [];

        options.forEach(function (option) {
            var belongs = chosen !== '' && option.dataset.preDpc === chosen;

            /* Hidden *and* disabled: `hidden` alone still leaves the option
               submittable by keyboard in some browsers, and an option that cannot be
               seen must not be selectable either. */
            option.hidden = !belongs;
            option.disabled = !belongs;

            if (belongs) { visible.push(option); }
        });

        if (placeholder) {
            placeholder.hidden = visible.length === 1;
        }

        /* If the current selection belongs to another order it is no longer valid,
           so it is dropped rather than silently carried over. */
        if (feeder.selectedOptions[0] && feeder.selectedOptions[0].disabled) {
            feeder.value = '';
        }

        if (visible.length === 1) {
            feeder.value = visible[0].value;
        }

        /* An order that sanctions one feeder leaves nothing to choose. Disabled
           rather than hidden, so the reader can still see which post it is.

           A disabled field is not submitted, so nothing arrives for it — which is
           exactly the case `promotion_from_post_id` is `nullable` for, and what
           `sourceFor()` resolves by taking the order's only source. */
        feeder.disabled = visible.length <= 1;
    }

    order.addEventListener('change', sync);

    sync();
})();
