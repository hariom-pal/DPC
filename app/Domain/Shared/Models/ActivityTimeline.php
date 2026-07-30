<?php

declare(strict_types=1);

namespace App\Domain\Shared\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
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
    /**
     * @var list<string>
     */
    protected $fillable = [
        'subject_type',
        'subject_id',
        'event',
        'description',
        'causer_type',
        'causer_id',
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
