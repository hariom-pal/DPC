<?php

declare(strict_types=1);

namespace App\Enums;

enum OtpPurpose: string
{
    case Registration = 'registration';
    case Login = 'login';
    // A signed-in user's own e-mail / mobile change, proved on both (2026-11-03).
    case ContactChange = 'contact_change';
    // Forgot password: the owner proves the account's e-mail and mobile (2026-11-03).
    case PasswordReset = 'password_reset';
}
