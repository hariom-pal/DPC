<?php

declare(strict_types=1);

namespace App\Domain\Shared\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * The legal workflow history (architect ruling): business tables must never be
 * required to reconstruct what happened.
 *
 * Every row carries a `schema_version` — starting at 1 — because a future PDF,
 * CSV or audit export must be able to read a record written years earlier
 * without guessing which shape its `properties` are in. The version-1 shape is
 * documented in `docs/archive/2026-08-19-pre-final/WORKFLOWS.md`.
 *
 * @property int $id
 * @property string $uuid
 * @property int $schema_version
 * @property string|null $actor_uuid
 * @property string|null $actor_name
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string $event
 * @property string|null $description
 * @property string|null $causer_type
 * @property int|null $causer_id
 * @property array<string, mixed>|null $properties
 * @property Carbon $occurred_at
 */
class ActivityTimeline extends Model
{
    use HasUuids;

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * The shape `properties` are written in today. Bump when the meaning of an
     * existing key changes, never for an added key — an exporter reading
     * version 1 must keep working.
     */
    public const SCHEMA_VERSION = 1;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'schema_version',
        'subject_type',
        'subject_id',
        'event',
        'description',
        'causer_type',
        'causer_id',
        'actor_uuid',
        'actor_name',
        'properties',
        'occurred_at',
    ];

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function causer(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
