<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public const COOKIE = 'rezervogk_locale';

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->cookie(self::COOKIE);
        // Recovery links carry a whitelisted language, never a user-supplied origin.
        $linkLocale = $request->isMethod('GET') && $request->routeIs('password.reset') ? $request->query('locale') : null;
        if (in_array($linkLocale, ['sq', 'en'], true)) {
            $locale = $linkLocale;
        }
        app()->setLocale(in_array($locale, ['sq', 'en'], true) ? $locale : 'sq');
        $response = $next($request);
        $response->setVary('Cookie', false);
        if (in_array($linkLocale, ['sq', 'en'], true)) {
            $response->headers->setCookie(cookie(self::COOKIE, $linkLocale, 525600, '/', null, $request->isSecure(), true, false, 'lax'));
        }

        return $response;
    }
}
