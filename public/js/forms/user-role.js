/**
 * Provision / Edit Account — keep Department in step with Role (change items 1, 2).
 *
 * A departmental role (Authorized Officer, Administrative Head, Promotion
 * Candidate) is an officer *of a department*; a Commission role holds none. The
 * server states both halves of that rule — `required_if` and
 * `prohibited_unless` in the Form Request — and this only reflects it in the
 * form, so the reason is visible before the submit rather than after it.
 *
 * The list of departmental roles is read from `data-departmental-roles` rather
 * than repeated here: the Role enum is the source of truth, the Blade template
 * prints it, and this file has no opinion about which roles those are. Adding a
 * fourth departmental role therefore changes one enum method and nothing else.
 *
 * No inline handler and no inline script — the enforced CSP forbids both, and
 * `ViewsAreCspCleanTest` fails the build if either comes back.
 */
(function () {
    'use strict';

    var role = document.getElementById('role');
    var department = document.getElementById('department_id');
    var hint = document.getElementById('department_hint');

    if (!role || !department) { return; }

    var departmental = (department.dataset.departmentalRoles || '')
        .split(',')
        .filter(function (value) { return value !== ''; });

    function sync() {
        var isDepartmental = departmental.indexOf(role.value) !== -1;

        department.disabled = !isDepartmental;
        department.required = isDepartmental;

        /* Clearing on the way out matters: a Commission role with a department
           still selected is refused by the server, and leaving the stale value
           visible makes that refusal look arbitrary. */
        if (!isDepartmental) { department.value = ''; }

        if (hint) {
            hint.textContent = isDepartmental
                ? 'Required — this role exists within a department.'
                : 'A Commission role holds no department.';
        }
    }

    role.addEventListener('change', sync);
    sync();
})();
