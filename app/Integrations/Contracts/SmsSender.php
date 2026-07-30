<?php

declare(strict_types=1);

namespace App\Integrations\Contracts;

/**
 * Provider abstraction for outbound SMS. Development binds a log sender;
 * production binds the government SMS gateway. Calling code depends on this
 * interface only.
 */
interface SmsSender
{
    public function send(string $mobile, string $message): void;
}
