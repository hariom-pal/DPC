<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Authentication\Models\User;
use App\Domain\Shared\Models\ActivityTimeline;
use App\Domain\Shared\Services\ActivityTimelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityTimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_records_a_timeline_entry_with_subject_and_causer(): void
    {
        $causer = User::factory()->create();
        $subject = User::factory()->create();

        app(ActivityTimelineService::class)->record(
            event: 'master.created',
            subject: $subject,
            description: 'Created a record',
            properties: ['field' => 'value'],
            causer: $causer,
        );

        $entry = ActivityTimeline::query()->firstOrFail();
        $this->assertSame('master.created', $entry->event);
        $this->assertSame($subject->getKey(), $entry->subject_id);
        $this->assertSame($causer->getKey(), $entry->causer_id);
        $this->assertSame(['field' => 'value'], $entry->properties);
    }

    public function test_defaults_causer_to_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);
        app(ActivityTimelineService::class)->record(event: 'did.something');

        $this->assertSame($user->getKey(), ActivityTimeline::query()->firstOrFail()->causer_id);
    }
}
