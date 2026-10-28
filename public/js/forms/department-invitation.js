// Department Admin invitation (2026-10-28): the e-mail is the department's own,
// so choosing the department fills it in from the Department Master.
(function () {
    'use strict';

    var department = document.getElementById('department_id');
    var email = document.getElementById('email');

    if (!department || !email) {
        return;
    }

    department.addEventListener('change', function () {
        var option = department.options[department.selectedIndex];
        email.value = option && option.dataset.email ? option.dataset.email : '';
    });
})();
