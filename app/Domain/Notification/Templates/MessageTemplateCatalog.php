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
            'sitting.meeting' => [
                'label' => 'DPC sitting meeting started',
                'when' => 'When the DPC Chairman starts the sitting\'s meeting. Sent to every other member of the committee with a login.',
                'channels' => [MessageTemplate::EMAIL],
                'placeholders' => ['dpc', 'chairman', 'when', 'url'],
                'required' => ['url'],
                'mandatory' => false,
                'default' => fn (array $p): array => [
                    'subject' => (string) __('notifications.sitting.meeting_subject', $p),
                    'body' => (string) __('notifications.sitting.meeting_body', $p),
                ],
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
                'label' => 'DPC sitting scheduled — to the committee members',
                'when' => 'When the CGPSC Member fixes the date of a DPC sitting. Sent to the DPC Committee members (the employees have their own message).',
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
                'label' => 'DPC sitting re-scheduled — to the committee members',
                'when' => 'When the date of a DPC sitting is changed. Sent to the DPC Committee members (the employees have their own message).',
                'channels' => $both,
                'placeholders' => ['dpc', 'date', 'time', 'instructions'],
                'required' => ['date'],
                'mandatory' => false,
                'default' => fn (array $p): array => [
                    'subject' => (string) __('notifications.sitting.subject_rescheduled', $p),
                    'body' => (string) __('notifications.sitting.sms_rescheduled', $p),
                ],
            ],
            // MoM of 16 November 2026, point 4: the officers' own wording, apart from
            // the committee's.
            'sitting.scheduled_candidate' => [
                'label' => 'DPC meeting date — to the employees under consideration',
                'when' => 'When the date of a DPC meeting is fixed. Sent to each employee on the DPC\'s Seniority List.',
                'channels' => $both,
                'placeholders' => ['name', 'dpc', 'date', 'time'],
                'required' => ['date'],
                'mandatory' => false,
                'default' => fn (array $p): array => [
                    'subject' => (string) __('notifications.sitting.subject_candidate_scheduled', $p),
                    'body' => (string) __('notifications.sitting.sms_candidate_scheduled', $p),
                ],
            ],
            'sitting.rescheduled_candidate' => [
                'label' => 'DPC meeting date changed — to the employees under consideration',
                'when' => 'When the date of a DPC meeting is changed. Sent to each employee on the DPC\'s Seniority List.',
                'channels' => $both,
                'placeholders' => ['name', 'dpc', 'date', 'time'],
                'required' => ['date'],
                'mandatory' => false,
                'default' => fn (array $p): array => [
                    'subject' => (string) __('notifications.sitting.subject_candidate_rescheduled', $p),
                    'body' => (string) __('notifications.sitting.sms_candidate_rescheduled', $p),
                ],
            ],
            // MoM of 16 November 2026, point 6: deadline reminders, e-mailed.
            'stage.reminder' => [
                'label' => 'DPC stage deadline near',
                'when' => 'Once a day while a DPC\'s stage has a day or less left before the deadline set in Workflow Timelines. Sent to whoever is to act at the stage, by e-mail and/or SMS as set in Workflow Timelines.',
                'channels' => $both,
                'placeholders' => ['dpc', 'stage', 'action', 'due', 'url'],
                'required' => ['due'],
                'mandatory' => false,
                'default' => fn (array $p): array => [
                    'subject' => (string) __('notifications.stage.reminder_subject', $p),
                    'body' => (string) __('notifications.stage.reminder_body', $p),
                ],
            ],
            'stage.overdue' => [
                'label' => 'DPC stage deadline passed',
                'when' => 'Once a day after a DPC\'s stage has passed its deadline. Sent to whoever is to act at the stage, by e-mail and/or SMS as set in Workflow Timelines.',
                'channels' => $both,
                'placeholders' => ['dpc', 'stage', 'action', 'due', 'url'],
                'required' => ['due'],
                'mandatory' => false,
                'default' => fn (array $p): array => [
                    'subject' => (string) __('notifications.stage.overdue_subject', $p),
                    'body' => (string) __('notifications.stage.overdue_body', $p),
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
     * The System default text with its placeholders left in, `{name}`.
     *
     * An SMS's System default text is always the one language its DLT template is
     * registered in (`dpc.sms.text_locale`, English unless set), whatever language the
     * screen is in (2026-11-13): an SMS that differs from its registered text by a
     * single character is not delivered.
     *
     * @return array{subject: string, body: string}
     */
    public static function defaultFor(string $key, ?string $channel = null): array
    {
        $definition = self::find($key);

        if ($definition === null) {
            return ['subject' => '', 'body' => ''];
        }

        $tokens = [];
        foreach ($definition['placeholders'] as $name) {
            $tokens[$name] = '{'.$name.'}';
        }

        if ($channel !== MessageTemplate::SMS) {
            return ($definition['default'])($tokens);
        }

        $locale = app()->getLocale();
        app()->setLocale((string) config('dpc.sms.text_locale', 'en'));

        try {
            return ($definition['default'])($tokens);
        } finally {
            app()->setLocale($locale);
        }
    }

    /**
     * The text as it is registered on the DLT portal (2026-11-13): each placeholder
     * becomes the DLT variable `{#var#}`, everything else exactly as sent.
     */
    public static function dltText(string $text): string
    {
        return (string) preg_replace('/\{[a-z_]+\}/', '{#var#}', $text);
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
