<?php

declare(strict_types=1);

namespace App\Domain\Notification\Templates;

use App\Domain\Notification\Models\MessageTemplate;

/**
 * Every SMS and e-mail the portal sends, as a template the CGPSC Administrator may
 * reword (2026-11-12).
 *
 * Each message names its placeholders — written `{name}` in a template — and those it
 * cannot do without (an OTP without `{code}` is useless). The built-in wording is the
 * portal's own text, read from the language files as before, so a message nobody has
 * edited goes out exactly as it always did. A message `mandatory` for security or
 * access (the OTP, an invitation) cannot be switched off.
 */
final class MessageTemplateCatalog
{
    /**
     * @return array<string, array{label: string, when: string, channels: list<string>, placeholders: list<string>, required: list<string>, mandatory: bool, default: callable(array<string, string>): array{subject: string, body: string}}>
     */
    public static function all(): array
    {
        $both = [MessageTemplate::SMS, MessageTemplate::EMAIL];

        return [
            'otp.code' => [
                'label' => 'Verification code (OTP)',
                'when' => 'When an e-mail address or mobile number is verified: at sign-in, at registration, when an officer changes their own e-mail or mobile, and on Forgot Password. Sent to the address or number being verified.',
                'channels' => $both,
                'placeholders' => ['code', 'minutes'],
                'required' => ['code'],
                'mandatory' => true,
                'default' => fn (array $p): array => [
                    'subject' => 'ODPC Portal — Verification Code',
                    'body' => "Your ODPC verification code is {$p['code']}. Valid for {$p['minutes']} minutes. Do not share it.",
                ],
            ],
            'account.created' => [
                'label' => 'Account created',
                'when' => 'When the CGPSC Administrator or a Department Admin creates an account. Sent to the new account holder.',
                'channels' => $both,
                'placeholders' => ['login', 'url'],
                'required' => [],
                'mandatory' => false,
                'default' => fn (array $p): array => self::lang('authui.credentials.created', $p),
            ],
            'account.reset' => [
                'label' => 'Password reset by the administrator',
                'when' => 'When the CGPSC Administrator resets an account\'s password. Sent to the account holder.',
                'channels' => $both,
                'placeholders' => ['login', 'url'],
                'required' => [],
                'mandatory' => false,
                'default' => fn (array $p): array => self::lang('authui.credentials.reset', $p),
            ],
            'department.invitation' => [
                'label' => 'Department Admin invitation',
                'when' => 'When the CGPSC Administrator invites a department, or sends its invitation again. Sent to the department\'s official e-mail.',
                'channels' => [MessageTemplate::EMAIL],
                'placeholders' => ['department', 'hours', 'link'],
                'required' => ['link'],
                'mandatory' => true,
                'default' => fn (array $p): array => [
                    'subject' => (string) __('invitations.mail.subject'),
                    'body' => (string) __('invitations.mail.body', $p),
                ],
            ],
            'department.registered' => [
                'label' => 'Department Admin registration complete',
                'when' => 'When the Department Admin completes registration from the invitation. Sent to the Department Admin.',
                'channels' => [MessageTemplate::EMAIL],
                'placeholders' => ['department', 'url'],
                'required' => [],
                'mandatory' => false,
                'default' => fn (array $p): array => [
                    'subject' => (string) __('invitations.mail.registered_subject'),
                    'body' => (string) __('invitations.mail.registered_body', $p),
                ],
            ],
            'seat.posted' => [
                'label' => 'Registered to a department seat',
                'when' => 'Not sent at present: no action in the portal raises it.',
                'channels' => $both,
                'placeholders' => ['role', 'department'],
                'required' => [],
                'mandatory' => false,
                'default' => fn (array $p): array => self::lang('notifications.registration.posted', $p),
            ],
            'seat.replaced' => [
                'label' => 'Department seat taken by another officer',
                'when' => 'Not sent at present: no action in the portal raises it.',
                'channels' => $both,
                'placeholders' => ['role', 'department'],
                'required' => [],
                'mandatory' => false,
                'default' => fn (array $p): array => self::lang('notifications.registration.replaced', $p),
            ],
            'sitting.scheduled' => [
                'label' => 'DPC sitting scheduled',
                'when' => 'When the CGPSC Member fixes the date of a DPC sitting. Sent to the committee members and the officers under consideration.',
                'channels' => $both,
                'placeholders' => ['dpc', 'date', 'time', 'instructions'],
                'required' => ['date'],
                'mandatory' => false,
                'default' => fn (array $p): array => [
                    'subject' => (string) __('notifications.sitting.subject_scheduled', $p),
                    'body' => (string) __('notifications.sitting.sms_scheduled', $p),
                ],
            ],
            'sitting.rescheduled' => [
                'label' => 'DPC sitting re-scheduled',
                'when' => 'When the date of a DPC sitting is changed. Sent to the committee members and the officers under consideration.',
                'channels' => $both,
                'placeholders' => ['dpc', 'date', 'time', 'instructions'],
                'required' => ['date'],
                'mandatory' => false,
                'default' => fn (array $p): array => [
                    'subject' => (string) __('notifications.sitting.subject_rescheduled', $p),
                    'body' => (string) __('notifications.sitting.sms_rescheduled', $p),
                ],
            ],
            'result.published' => [
                'label' => 'DPC result available',
                'when' => 'When CGPSC approves a DPC\'s result and its proceedings and promotion list are published. Sent to the department\'s AO and AH.',
                'channels' => $both,
                'placeholders' => ['dpc'],
                'required' => [],
                'mandatory' => false,
                'default' => fn (array $p): array => [
                    'subject' => 'ODPC Portal — DPC Result Available',
                    'body' => "ODPC: the result of DPC {$p['dpc']} has been approved by CGPSC and the proceedings and promotion list are now available on the portal.",
                ],
            ],
            'promotion_order.issued' => [
                'label' => 'Promotion order issued',
                'when' => 'When the AH issues a promotion order, including one after a sealed cover is opened. Sent to the officers promoted and to the committee members.',
                'channels' => $both,
                'placeholders' => ['number', 'date', 'post', 'link'],
                'required' => ['link'],
                'mandatory' => false,
                'default' => fn (array $p): array => self::lang('notifications.promotion_order', $p),
            ],
            'review.superseded' => [
                'label' => 'DPC superseded by a Review DPC',
                'when' => 'When a Review DPC\'s result is approved and replaces the earlier DPC. Sent to the Review DPC\'s AO and AH.',
                'channels' => $both,
                'placeholders' => ['original', 'review', 'review_number'],
                'required' => [],
                'mandatory' => false,
                'default' => fn (array $p): array => [
                    'subject' => 'ODPC Portal — DPC Superseded by Review',
                    'body' => "ODPC: DPC {$p['original']} has been superseded by Review DPC {$p['review']} (review {$p['review_number']}). The earlier promotion list is no longer the operative one.",
                ],
            ],
            'workflow.update' => [
                'label' => 'Workflow update (submitted, approved, returned …)',
                'when' => 'When a Pre-DPC, DPC proposal, allotment or circulation moves on — submitted, approved, returned, cancelled, allotted and so on. Sent to the officers the step names: for example the AH when the AO submits, and the AO when the AH approves or returns.',
                'channels' => $both,
                'placeholders' => ['workflow', 'event', 'status', 'remarks'],
                'required' => [],
                'mandatory' => false,
                'default' => fn (array $p): array => [
                    'subject' => 'ODPC Portal — Workflow Update',
                    'body' => "ODPC: {$p['workflow']} was '{$p['event']}' (status: {$p['status']}).{$p['remarks']}",
                ],
            ],
        ];
    }

    /**
     * @return array{label: string, when: string, channels: list<string>, placeholders: list<string>, required: list<string>, mandatory: bool, default: callable(array<string, string>): array{subject: string, body: string}}|null
     */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * The built-in wording with its placeholders left in, `{name}`.
     *
     * @return array{subject: string, body: string}
     */
    public static function defaultFor(string $key): array
    {
        $definition = self::find($key);

        if ($definition === null) {
            return ['subject' => '', 'body' => ''];
        }

        $tokens = [];
        foreach ($definition['placeholders'] as $name) {
            $tokens[$name] = '{'.$name.'}';
        }

        return ($definition['default'])($tokens);
    }

    /**
     * The placeholders a text uses, `{name}`.
     *
     * @return list<string>
     */
    public static function placeholdersIn(string $text): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $text, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @param  array<string, string>  $p
     * @return array{subject: string, body: string}
     */
    private static function lang(string $key, array $p): array
    {
        return ['subject' => (string) __($key.'.subject', $p), 'body' => (string) __($key.'.message', $p)];
    }
}
