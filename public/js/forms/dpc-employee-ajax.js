/**
 * Employee details saved without leaving the place on the page (2026-11-14:
 * "save or edit should work on AJAX so that it does not move to top or bottom").
 *
 * A form marked `data-ajax-section` is posted with fetch. The server answers exactly
 * as it does for an ordinary submission — a redirect back to this page, with the
 * message or the validation errors in the session — and fetch follows it, so the
 * page that comes back is the one a reload would show. Its content replaces this
 * page's, the scroll position is kept, and "Save & Next" then moves to the next
 * section. An Edit link marked `data-ajax-link` opens its form the same way.
 *
 * Nothing on the server knows about this: without script, or if the request fails,
 * the form is submitted the ordinary way. External file with no inline script,
 * because the CSP forbids one (TD-29).
 */
(function () {
    'use strict';

    var CONTAINER = '#employee-details';

    function container(doc) {
        return doc.querySelector(CONTAINER);
    }

    /** Puts the page that came back in place of this one, keeping the place on the page. */
    function swap(html, url, after) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var fresh = container(doc);
        var current = container(document);

        if (!fresh || !current) {
            return false;
        }

        var y = window.scrollY;
        current.innerHTML = fresh.innerHTML;

        try {
            var clean = new URL(url, window.location.href);
            clean.hash = '';
            window.history.replaceState(null, '', clean.toString());
        } catch (e) { /* the address bar keeps the old address */ }

        document.dispatchEvent(new CustomEvent('edpc:content', { detail: { root: current } }));
        window.scrollTo(0, y);

        if (after) { after(current); }

        return true;
    }

    function scrollTo(id) {
        var target = id ? document.getElementById(id) : null;

        if (target) {
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    /** Whether the section the form was in came back with an error. */
    function hasErrors(root, sectionId) {
        var section = sectionId ? root.querySelector('#' + sectionId) : null;

        return !!(section && section.querySelector('.is-invalid, .alert-danger'));
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;

        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-ajax-section') || !window.fetch || !window.FormData) {
            return;
        }

        // A confirmation refused (portal.js) already stopped this submission.
        if (event.defaultPrevented) {
            return;
        }

        event.preventDefault();

        var data = new FormData(form);
        var submitter = event.submitter;

        if (submitter && submitter.name) {
            data.append(submitter.name, submitter.value);
        }

        var section = form.closest('[id^="section-"]');
        var sectionId = section ? section.id : null;
        var next = submitter && submitter.name === '_next' ? 'section-' + submitter.value : null;

        // An ordinary submission — not an XHR — so the server redirects as it always does.
        window.fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin', redirect: 'follow' })
            .then(function (response) {
                if (!response.ok) { throw new Error(String(response.status)); }

                return response.text().then(function (html) { return { html: html, url: response.url }; });
            })
            .then(function (page) {
                var swapped = swap(page.html, page.url, function (root) {
                    if (hasErrors(root, sectionId)) {
                        scrollTo(sectionId);
                    } else if (next) {
                        scrollTo(next);
                    }
                });

                if (!swapped) { form.submit(); }
            })
            .catch(function () {
                // Whatever went wrong, the ordinary submission still works.
                form.submit();
            });
    });

    document.addEventListener('click', function (event) {
        var link = event.target.closest ? event.target.closest('a[data-ajax-link]') : null;

        if (!link || !window.fetch || event.ctrlKey || event.metaKey || event.shiftKey) {
            return;
        }

        event.preventDefault();

        var target = link.hash ? link.hash.slice(1) : null;

        window.fetch(link.href, { credentials: 'same-origin' })
            .then(function (response) {
                if (!response.ok) { throw new Error(String(response.status)); }

                return response.text().then(function (html) { return { html: html, url: response.url }; });
            })
            .then(function (page) {
                if (!swap(page.html, page.url, function () { scrollTo(target); })) {
                    window.location.href = link.href;
                }
            })
            .catch(function () { window.location.href = link.href; });
    });

    // "Next" with no form to save: a smooth move to the next section.
    document.addEventListener('click', function (event) {
        var link = event.target.closest ? event.target.closest('a[data-next-section]') : null;

        if (!link) { return; }

        event.preventDefault();
        scrollTo(link.hash.slice(1));
    });
})();
