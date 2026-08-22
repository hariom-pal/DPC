<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response security headers — M11 hardening.
 *
 * **Permitted on every frozen milestone without a per-change approval**: AD-15
 * admits security fixes, VAPT findings and production hardening to M4, M5, M6 and
 * M7, and this touches no milestone's code at all — it is a response filter, so
 * every screen including the frozen ones gets it without being edited.
 *
 * **Why a Content-Security-Policy is worth having here specifically.** This
 * application vendors Bootstrap and its own two scripts and loads **nothing from a
 * CDN** — a deliberate M0 decision. That makes a strict policy achievable rather
 * than aspirational: `script-src 'self'` costs nothing today, and its value is
 * that it stops costing nothing the moment somebody pastes in a third-party
 * widget. A policy written after the first external script is a policy that has to
 * be loosened to fit it.
 *
 * **`'unsafe-inline'` is permitted for styles and not for scripts, and that
 * asymmetry is the point.** Several Blade views set element widths inline — the
 * report bars, for one — so `style-src` must admit inline styles or the pages
 * render wrong. An inline-*script* allowance is what turns a reflected string into
 * executable code, so it is not granted.
 *
 * **The policy is enforced by default since 2026-08-22, and how it got there is
 * the part worth reading.** For most of the project's life it shipped as
 * `Content-Security-Policy-Report-Only`, because the application had **6 views
 * with inline `<script>` blocks and 21 inline event handlers**
 * (`onsubmit="return confirm(...)"`, `onchange="this.form.submit()"`), several on
 * M4's frozen screens. Under an *enforced* `script-src 'self'` a browser silently
 * refuses all of them: the add-a-row buttons on the committee, seniority,
 * working-officers, evaluation-chart, Pre-DPC and recruitment-rule forms stop
 * working, and every confirmation before a destructive action stops appearing —
 * the form just submits.
 *
 * **None of that is visible to the test suite, because CSP is enforced by the
 * browser and not by the server.** A screen returns 200 either way. Enforcing the
 * policy on the strength of a green suite would have broken core data entry in a
 * way every test agreed was fine, which is the worst shape a change can have —
 * so that is not how the switch was thrown.
 *
 * Instead (**TD-29**, closed): every inline handler moved into `public/js/`, seed
 * data moved onto `data-` attributes, and the confirmations and submit-on-change
 * selects became two delegated listeners in `portal.js`. The result was then
 * driven in **headless Chromium with the policy enforced** — twelve checks across
 * the six repeating forms, the confirmation dialogues, the Pre-DPC rehydration
 * path and the accessibility control — with **zero violations and zero console
 * errors**. `ViewsAreCspCleanTest` fails the build if an inline handler returns.
 *
 * The alternative — `'unsafe-inline'` — would have made the header decorative
 * while looking strict, and is still not offered. Reverting to report-only is one
 * environment variable (`DPC_CSP_ENFORCE=false`), because a header that breaks a
 * page in production and cannot be turned off without a deploy is worse than an
 * absent one.
 *
 * **Every header is configurable and can be switched off**, because a header that
 * breaks a page in production and cannot be disabled without a deploy is worse
 * than an absent one.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (! (bool) config('dpc.security.headers.enabled', true)) {
            return $response;
        }

        foreach ($this->headers($request) as $header => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            // Never overwritten: a controller that set one deliberately — a
            // document download relaxing the frame policy, say — knows something
            // this middleware does not.
            if (! $response->headers->has($header)) {
                $response->headers->set($header, $value);
            }
        }

        return $response;
    }

    /**
     * @return array<string, string|null>
     */
    private function headers(Request $request): array
    {
        return [
            // Enforced unless switched back to report-only — see the class
            // docblock for how the inline-handler inventory was cleared first.
            $this->policyHeaderName() => $this->contentSecurityPolicy(),

            // Clickjacking. `SAMEORIGIN` rather than `DENY`: the print view and the
            // stored documents are legitimately opened in a frame by some PDF
            // viewers, and those are same-origin.
            'X-Frame-Options' => (string) config('dpc.security.headers.frame_options', 'SAMEORIGIN'),

            // Stops a browser guessing that an uploaded .txt is really HTML —
            // relevant here because signed documents and import files are served
            // back to users.
            'X-Content-Type-Options' => 'nosniff',

            // A DPC URL contains a proposal id; a promotion list is not something
            // to leak to a third-party site through a referrer.
            'Referrer-Policy' => (string) config('dpc.security.headers.referrer_policy', 'same-origin'),

            // This application asks for none of these.
            'Permissions-Policy' => (string) config(
                'dpc.security.headers.permissions_policy',
                'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            ),

            // HSTS only over TLS. Sending it over plain HTTP is meaningless, and
            // sending it from a development machine would pin `localhost` to HTTPS
            // in the developer's browser — which is a genuinely annoying thing to
            // do to somebody.
            'Strict-Transport-Security' => $request->isSecure()
                ? (string) config('dpc.security.headers.hsts', 'max-age=31536000; includeSubDomains')
                : null,
        ];
    }

    /**
     * `Content-Security-Policy` when enforcing, `-Report-Only` otherwise.
     */
    private function policyHeaderName(): string
    {
        return (bool) config('dpc.security.headers.enforce_csp', true)
            ? 'Content-Security-Policy'
            : 'Content-Security-Policy-Report-Only';
    }

    private function contentSecurityPolicy(): ?string
    {
        $configured = config('dpc.security.headers.content_security_policy');

        if ($configured === '' || $configured === false) {
            return null;
        }

        if (is_string($configured)) {
            return $configured;
        }

        return implode('; ', [
            "default-src 'self'",
            // See the class docblock: inline styles are needed, inline scripts are
            // not, and the asymmetry is deliberate.
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self' data:",
            // No CDN, no analytics, no external API from the browser. The whole
            // application is same-origin by design.
            "connect-src 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            ...($this->reportUri() === null ? [] : ['report-uri '.$this->reportUri()]),
        ]);
    }

    /**
     * Where a browser should post violations, if anywhere.
     *
     * Unset by default: a report-only policy with nowhere to report to still
     * protects nothing, but it also breaks nothing, and inventing an endpoint that
     * silently discards reports would be worse than having none.
     */
    private function reportUri(): ?string
    {
        $uri = config('dpc.security.headers.csp_report_uri');

        return is_string($uri) && $uri !== '' ? $uri : null;
    }
}
