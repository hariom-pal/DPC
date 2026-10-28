<?php

declare(strict_types=1);

namespace App\Domain\Signature\Models;

use App\Domain\Authentication\Models\User;
use App\Enums\SignatureStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property SignatureStatus $status
 * @property string|null $transaction_reference
 * @property string|null $certificate_subject
 * @property string|null $certificate_serial
 * @property string|null $certificate_issuer
 * @property string|null $signed_disk
 * @property string|null $signed_path
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $signed_at
 */
class Signature extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'signable_type',
        'signable_id',
        'provider',
        'status',
        'transaction_reference',
        'certificate_subject',
        'certificate_serial',
        'certificate_issuer',
        'signer_name',
        'request_reference',
        'signed_disk',
        'signed_path',
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
            'status' => SignatureStatus::class,
            'meta' => 'array',
            'signed_at' => 'datetime',
        ];
    }
}
