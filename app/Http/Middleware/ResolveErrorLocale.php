<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveErrorLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        // Route-miss/405/early-CSRF errors may occur before the web middleware.
        // Read only this authenticated cookie, using Laravel's cookie prefix/key rotation rules.
        $locale = null;
        $cookie = $request->cookie(SetLocale::COOKIE);
        if (is_string($cookie)) {
            try {
                $encrypter = app('encrypter');
                $locale = CookieValuePrefix::validate(SetLocale::COOKIE, $encrypter->decrypt($cookie, false), $encrypter->getAllKeys());
            } catch (DecryptException) {
                // Invalid cookies cannot select a locale or expose exception data.
            }
        }
        app()->setLocale(in_array($locale, ['sq', 'en'], true) ? $locale : 'sq');

        return $next($request);
    }
}
