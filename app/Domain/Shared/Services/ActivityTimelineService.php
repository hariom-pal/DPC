<?php

declare(strict_types=1);

namespace App\Domain\Shared\Services;

use App\Domain\Shared\Models\ActivityTimeline;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Records workflow events onto the Activity Timeline. Separate from the
 * owen-it audit trail: audits answer "what field changed", the timeline
 * answers "what happened, in order, and who did it".
 */
class ActivityTimelineService
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function record(string $event, ?Model $subject = null, ?string $description = null, array $properties = [], ?Model $causer = null): ActivityTimeline
    {
        $causer ??= Auth::user();

        return ActivityTimeline::query()->create([
            // Stamped on every row so a future export can read records written
            // under an older shape without guessing.
            'schema_version' => ActivityTimeline::SCHEMA_VERSION,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'event' => $event,
            'description' => $description,
            'causer_type' => $causer?->getMorphClass(),
            'causer_id' => $causer?->getKey(),
            // Snapshot, not a reference (architect directive). A user who is
            // later renamed or removed must not change or erase what the
            // record says happened, and a replay reading the timeline alone
            // needs a stable identity that is not a sequential id.
            'actor_uuid' => is_string($causer?->getAttribute('uuid')) ? $causer->getAttribute('uuid') : null,
            'actor_name' => is_string($causer?->getAttribute('name')) ? $causer->getAttribute('name') : null,
            'properties' => $properties === [] ? null : $properties,
            'occurred_at' => now(),
        ]);
    }
}
