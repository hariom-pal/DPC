<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OtpVerification extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'identifier',
        'channel',
        'purpose',
        'code_hash',
        'attempts',
        'expires_at',
        'consumed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
