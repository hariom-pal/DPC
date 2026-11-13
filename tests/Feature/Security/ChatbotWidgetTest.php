<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * The help-desk chatbot widget: absent unless configured, and when configured the
 * CSP admits its one origin — for scripts, fetches and its logo — and nothing more.
 */
class ChatbotWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_widget_is_absent_and_the_policy_unchanged_by_default(): void
    {
        Config::set('dpc.chatbot.url', null);
        Config::set('dpc.chatbot.site_id', null);

        $response = $this->get(route('login'))->assertOk();

        $response->assertDontSee('widget.js', false);
        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'self';", $csp);
        $this->assertStringContainsString("connect-src 'self';", $csp);
    }

    public function test_a_configured_widget_is_rendered_and_its_origin_alone_is_allowed(): void
    {
        Config::set('dpc.chatbot.url', 'https://Chat.Example.gov.in/some/path/');
        Config::set('dpc.chatbot.site_id', 'dpc-site_1');

        $response = $this->get(route('login'))->assertOk();

        $response->assertSee('<script src="https://chat.example.gov.in/widget.js"', false);
        $response->assertSee('data-site-id="dpc-site_1"', false);
        $response->assertSee('data-language="en"', false);

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'self' https://chat.example.gov.in;", $csp);
        $this->assertStringContainsString("connect-src 'self' https://chat.example.gov.in;", $csp);
        $this->assertStringContainsString("img-src 'self' data: https://chat.example.gov.in;", $csp);
        $this->assertStringContainsString("default-src 'self';", $csp);
    }

    public function test_the_widget_speaks_hindi_when_the_portal_does(): void
    {
        Config::set('dpc.chatbot.url', 'http://127.0.0.1:8000');
        Config::set('dpc.chatbot.site_id', 'dpc');

        $this->get(route('lang.switch', 'hi'));

        $this->get(route('login'))->assertOk()
            ->assertSee('src="http://127.0.0.1:8000/widget.js"', false)
            ->assertSee('data-language="hi"', false);
    }

    /**
     * A half or malformed setting leaves the widget out instead of rendering a tag
     * the browser would refuse, and never widens the policy with junk.
     */
    public function test_an_incomplete_or_malformed_setting_leaves_the_widget_out(): void
    {
        foreach ([
            ['https://chat.example.gov.in', ''],
            ['', 'dpc'],
            ['javascript:alert(1)', 'dpc'],
            ['https://chat.example.gov.in', 'bad id"><script>'],
            ['chat.example.gov.in', 'dpc'],
        ] as [$url, $site]) {
            Config::set('dpc.chatbot.url', $url);
            Config::set('dpc.chatbot.site_id', $site);

            $response = $this->get(route('login'))->assertOk();

            $response->assertDontSee('widget.js', false);
            $this->assertStringContainsString("script-src 'self';", (string) $response->headers->get('Content-Security-Policy'), $url.' / '.$site);
        }
    }
}
