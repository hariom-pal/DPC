<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Login, registration and verification screens — हिन्दी
|--------------------------------------------------------------------------
|
| Change item 7: the application is bilingual, and every user-visible string is
| served from here when the active locale is Hindi.
|
| The key structure mirrors `lang/en/authui.php` exactly. A key absent from this
| file falls back to the English string, which is Laravel's own behaviour and is
| better than showing a raw key on a government screen.
|
*/

return [
    'role_switch' => [
        'now_acting_as' => 'अब आप :role के रूप में कार्य कर रहे हैं।',
        'unknown' => 'यह इस पोर्टल की कोई भूमिका नहीं है।',
    ],
    'change_password' => [
        'change_password' => 'पासवर्ड बदलें',
        'confirm_new_password' => 'नए पासवर्ड की पुष्टि करें',
        'current_temporary_password' => 'वर्तमान (अस्थायी) पासवर्ड',
        'hint_password' => 'कम से कम 8 वर्ण, जिनमें बड़े एवं छोटे अक्षर, एक अंक तथा एक चिह्न हो।',
        'new_password' => 'नया पासवर्ड',
        'update_password' => 'पासवर्ड अद्यतन करें',
        'you_are_using_a_system_issued_password_please_se' => 'आप प्रणाली द्वारा जारी पासवर्ड का उपयोग कर रहे हैं। आगे बढ़ने हेतु कृपया नया पासवर्ड निर्धारित करें।',
    ],
    'login' => [
        'departmental_promotion_committee_portal' => 'लॉग इन · विभागीय पदोन्नति समिति पोर्टल',
        'email_or_mobile' => 'ईमेल अथवा मोबाइल',
        'login_e_dpc_portal' => 'लॉग इन — ई-डी.पी.सी. पोर्टल',
        'new_department' => 'नया विभाग?',
        'officer_under_consideration' => 'पदोन्नति हेतु विचाराधीन अधिकारी?',
        'password' => 'पासवर्ड',
        'register_here' => 'यहाँ पंजीयन करें',
        'remember_me_on_this_device' => 'इस उपकरण पर मुझे स्मरण रखें',
        'signing_in' => 'लॉग इन किया जा रहा है',
        'trouble_signing_in_contact_your_department_s_nod' => 'लॉग इन में कठिनाई? अपने विभाग के नोडल अधिकारी से संपर्क करें।',
        'view_your_own_data' => 'स्वयं का डाटा देखें',
    ],
    'register' => [
        'administrative_head_level_2' => 'प्रशासकीय प्रमुख (स्तर-2)',
        'department_name' => 'विभाग का नाम',
        'department_registration_e_dpc_portal' => 'विभाग पंजीयन — ई-डी.पी.सी. पोर्टल',
        'designation' => 'पदनाम',
        'district' => 'जिला',
        'dpc_authorized_officer_level_1' => 'डी.पी.सी. हेतु प्राधिकृत अधिकारी (स्तर-1)',
        'email_gov_in' => 'ईमेल (.gov.in)',
        'email_only_gov_id_department_contact_email' => 'विभागीय संपर्क ईमेल (केवल .gov.in)',
        'mobile' => 'मोबाइल',
        'mobile_2' => 'मो. न.',
        'name' => 'नाम',
        'pin' => 'पिन',
        'place' => 'स्थान',
        'register' => 'पंजीकृत करें',
        'registration_of_department' => 'विभाग का पंजीयन',
        'select_department' => '— विभाग चुनें —',
        'text' => 'विभाग का पंजीयन',
        'what_happens_after_you_submit' => 'प्रस्तुत करने के पश्चात क्या होगा',
        'what_happens_next' => 'आपका विवरण ई-मेल एवं मोबाइल ओ.टी.पी. द्वारा सत्यापित होता है। तत्पश्चात आयोग पंजीयन अनुमोदित करता है और प्राधिकृत अधिकारी एवं प्रशासनिक प्रमुख को लॉग इन विवरण जारी करता है।',
    ],
    'verify' => [
        'email_otp_sent_to' => 'ई-मेल ओ.टी.पी. (:email पर भेजा गया)',
        'mobile_otp_sent_to' => 'मोबाइल ओ.टी.पी. (:mobile पर भेजा गया)',
        'otp_verification' => 'ओ.टी.पी. सत्यापन',
        'verify' => 'सत्यापित करें',
    ],
];
