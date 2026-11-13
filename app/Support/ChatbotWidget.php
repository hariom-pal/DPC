<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Middleware\SecurityHeaders;

/**
 * The help-desk chatbot embedded on every page (the self-hosted Knowledge Chatbot).
 *
 * **Off unless configured.** `DPC_CHATBOT_URL` (where `widget.js` is served, e.g.
 * `https://chat.example.gov.in`) and `DPC_CHATBOT_SITE_ID` (the website's site id
 * in the chatbot's admin) must both be set; a blank or malformed value leaves the
 * widget out entirely rather than rendering a tag the browser would refuse.
 *
 * The application's CSP allows scripts and fetches from its own origin only, so
 * {@see SecurityHeaders} adds exactly this one origin — and
 * nothing else — to `script-src`, `connect-src` and `img-src` while it is on.
 *
 * The widget sends only what the visitor types; it reads nothing from the page.
 */
class ChatbotWidget
{
    public static function enabled(): bool
    {
        return self::origin() !== null && self::siteId() !== null;
    }

    /** `scheme://host[:port]` of the chatbot, or null when unset or malformed. */
    public static function origin(): ?string
    {
        $url = config('dpc.chatbot.url');

        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $parts = parse_url(trim($url));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || ! preg_match('/^[A-Za-z0-9.\-]+$/', $host)) {
            return null;
        }

        return $scheme.'://'.strtolower($host).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    public static function scriptUrl(): ?string
    {
        $origin = self::origin();

        return $origin === null ? null : $origin.'/widget.js';
    }

    public static function siteId(): ?string
    {
        $id = config('dpc.chatbot.site_id');

        return is_string($id) && preg_match('/^[A-Za-z0-9_\-]{1,100}$/', trim($id)) ? trim($id) : null;
    }
}
