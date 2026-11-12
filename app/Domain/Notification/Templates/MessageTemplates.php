<?php

declare(strict_types=1);

namespace App\Domain\Notification\Templates;

use App\Domain\Notification\Models\MessageTemplate;
use App\Domain\Notification\Models\SmsDefaultDltId;

/**
 * Renders a message from the Administrator's Active template, or from the portal's
 * System default text where there is none — never saved, or saved only as a Draft
 * (2026-11-13). Placeholders are `{name}`; a value is inserted as given, and nothing
 * else in the text is changed, so an SMS goes out exactly as its DLT template reads.
 *
 * **DLT (2026-11-13).** There is no common DLT Content Template ID: each SMS text has
 * its own. An Active customised SMS carries the ID saved with it; the System default
 * text carries the ID the Administrator saved for that text; with neither there is no
 * ID, and none is ever assumed.
 */
final class MessageTemplates
{
    /**
     * The SMS text and its DLT Content Template ID (null where none is configured);
     * null when the Administrator has made the SMS Inactive or the message has no SMS.
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
     * The e-mail subject and body; null when Inactive or not an e-mail.
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
        $source = $this->source($key, $channel);

        if ($source === null) {
            return null;
        }

        $replace = [];
        foreach (MessageTemplateCatalog::find($key)['placeholders'] ?? [] as $name) {
            $replace['{'.$name.'}'] = (string) ($values[$name] ?? '');
        }

        return [
            'subject' => strtr($source['subject'], $replace),
            'body' => strtr($source['body'], $replace),
            'dlt_template_id' => $source['dlt_template_id'],
        ];
    }

    /**
     * The text a message goes out in — with its placeholders — and the DLT Content
     * Template ID that belongs to that text; null when the message is not sent.
     *
     * @return array{subject: string, body: string, dlt_template_id: string|null, customised: bool}|null
     */
    public function source(string $key, string $channel): ?array
    {
        $definition = MessageTemplateCatalog::find($key);

        if ($definition === null || ! in_array($channel, $definition['channels'], true)) {
            return null;
        }

        $saved = MessageTemplate::query()->where('key', $key)->where('channel', $channel)->first();

        // Inactive: not sent — except a message needed for access, which always goes.
        if ($saved !== null && $saved->status === MessageTemplate::INACTIVE && ! $definition['mandatory']) {
            return null;
        }

        // Only an Active template's text is used; a Draft leaves the System default —
        // and the System default's own DLT ID — in place.
        if ($saved !== null && $saved->isActive()) {
            return [
                'subject' => (string) $saved->subject,
                'body' => $saved->body,
                'dlt_template_id' => $channel === MessageTemplate::SMS ? self::filled($saved->dlt_template_id) : null,
                'customised' => true,
            ];
        }

        $default = MessageTemplateCatalog::defaultFor($key, $channel);

        return [
            'subject' => $default['subject'],
            'body' => $default['body'],
            'dlt_template_id' => $channel === MessageTemplate::SMS ? $this->defaultDltId($key) : null,
            'customised' => false,
        ];
    }

    /** The DLT Content Template ID saved for a message's System default SMS text, if any. */
    public function defaultDltId(string $key): ?string
    {
        return self::filled(SmsDefaultDltId::query()->where('key', $key)->value('dlt_template_id'));
    }

    private static function filled(mixed $id): ?string
    {
        $id = trim((string) $id);

        return $id === '' ? null : $id;
    }
}
