<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Error pages — हिन्दी
|--------------------------------------------------------------------------
|
| Change item 7: the application is bilingual, and every user-visible string is
| served from here when the active locale is Hindi.
|
| The key structure mirrors `lang/en/errorpages.php` exactly. A key absent from this
| file falls back to the English string, which is Laravel's own behaviour and is
| better than showing a raw key on a government screen.
|
*/

return [
    'errors' => [
        '403' => [
            'access_denied' => 'पहुँच अस्वीकृत',
            'you_are_signed_in_but_this_action_is_not_permitt' => 'आप लॉग इन हैं, परंतु यह कार्रवाई आपकी भूमिका अथवा आपके विभाग हेतु अनुमत नहीं है। यदि आपको लगता है कि यह अनुमत होनी चाहिए, तो अपने नोडल अधिकारी से संपर्क करें।',
        ],
        '404' => [
            'page_not_found' => 'पृष्ठ नहीं मिला',
            'the_page_you_asked_for_does_not_exist_or_has_mov' => 'आपके द्वारा माँगा गया पृष्ठ विद्यमान नहीं है, अथवा स्थानांतरित हो गया है। पता जाँचें, अथवा डैशबोर्ड पर वापस जाएँ।',
        ],
        '419' => [
            'for_security_the_page_was_open_too_long_sign_in' => 'सुरक्षा कारणों से यह पृष्ठ अत्यधिक समय तक खुला रहा। पुनः लॉग इन करें — आपके द्वारा पूर्व में सहेजी गई कोई भी सामग्री नष्ट नहीं हुई है।',
            'your_session_expired' => 'आपका सत्र समाप्त हो गया',
        ],
        '429' => [
            'the_action_was_attempted_too_often_in_a_short_ti' => 'यह कार्रवाई अल्प समय में अत्यधिक बार की गई। कुछ क्षण प्रतीक्षा कर पुनः प्रयास करें।',
            'too_many_requests' => 'अत्यधिक अनुरोध',
        ],
        '500' => [
            'something_went_wrong' => 'कुछ त्रुटि हुई',
            'the_problem_has_been_recorded_nothing_you_submit' => 'समस्या अभिलिखित कर ली गई है। आपके द्वारा प्रस्तुत कोई भी सामग्री अपूर्ण रूप से सहेजी नहीं गई है — प्रणाली किसी कार्रवाई को पूर्ण करती है अथवा उसे पूर्णतः नहीं करती।',
        ],
        '503' => [
            'the_portal_is_briefly_unavailable_while_maintena' => 'अनुरक्षण कार्य पूर्ण होने तक पोर्टल अल्प समय हेतु अनुपलब्ध है। कृपया कुछ समय पश्चात पुनः प्रयास करें।',
            'under_maintenance' => 'अनुरक्षण जारी',
        ],
    ],
];
