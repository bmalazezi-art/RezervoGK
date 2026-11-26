<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use App\Support\Locales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $locale = $request->validate(['locale' => ['required', 'string', Rule::in(Locales::all())]])['locale'];

        // Independent of authentication: session rotation/logout cannot erase it.
        return response()->json(['locale' => $locale])->withCookie(
            cookie(SetLocale::COOKIE, $locale, 525600, '/', null, $request->isSecure(), true, false, 'lax')
        );
    }
}
