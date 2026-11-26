<?php

namespace App\Http\Middleware;

use App\Support\Locales;
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
        if (Locales::supports($linkLocale)) {
            $locale = $linkLocale;
        }
        app()->setLocale(Locales::resolve($locale));
        $response = $next($request);
        $response->setVary('Cookie', false);
        if (Locales::supports($linkLocale)) {
            $response->headers->setCookie(cookie(self::COOKIE, $linkLocale, 525600, '/', null, $request->isSecure(), true, false, 'lax'));
        }

        return $response;
    }
}
