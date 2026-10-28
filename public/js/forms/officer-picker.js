// The AO / AH picker on the allotment screens (2026-10-28). The e-mail finds an
// existing officer; when there is none, the officer is added on the spot after
// the OTPs sent to their e-mail and mobile are entered.
(function () {
    'use strict';

    var token = document.querySelector('meta[name="csrf-token"]');
    var csrf = token ? token.getAttribute('content') : '';

    function request(url, method, body) {
        var options = {
            method: method,
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        };
        if (body) {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(body);
        }

        return fetch(url, options).then(function (response) {
            return response.json().then(function (data) {
                return { ok: response.ok, data: data };
            });
        });
    }

    function firstError(data, fallback) {
        if (data && data.errors) {
            var keys = Object.keys(data.errors);
            if (keys.length) {
                return data.errors[keys[0]][0];
            }
        }

        return (data && data.message) || fallback;
    }

    function text(element, value, kind) {
        element.textContent = value || '';
        element.className = element.className.replace(/\btext-(danger|success)\b/g, '').trim();
        if (kind) {
            element.classList.add('text-' + kind);
        }
    }

    document.querySelectorAll('.officer-picker').forEach(function (picker) {
        var get = function (name) { return picker.querySelector('.' + name); };
        var email = get('picker-email');
        var id = get('picker-id');
        var result = get('picker-result');
        var message = get('picker-message');
        var panel = get('picker-new');
        var data = picker.dataset;

        function choose(officer) {
            id.value = officer.id;
            result.textContent = '';
            var badge = document.createElement('span');
            badge.className = 'badge text-bg-success me-1';
            badge.textContent = data.msgSelected;
            result.appendChild(badge);
            result.appendChild(document.createTextNode(
                officer.name + (officer.designation ? ' — ' + officer.designation : '') + (officer.department ? ' (' + officer.department + ')' : '')
            ));
            panel.classList.add('d-none');
        }

        // Resolves true when an officer is chosen for this e-mail.
        function find() {
            id.value = '';
            result.textContent = '';
            text(message, '');
            var value = email.value.trim();
            if (!value) {
                return Promise.resolve(false);
            }

            return request(data.lookupUrl + '?email=' + encodeURIComponent(value), 'GET').then(function (answer) {
                if (answer.ok && answer.data.found) {
                    choose(answer.data);
                    return true;
                }
                if (answer.data.blocked || !answer.ok) {
                    panel.classList.add('d-none');
                    text(message, answer.data.message || data.msgError, 'danger');
                } else {
                    panel.classList.remove('d-none');
                    text(message, data.msgNotFound);
                }
                return false;
            }).catch(function () {
                text(message, data.msgError, 'danger');
                return false;
            });
        }

        picker.officerFind = find;
        picker.officerPending = function () {
            return email.value.trim() !== '' && id.value === '';
        };
        picker.officerNotFound = function () {
            // Only for an e-mail nobody has; a refused account keeps its own message.
            if (!panel.classList.contains('d-none')) {
                text(message, data.msgAddFirst, 'danger');
            }
        };

        function contact() {
            return { email: email.value.trim(), mobile: get('picker-mobile').value.trim() };
        }

        get('picker-find').addEventListener('click', find);
        email.addEventListener('change', find);
        // A changed e-mail no longer names the officer chosen before.
        email.addEventListener('input', function () {
            id.value = '';
            result.textContent = '';
        });
        email.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                find();
            }
        });

        get('picker-send-otp').addEventListener('click', function () {
            request(data.otpUrl, 'POST', contact()).then(function (answer) {
                text(message, answer.ok ? answer.data.message : firstError(answer.data, data.msgError), answer.ok ? 'success' : 'danger');
            }).catch(function () { text(message, data.msgError, 'danger'); });
        });

        get('picker-add').addEventListener('click', function () {
            var body = contact();
            body.name = get('picker-name').value.trim();
            body.gender = get('picker-gender').value;
            body.date_of_birth = get('picker-dob').value;
            body.department_id = get('picker-department').value;
            body.role = data.role;
            body.email_code = get('picker-email-code').value.trim();
            body.mobile_code = get('picker-mobile-code').value.trim();

            request(data.storeUrl, 'POST', body).then(function (answer) {
                if (!answer.ok) {
                    text(message, firstError(answer.data, data.msgError), 'danger');
                    return;
                }
                choose(answer.data);
                text(message, answer.data.message + ' ' + data.msgPassword + ' ' + answer.data.password, 'success');
            }).catch(function () { text(message, data.msgError, 'danger'); });
        });
    });

    // Saving without pressing Find (2026-10-28): every picker whose e-mail has not
    // been looked up yet is looked up first; the form goes only when each names an
    // officer, otherwise the picker says what is missing.
    document.querySelectorAll('form').forEach(function (form) {
        var pickers = Array.prototype.slice.call(form.querySelectorAll('.officer-picker'));
        if (!pickers.length) {
            return;
        }

        form.addEventListener('submit', function (event) {
            var pending = pickers.filter(function (picker) { return picker.officerPending(); });
            if (!pending.length) {
                return;
            }

            event.preventDefault();
            Promise.all(pending.map(function (picker) { return picker.officerFind(); })).then(function (found) {
                if (found.every(Boolean)) {
                    form.submit();
                    return;
                }
                pending.forEach(function (picker, index) {
                    if (!found[index]) {
                        picker.officerNotFound();
                    }
                });
            });
        });
    });
})();
