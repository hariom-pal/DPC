// The AO / AH picker on the allotment screens (2026-10-28, reworked 2026-10-29).
// As the e-mail is typed, the department's own officers and released officers are
// suggested. A chosen officer fills the details form read-only, except the mobile,
// which changes only after an OTP to the new number, and the post (2026-10-30). An e-mail nobody has opens
// the same form empty, to add the officer after the e-mail and mobile OTPs.
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
        var list = get('picker-suggestions');
        var details = get('picker-details');
        var fields = { name: get('picker-name'), mobile: get('picker-mobile'), gender: get('picker-gender'), dob: get('picker-dob'), designation: get('picker-designation') };
        var data = picker.dataset;
        var chosen = null;
        var timer = null;
        var seq = 0;

        // The officer's public identifier (uuid), never the numeric id.
        function url(template, officerKey) {
            return template.replace('__ID__', encodeURIComponent(officerKey));
        }

        function hideList() {
            list.classList.add('d-none');
            list.textContent = '';
            email.setAttribute('aria-expanded', 'false');
        }

        // The details form: empty and editable for a new officer, filled and locked
        // (mobile aside) for a chosen one.
        function showDetails(officer) {
            var existing = officer !== null;
            details.classList.remove('d-none');
            get('picker-lead-new').classList.toggle('d-none', existing);
            get('picker-lead-existing').classList.toggle('d-none', !existing);
            get('picker-new-actions').classList.toggle('d-none', existing);
            get('picker-mobile-actions').classList.add('d-none');

            fields.name.value = existing ? officer.name || '' : '';
            fields.mobile.value = existing ? officer.mobile || '' : '';
            fields.gender.value = existing ? officer.gender || '' : '';
            fields.dob.value = existing ? officer.date_of_birth || '' : '';
            fields.designation.value = existing && officer.designation_id ? String(officer.designation_id) : '';
            get('picker-designation-save').classList.add('d-none');
            fields.name.readOnly = existing;
            fields.dob.readOnly = existing;
            fields.gender.disabled = existing;
        }

        function choose(officer) {
            chosen = officer;
            id.value = officer.id;
            result.textContent = '';
            var badge = document.createElement('span');
            badge.className = 'badge text-bg-success me-1';
            badge.textContent = data.msgSelected;
            result.appendChild(badge);
            if (officer.released) {
                var released = document.createElement('span');
                released.className = 'badge text-bg-warning me-1';
                released.textContent = data.msgReleased + (officer.released_from ? ' — ' + officer.released_from : '');
                result.appendChild(released);
            }
            result.appendChild(document.createTextNode(
                officer.name + (officer.designation ? ' — ' + officer.designation : '') + (officer.department ? ' (' + officer.department + ')' : '')
            ));
            email.value = officer.email || email.value;
            hideList();
            showDetails(officer);
        }

        function clearChoice() {
            chosen = null;
            id.value = '';
            result.textContent = '';
            details.classList.add('d-none');
        }

        function suggest() {
            var term = email.value.trim();
            // From the first character typed (2026-10-30).
            if (term.length < 1) {
                hideList();
                return;
            }

            var mine = ++seq;
            request(data.searchUrl + '?q=' + encodeURIComponent(term), 'GET').then(function (answer) {
                if (mine !== seq) {
                    return;
                }
                list.textContent = '';
                var officers = (answer.ok && answer.data.officers) || [];
                if (!officers.length) {
                    var none = document.createElement('div');
                    none.className = 'list-group-item small text-muted';
                    none.textContent = data.msgNoMatch;
                    list.appendChild(none);
                }
                officers.forEach(function (officer) {
                    var item = document.createElement('button');
                    item.type = 'button';
                    item.className = 'list-group-item list-group-item-action small';
                    item.setAttribute('role', 'option');
                    var strong = document.createElement('strong');
                    strong.textContent = officer.email;
                    item.appendChild(strong);
                    item.appendChild(document.createTextNode(' — ' + officer.name));
                    if (officer.released) {
                        var badge = document.createElement('span');
                        badge.className = 'badge text-bg-warning ms-1';
                        badge.textContent = data.msgReleased + (officer.released_from ? ' — ' + officer.released_from : '');
                        item.appendChild(badge);
                    }
                    // mousedown, so the choice lands before the e-mail loses focus.
                    item.addEventListener('mousedown', function (event) {
                        event.preventDefault();
                        text(message, '');
                        choose(officer);
                    });
                    list.appendChild(item);
                });
                list.classList.remove('d-none');
                email.setAttribute('aria-expanded', 'true');
            }).catch(function () { hideList(); });
        }

        // Resolves true when an officer is chosen for this e-mail.
        function find() {
            hideList();
            text(message, '');
            var value = email.value.trim();
            if (!value) {
                clearChoice();
                return Promise.resolve(false);
            }
            if (chosen && chosen.email && chosen.email.toLowerCase() === value.toLowerCase()) {
                return Promise.resolve(true);
            }
            clearChoice();

            return request(data.lookupUrl + '?email=' + encodeURIComponent(value), 'GET').then(function (answer) {
                if (answer.ok && answer.data.found) {
                    choose(answer.data);
                    return true;
                }
                if (answer.data.blocked || !answer.ok) {
                    text(message, answer.data.message || data.msgError, 'danger');
                } else {
                    showDetails(null);
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
            if (!details.classList.contains('d-none') && chosen === null) {
                text(message, data.msgAddFirst, 'danger');
            }
        };

        get('picker-find').addEventListener('click', find);
        email.addEventListener('input', function () {
            // A changed e-mail no longer names the officer chosen before.
            if (chosen) {
                clearChoice();
            }
            window.clearTimeout(timer);
            timer = window.setTimeout(suggest, 250);
        });
        email.addEventListener('blur', function () { window.setTimeout(hideList, 150); });
        email.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                find();
            } else if (event.key === 'Escape') {
                hideList();
            }
        });

        // A chosen officer's mobile: changing it asks for an OTP to the new number.
        fields.mobile.addEventListener('input', function () {
            if (chosen) {
                get('picker-mobile-actions').classList.toggle('d-none', fields.mobile.value.trim() === (chosen.mobile || ''));
            }
        });

        // A chosen officer's post (MoM of 2026-10-29): saved as chosen, no OTP.
        fields.designation.addEventListener('change', function () {
            if (chosen) {
                get('picker-designation-save').classList.toggle('d-none', fields.designation.value === String(chosen.designation_id || ''));
            }
        });

        get('picker-designation-save').addEventListener('click', function () {
            request(url(data.designationUrl, chosen.key), 'PUT', { designation_id: fields.designation.value }).then(function (answer) {
                if (!answer.ok) {
                    text(message, firstError(answer.data, data.msgError), 'danger');
                    return;
                }
                choose(answer.data);
                text(message, answer.data.message, 'success');
            }).catch(function () { text(message, data.msgError, 'danger'); });
        });

        get('picker-mobile-send').addEventListener('click', function () {
            request(url(data.mobileOtpUrl, chosen.key), 'POST', { mobile: fields.mobile.value.trim() }).then(function (answer) {
                text(message, answer.ok ? answer.data.message : firstError(answer.data, data.msgError), answer.ok ? 'success' : 'danger');
            }).catch(function () { text(message, data.msgError, 'danger'); });
        });

        get('picker-mobile-save').addEventListener('click', function () {
            var body = { mobile: fields.mobile.value.trim(), mobile_code: get('picker-new-mobile-code').value.trim() };
            request(url(data.mobileUrl, chosen.key), 'PUT', body).then(function (answer) {
                if (!answer.ok) {
                    text(message, firstError(answer.data, data.msgError), 'danger');
                    return;
                }
                choose(answer.data);
                text(message, answer.data.message, 'success');
            }).catch(function () { text(message, data.msgError, 'danger'); });
        });

        function contact() {
            return { email: email.value.trim(), mobile: fields.mobile.value.trim() };
        }

        get('picker-send-otp').addEventListener('click', function () {
            request(data.otpUrl, 'POST', contact()).then(function (answer) {
                text(message, answer.ok ? answer.data.message : firstError(answer.data, data.msgError), answer.ok ? 'success' : 'danger');
            }).catch(function () { text(message, data.msgError, 'danger'); });
        });

        get('picker-add').addEventListener('click', function () {
            var body = contact();
            body.name = fields.name.value.trim();
            body.gender = fields.gender.value;
            body.date_of_birth = fields.dob.value;
            body.designation_id = fields.designation.value;
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
