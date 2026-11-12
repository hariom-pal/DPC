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
    /**
     * @param  string|null  $templateId  the DLT template ID the message is registered
     *                                   under, from the Administrator's template (2026-11-12)
     */
    public function send(string $mobile, string $message, ?string $templateId = null): void;
}
