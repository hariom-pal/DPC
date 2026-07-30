<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_endpoint_reports_all_dependencies_ok(): void
    {
        $this->getJson('/health')
            ->assertOk()
            ->assertJson(['status' => 'ok'])
            ->assertJsonPath('checks.database', 'ok')
            ->assertJsonPath('checks.redis', 'ok')
            ->assertJsonPath('checks.cache', 'ok');
    }

    public function test_home_page_renders(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(__('messages.welcome_title'));
    }

    public function test_language_can_be_switched_to_hindi(): void
    {
        $this->get('/lang/hi');

        $this->assertSame('hi', session('locale'));
    }

    public function test_unsupported_language_is_ignored(): void
    {
        $this->get('/lang/fr');

        $this->assertNull(session('locale'));
    }
}
