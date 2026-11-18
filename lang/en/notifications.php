<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Notification centre
|--------------------------------------------------------------------------
|
| Change item 7: every user-visible string comes from a language file, and no
| screen carries hardcoded text.
|
| Keys are nested to mirror the screen they serve, so `notifications.index.new` reads as the
| path to the label rather than as a flat identifier. Strings that appear on more
| than one screen live in `ui.php` instead — shared because they *are* shared,
| not because somebody guessed they might be.
|
|
*/

return [
    'index' => [
        'new' => 'New',
        'notifications_are_also_sent_by_sms_and_e_mail_on' => 'Notifications are also sent by SMS and e-mail once those gateways are
                connected. This list does not depend on them.',
    ],
    'registration' => [
        'posted' => [
            'subject' => 'You are registered as :role of :department',
            'message' => 'You have been registered on the ODPC Portal as the :role of :department. Sign in with your existing password.',
        ],
        'replaced' => [
            'subject' => 'Your seat as :role of :department has been taken',
            'message' => 'Another officer has been registered as the :role of :department on the ODPC Portal. You no longer hold that seat; you can still sign in, and a fresh registration posts you again.',
        ],
    ],
    'promotion_order' => [
        'subject' => 'Promotion order :number issued',
        'message' => 'Promotion order :number dated :date for the post of :post has been issued. Open the order: :link',
    ],
    'stage' => [
        'reminder_subject' => 'ODPC Portal — :dpc: deadline :due',
        'reminder_body' => ':dpc is with you (:stage): :action. The deadline is :due. Open it: :url',
        'overdue_subject' => 'ODPC Portal — :dpc: deadline passed',
        'overdue_body' => ':dpc is with you (:stage): :action. The deadline of :due has passed. Open it: :url',
    ],
    'sitting' => [
        'meeting_subject' => 'ODPC Portal — :dpc: the DPC meeting has started',
        'meeting_body' => 'The DPC Chairman (:chairman) has started the meeting of :dpc, held on :when. Sign in to the e-DPC Portal at that time and join it: :url',
        // Business clarification of 2026-09-05: the message the committee and the
        // candidates receive when the DPC Allottee fixes or moves the sitting.
        'subject_candidate_scheduled' => 'ODPC Portal — the DPC for your promotion is fixed',
        'subject_candidate_rescheduled' => 'ODPC Portal — the date of the DPC for your promotion has changed',
        'sms_candidate_scheduled' => 'Dear :name, the DPC (:dpc) considering your promotion will meet on :date at :time. ODPC Portal.',
        'sms_candidate_rescheduled' => 'Dear :name, the DPC (:dpc) considering your promotion has been moved to :date at :time. ODPC Portal.',
        'subject_scheduled' => 'ODPC Portal — :dpc has been scheduled',
        'subject_rescheduled' => 'ODPC Portal — :dpc has been re-scheduled',
        'sms_scheduled' => 'ODPC: :dpc is scheduled for :date at :time. :instructions',
        'sms_rescheduled' => 'ODPC: :dpc has been MOVED to :date at :time. Please note the new date. :instructions',
        'joining_instructions' => 'Please attend on time. Committee members should sign in to the e-DPC Portal to take up the sitting; officers under consideration need not attend and may check their own record through Self-Inspection. Do not share this message.',
    ],
];
