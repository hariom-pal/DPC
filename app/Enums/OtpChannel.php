<?php

declare(strict_types=1);

namespace App\Enums;

enum OtpChannel: string
{
    case Email = 'email';
    case Sms = 'sms';
}
