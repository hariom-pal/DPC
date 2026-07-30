<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SignEvent extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'signable_type',
        'signable_id',
        'channel',
        'otp_identifier',
        'ip_address',
        'user_agent',
        'meta',
        'signed_at',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The record whose signing this event attests to (proposal, order, …).
     *
     * @return MorphTo<Model, $this>
     */
    public function signable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'signed_at' => 'datetime',
        ];
    }
}
