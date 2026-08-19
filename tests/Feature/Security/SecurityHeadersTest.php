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
        // Report-only by default: see the class docblock and TD-29. Enforcing the
        // policy today would silently break the inline handlers this application
        // still has, in a way no server-side test can see.
        $this->assertNull($response->headers->get('Content-Security-Policy'));
        $this->assertNotNull($response->headers->get('Content-Security-Policy-Report-Only'));
    }

    /**
     * The policy is enforced by configuration, not by default.
     *
     * This is the flag to turn on once TD-29's inline handlers are gone. Pinned so
     * that the switch is known to work before anybody relies on it in production.
     */
    public function test_the_policy_can_be_enforced_by_configuration(): void
    {
        Config::set('dpc.security.headers.enforce_csp', true);

        $response = $this->get(route('login'))->assertOk();

        $this->assertNotNull($response->headers->get('Content-Security-Policy'));
        $this->assertNull($response->headers->get('Content-Security-Policy-Report-Only'));
    }

    /**
     * A report-only policy with nowhere to report to protects nothing, so the
     * endpoint is configurable — and absent by default rather than invented.
     */
    public function test_a_report_endpoint_is_added_only_when_configured(): void
    {
        $this->assertStringNotContainsString(
            'report-uri',
            (string) $this->get(route('login'))->headers->get('Content-Security-Policy-Report-Only'),
        );

        Config::set('dpc.security.headers.csp_report_uri', 'https://csp.example.gov.in/report');

        $this->assertStringContainsString(
            'report-uri https://csp.example.gov.in/report',
            (string) $this->get(route('login'))->headers->get('Content-Security-Policy-Report-Only'),
        );
    }

    /**
     * The one screen written after the policy existed is CSP-clean, and stays so.
     *
     * The rest of the application is not (TD-29). New code is not going to add to
     * that, and this is what says so.
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
        $csp = (string) $this->get(route('login'))->headers->get('Content-Security-Policy-Report-Only');

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
            ->assertHeaderMissing('Content-Security-Policy-Report-Only')
            ->assertHeaderMissing('X-Content-Type-Options');
    }

    public function test_the_policy_can_be_replaced_or_omitted(): void
    {
        Config::set('dpc.security.headers.content_security_policy', "default-src 'none'");

        $this->get(route('login'))
            ->assertHeader('Content-Security-Policy-Report-Only', "default-src 'none'");

        Config::set('dpc.security.headers.content_security_policy', '');

        $this->get(route('login'))->assertHeaderMissing('Content-Security-Policy-Report-Only');
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
