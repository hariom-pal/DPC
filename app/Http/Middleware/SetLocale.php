<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Apply the user's chosen UI language (English/Hindi) for the request.
     *
     * The application is bilingual per the PPT source screens; the chosen
     * locale is persisted in the session and defaults to the configured locale.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $supported = config('app.supported_locales', ['en']);
        $locale = (string) $request->session()->get('locale', (string) config('app.locale'));

        if (in_array($locale, $supported, true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
