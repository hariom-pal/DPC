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
    'promotion_order' => [
        'subject' => 'Promotion order :number issued',
        'message' => 'Promotion order :number dated :date for the post of :post has been issued. Open the order: :link',
    ],
    'sitting' => [
        // Business clarification of 2026-09-05: the message the committee and the
        // candidates receive when the DPC Allottee fixes or moves the sitting.
        'subject_scheduled' => 'ODPC Portal — :dpc has been scheduled',
        'subject_rescheduled' => 'ODPC Portal — :dpc has been re-scheduled',
        'sms_scheduled' => 'ODPC: :dpc is scheduled for :date at :time. :instructions',
        'sms_rescheduled' => 'ODPC: :dpc has been MOVED to :date at :time. Please note the new date. :instructions',
        'joining_instructions' => 'Please attend on time. Committee members should sign in to the e-DPC Portal to take up the sitting; officers under consideration need not attend and may check their own record through Self-Inspection. Do not share this message.',
    ],
];
