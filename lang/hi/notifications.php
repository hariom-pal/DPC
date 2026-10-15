<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Notification centre — हिन्दी
|--------------------------------------------------------------------------
|
| Change item 7: the application is bilingual, and every user-visible string is
| served from here when the active locale is Hindi.
|
| The key structure mirrors `lang/en/notifications.php` exactly. A key absent from this
| file falls back to the English string, which is Laravel's own behaviour and is
| better than showing a raw key on a government screen.
|
*/

return [
    'index' => [
        'new' => 'नया',
        'notifications_are_also_sent_by_sms_and_e_mail_on' => 'इन गेटवे के जुड़ने पर सूचनाएँ एस.एम.एस. एवं ईमेल द्वारा भी भेजी जाएँगी। यह सूची उन पर निर्भर नहीं है।',
    ],
    'sitting' => [
        // Business clarification of 2026-09-05: the message the committee and the
        // candidates receive when the DPC Allottee fixes or moves the sitting.
        'subject_scheduled' => 'ओ.डी.पी.सी. पोर्टल — :dpc की तिथि निर्धारित',
        'subject_rescheduled' => 'ओ.डी.पी.सी. पोर्टल — :dpc की तिथि परिवर्तित',
        'sms_scheduled' => 'ओ.डी.पी.सी.: :dpc :date को :time बजे निर्धारित है। :instructions',
        'sms_rescheduled' => 'ओ.डी.पी.सी.: :dpc की तिथि बदलकर :date, :time बजे कर दी गई है। कृपया नई तिथि नोट करें। :instructions',
        'joining_instructions' => 'कृपया समय पर उपस्थित हों। समिति सदस्य सिटिंग हेतु ई-डी.पी.सी. पोर्टल में लॉग-इन करें; विचाराधीन अधिकारियों को उपस्थित होने की आवश्यकता नहीं है, वे स्वयं-निरीक्षण से अपना अभिलेख देख सकते हैं। यह संदेश साझा न करें।',
    ],
];
