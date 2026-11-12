<?php

declare(strict_types=1);

namespace App\Domain\Notification\Templates;

use App\Domain\Notification\Models\MessageTemplate;

/**
 * Renders a message from the Administrator's template, or from the built-in wording
 * where none has been saved (2026-11-12). Placeholders are `{name}`; a value is
 * inserted as given.
 */
final class MessageTemplates
{
    /**
     * The SMS text and its DLT template ID; null when the Administrator has switched
     * the SMS off or the message has no SMS.
     *
     * @param  array<string, string|int|null>  $values
     * @return array{body: string, dlt_template_id: string|null}|null
     */
    public function sms(string $key, array $values): ?array
    {
        $rendered = $this->render($key, MessageTemplate::SMS, $values);

        return $rendered === null ? null : ['body' => $rendered['body'], 'dlt_template_id' => $rendered['dlt_template_id']];
    }

    /**
     * The e-mail subject and body; null when switched off or not an e-mail.
     *
     * @param  array<string, string|int|null>  $values
     * @return array{subject: string, body: string}|null
     */
    public function email(string $key, array $values): ?array
    {
        $rendered = $this->render($key, MessageTemplate::EMAIL, $values);

        return $rendered === null ? null : ['subject' => $rendered['subject'], 'body' => $rendered['body']];
    }

    /**
     * @param  array<string, string|int|null>  $values
     * @return array{subject: string, body: string, dlt_template_id: string|null}|null
     */
    public function render(string $key, string $channel, array $values): ?array
    {
        $definition = MessageTemplateCatalog::find($key);

        if ($definition === null || ! in_array($channel, $definition['channels'], true)) {
            return null;
        }

        $saved = MessageTemplate::query()->where('key', $key)->where('channel', $channel)->first();

        if ($saved !== null && ! $saved->is_active && ! $definition['mandatory']) {
            return null;
        }

        $text = $saved !== null
            ? ['subject' => (string) $saved->subject, 'body' => $saved->body]
            : MessageTemplateCatalog::defaultFor($key);

        $replace = [];
        foreach ($definition['placeholders'] as $name) {
            $replace['{'.$name.'}'] = (string) ($values[$name] ?? '');
        }

        return [
            'subject' => strtr($text['subject'], $replace),
            'body' => strtr($text['body'], $replace),
            'dlt_template_id' => $saved?->dlt_template_id,
        ];
    }
}
