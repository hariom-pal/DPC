<?php

declare(strict_types=1);

namespace App\Enums;

enum SignatureStatus: string
{
    case Pending = 'pending';
    case Signed = 'signed';
    case Failed = 'failed';
}
