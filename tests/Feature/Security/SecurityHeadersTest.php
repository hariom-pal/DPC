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
        $this->assertNotNull($response->headers->get('Content-Security-Policy'));
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
            ->assertHeaderMissing('X-Content-Type-Options');
    }

    public function test_the_policy_can_be_replaced_or_omitted(): void
    {
        Config::set('dpc.security.headers.content_security_policy', "default-src 'none'");

        $this->get(route('login'))->assertHeader('Content-Security-Policy', "default-src 'none'");

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
