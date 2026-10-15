<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Error pages
|--------------------------------------------------------------------------
|
| Change item 7: every user-visible string comes from a language file, and no
| screen carries hardcoded text.
|
| Keys are nested to mirror the screen they serve, so `errorpages.errors.403.access_denied` reads as the
| path to the label rather than as a flat identifier. Strings that appear on more
| than one screen live in `ui.php` instead — shared because they *are* shared,
| not because somebody guessed they might be.
|
|
*/

return [
    'errors' => [
        '403' => [
            'access_denied' => 'Access denied',
            'you_are_signed_in_but_this_action_is_not_permitt' => 'You are signed in, but this action is not permitted for your role or your department. If you believe it should be, contact your nodal officer.',
        ],
        '404' => [
            'page_not_found' => 'Page not found',
            'the_page_you_asked_for_does_not_exist_or_has_mov' => 'The page you asked for does not exist, or has moved. Check the address, or return to the dashboard.',
        ],
        '419' => [
            'for_security_the_page_was_open_too_long_sign_in' => 'For security, the page was open too long. Sign in again — nothing you had already saved has been lost.',
            'your_session_expired' => 'Your session expired',
        ],
        '429' => [
            'the_action_was_attempted_too_often_in_a_short_ti' => 'The action was attempted too often in a short time. Wait a moment and try again.',
            'too_many_requests' => 'Too many requests',
        ],
        '500' => [
            'something_went_wrong' => 'Something went wrong',
            'the_problem_has_been_recorded_nothing_you_submit' => 'The problem has been recorded. Nothing you submitted has been partly saved — the system completes an action or does none of it.',
        ],
        '503' => [
            'the_portal_is_briefly_unavailable_while_maintena' => 'The portal is briefly unavailable while maintenance completes. Please try again shortly.',
            'under_maintenance' => 'Under maintenance',
        ],
    ],
];
