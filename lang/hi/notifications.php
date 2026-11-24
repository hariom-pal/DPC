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
    'registration' => [
        'posted' => [
            'subject' => 'आप :department के :role के रूप में पंजीकृत',
            'message' => 'ओ.डी.पी.सी. पोर्टल पर आपको :department के :role के रूप में पंजीकृत किया गया है। अपने वर्तमान पासवर्ड से लॉग इन करें।',
        ],
        'replaced' => [
            'subject' => ':department के :role का आपका पद अन्य अधिकारी को',
            'message' => 'ओ.डी.पी.सी. पोर्टल पर :department के :role के रूप में अन्य अधिकारी पंजीकृत हुए हैं। अब यह पद आपके पास नहीं है; आप लॉग इन कर सकते हैं, और नया पंजीयन आपको पुनः पदस्थ करेगा।',
        ],
    ],
    'promotion_order' => [
        'subject' => 'पदोन्नति आदेश :number जारी',
        'message' => ':post पद हेतु पदोन्नति आदेश :number दिनांक :date जारी किया गया है। आदेश देखें: :link',
    ],
    'stage' => [
        'reminder_subject' => 'ओ.डी.पी.सी. पोर्टल — :dpc: समय-सीमा :due',
        'reminder_body' => ':dpc आपके पास (:stage) लंबित है: :action। समय-सीमा :due है। खोलें: :url',
        'overdue_subject' => 'ओ.डी.पी.सी. पोर्टल — :dpc: समय-सीमा समाप्त',
        'overdue_body' => ':dpc आपके पास (:stage) लंबित है: :action। :due की समय-सीमा समाप्त हो चुकी है। खोलें: :url',
    ],
    'sitting' => [
        'meeting_subject' => 'ओ.डी.पी.सी. पोर्टल — :dpc: डी.पी.सी. बैठक प्रारंभ',
        'meeting_body' => 'डी.पी.सी. अध्यक्ष (:chairman) ने :dpc की बैठक प्रारंभ की है, जो :when को होगी। उस समय ई-डी.पी.सी. पोर्टल में लॉगिन कर बैठक से जुड़ें: :url',
        // Business clarification of 2026-09-05: the message the committee and the
        // candidates receive when the DPC Allottee fixes or moves the sitting.
        'subject_candidate_scheduled' => 'ओ.डी.पी.सी. पोर्टल — आपकी पदोन्नति हेतु डी.पी.सी. की तिथि निर्धारित',
        'subject_candidate_rescheduled' => 'ओ.डी.पी.सी. पोर्टल — आपकी पदोन्नति हेतु डी.पी.सी. की तिथि परिवर्तित',
        'sms_candidate_scheduled' => 'प्रिय :name, आपकी पदोन्नति पर विचार हेतु डी.पी.सी. (:dpc) की बैठक :date को :time बजे होगी। ओ.डी.पी.सी. पोर्टल।',
        'sms_candidate_rescheduled' => 'प्रिय :name, आपकी पदोन्नति पर विचार हेतु डी.पी.सी. (:dpc) की बैठक अब :date को :time बजे होगी। ओ.डी.पी.सी. पोर्टल।',
        'subject_scheduled' => 'ओ.डी.पी.सी. पोर्टल — :dpc की तिथि निर्धारित',
        'subject_rescheduled' => 'ओ.डी.पी.सी. पोर्टल — :dpc की तिथि परिवर्तित',
        'sms_scheduled' => 'ओ.डी.पी.सी.: :dpc :date को :time बजे निर्धारित है। :instructions',
        'sms_rescheduled' => 'ओ.डी.पी.सी.: :dpc की तिथि बदलकर :date, :time बजे कर दी गई है। कृपया नई तिथि नोट करें। :instructions',
        'joining_instructions' => 'कृपया समय पर उपस्थित हों। समिति सदस्य सिटिंग हेतु ई-डी.पी.सी. पोर्टल में लॉग-इन करें; विचाराधीन अधिकारियों को उपस्थित होने की आवश्यकता नहीं है, वे अभ्यर्थी पोर्टल पर अपना अभिलेख देख सकते हैं। यह संदेश साझा न करें।',
    ],
    'candidate_verification' => [
        'opened' => [
            'subject' => 'ODPC: :dpc हेतु अपने अभिलेख का सत्यापन :deadline तक करें',
            'message' => 'ODPC: :dpc हेतु आपका अभिलेख आपके सत्यापन के लिए उपलब्ध है। :url पर अपने पंजीकृत मोबाइल नंबर एवं OTP से प्रवेश करें, प्रत्येक खंड जाँचें, और :deadline तक सहमति दें या शिकायत प्रस्तुत करें। केवल एक अंतिम प्रस्तुति।',
        ],
        'grievance_submitted' => [
            'subject' => 'ODPC: :dpc पर :candidate द्वारा शिकायत',
            'message' => 'ODPC: :candidate ने :dpc पर :count शिकायत(ें) प्रस्तुत की हैं। :url पर प्रत्येक पर निर्णय करें।',
        ],
        'grievance_review' => [
            'subject' => 'ODPC: :dpc पर :count अभ्यर्थी शिकायत(ें) समीक्षा हेतु',
            'message' => 'ODPC: :dpc का अभ्यर्थी सत्यापन :count शिकायत(ों) के साथ बंद हुआ। :url पर प्रत्येक की समीक्षा कर निर्णय करें।',
        ],
        'correction_assigned' => [
            'subject' => 'ODPC: :dpc पर सुधार सौंपा गया',
            'message' => 'ODPC: प्रशासकीय प्रमुख ने :dpc पर :candidate का :section आपके सुधार हेतु खोला है। :url पर सुधार कर वापस प्रस्तुत करें।',
        ],
        'correction_submitted' => [
            'subject' => 'ODPC: :dpc पर सुधार प्रस्तुत',
            'message' => 'ODPC: प्राधिकृत अधिकारी ने :dpc पर :candidate के :section का सुधार प्रस्तुत किया है। :url पर समीक्षा कर शिकायत का निर्णय करें।',
        ],
        'grievance_resolved' => [
            'subject' => 'ODPC: :dpc पर आपकी शिकायत का निर्णय',
            'message' => 'ODPC: प्रशासकीय प्रमुख ने :dpc के :section पर आपकी शिकायत का निर्णय किया है। निर्णय :url पर देखें।',
        ],
        'record_corrected' => [
            'subject' => 'ODPC: :dpc पर आपका अभिलेख सुधारा गया',
            'message' => 'ODPC: आपकी शिकायत के बाद :dpc पर आपके अभिलेख का :section सुधारा गया। सुधरी जानकारी :url पर देखें।',
        ],
        'secretary_ready' => [
            'subject' => 'ODPC: :dpc सत्यापित एवं आपके पास',
            'message' => 'ODPC: :dpc का अभ्यर्थी सत्यापन पूर्ण है, कोई शिकायत लंबित नहीं। यह :url पर आपके पास है।',
        ],
    ],
];
