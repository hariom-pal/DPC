<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Response security headers — M11 hardening.
 *
 * **Applies to every screen, including the frozen milestones', without editing
 * any of them** — it is a response filter, which is why it can be added while
 * M4–M8 are frozen. AD-15 admits security fixes and production hardening in any
 * case; this touches no milestone's code at all.
 *
 * The assertion worth reading twice is
 * {@see test_inline_scripts_are_not_permitted_but_inline_styles_are}: the
 * asymmetry is deliberate and a future "the CSP is breaking my widget" fix would
 * remove it.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_headers_are_present_on_a_public_page(): void
    {
        $response = $this->get(route('login'))->assertOk();

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'same-origin');

        $this->assertStringContainsString('camera=()', (string) $response->headers->get('Permissions-Policy'));

        // **Enforced, not report-only, since TD-29 closed on 2026-08-22.** The
        // views carry no inline script for the policy to refuse — see
        // ViewsAreCspCleanTest, which is what keeps that true — and the six
        // repeating forms were driven in headless Chromium under the enforced
        // policy before this default changed.
        $this->assertNotNull($response->headers->get('Content-Security-Policy'));
        $this->assertNull($response->headers->get('Content-Security-Policy-Report-Only'));
    }

    /**
     * Enforcement can be stood down without a deploy.
     *
     * Kept pinned in the other direction now: a header that breaks a page in
     * production and cannot be turned off is worse than an absent one, so the
     * retreat to report-only has to be known to work — not discovered under
     * pressure.
     */
    public function test_enforcement_can_be_stood_down_to_report_only(): void
    {
        Config::set('dpc.security.headers.enforce_csp', false);

        $response = $this->get(route('login'))->assertOk();

        $this->assertNull($response->headers->get('Content-Security-Policy'));
        $this->assertNotNull($response->headers->get('Content-Security-Policy-Report-Only'));
    }

    /**
     * A report-only policy with nowhere to report to protects nothing, so the
     * endpoint is configurable — and absent by default rather than invented.
     */
    public function test_a_report_endpoint_is_added_only_when_configured(): void
    {
        $this->assertStringNotContainsString(
            'report-uri',
            (string) $this->get(route('login'))->headers->get('Content-Security-Policy'),
        );

        Config::set('dpc.security.headers.csp_report_uri', 'https://csp.example.gov.in/report');

        // Worth having on an *enforced* policy too, not only a reporting one: a
        // violation now means a control has actually stopped working for a user,
        // which is the case you most want to hear about.
        $this->assertStringContainsString(
            'report-uri https://csp.example.gov.in/report',
            (string) $this->get(route('login'))->headers->get('Content-Security-Policy'),
        );
    }

    /**
     * The first screen written after the policy existed, and the pattern the rest
     * of the application was brought round to.
     *
     * It was the only CSP-clean view for a while and this test was what said so.
     * Since TD-29 closed, **every** view is clean and
     * {@see ViewsAreCspCleanTest} enforces that across the
     * whole of `resources/views`. This case is kept because it names the pattern —
     * behaviour in a file, nothing inline — on the screen it started on.
     */
    public function test_the_self_inspection_print_view_uses_no_inline_script(): void
    {
        $view = (string) file_get_contents(resource_path('views/self-inspection/print.blade.php'));

        $this->assertStringNotContainsString('onclick=', $view);
        $this->assertStringNotContainsString('<script>', $view);
        $this->assertStringContainsString('self-inspection-print.js', $view);
        $this->assertFileExists(public_path('js/self-inspection-print.js'));
    }

    /**
     * Inline styles are needed — several views set element widths inline, the
     * report bars among them — and inline scripts are not. An inline-script
     * allowance is what turns a reflected string into executable code.
     */
    public function test_inline_scripts_are_not_permitted_but_inline_styles_are(): void
    {
        $csp = (string) $this->get(route('login'))->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline'", $csp);

        // And nothing may be loaded from anywhere else: this application vendors
        // Bootstrap and loads no CDN, which is what makes the strict policy
        // affordable rather than aspirational.
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
    }

    /**
     * HSTS over plain HTTP is meaningless, and sending it from a development
     * machine would pin `localhost` to HTTPS in the developer's browser.
     */
    public function test_hsts_is_sent_only_over_tls(): void
    {
        $this->get(route('login'))->assertHeaderMissing('Strict-Transport-Security');

        $this->get('https://localhost/login')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    /**
     * A header that breaks a page in production and cannot be turned off without a
     * deploy is worse than an absent one.
     */
    public function test_the_whole_set_can_be_switched_off(): void
    {
        Config::set('dpc.security.headers.enabled', false);

        $this->get(route('login'))
            ->assertOk()
            ->assertHeaderMissing('Content-Security-Policy')
            ->assertHeaderMissing('Content-Security-Policy-Report-Only')
            ->assertHeaderMissing('X-Content-Type-Options');
    }

    public function test_the_policy_can_be_replaced_or_omitted(): void
    {
        Config::set('dpc.security.headers.content_security_policy', "default-src 'none'");

        $this->get(route('login'))
            ->assertHeader('Content-Security-Policy', "default-src 'none'");

        Config::set('dpc.security.headers.content_security_policy', '');

        $this->get(route('login'))->assertHeaderMissing('Content-Security-Policy');
    }

    /**
     * A controller that set a header deliberately knows something this middleware
     * does not, so the middleware never overwrites one.
     */
    public function test_a_header_set_by_the_application_is_not_overwritten(): void
    {
        Config::set('dpc.security.headers.frame_options', 'DENY');

        Route::get('/__test-frame-header', fn () => response('ok')
            ->header('X-Frame-Options', 'SAMEORIGIN'))->middleware('web');

        $this->get('/__test-frame-header')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }
}
