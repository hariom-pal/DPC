<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use Illuminate\Foundation\Events\Dispatchable;

class OtpRequested
{
    use Dispatchable;

    public function __construct(
        public readonly string $identifier,
        public readonly OtpChannel $channel,
        public readonly OtpPurpose $purpose,
        public readonly string $code,
    ) {}
}
