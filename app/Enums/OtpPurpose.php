<?php

declare(strict_types=1);

namespace App\Enums;

enum OtpPurpose: string
{
    case Registration = 'registration';
    case Login = 'login';
    case Signature = 'signature';
}
