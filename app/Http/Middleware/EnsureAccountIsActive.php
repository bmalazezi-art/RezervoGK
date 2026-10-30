<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    public function handle(Request $request, \Closure $next): Response
    {
        if ($request->user()?->is_suspended) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            Inertia::clearHistory();

            return redirect()->route('login')->withErrors(['email' => __('Llogaria nuk mund të përdoret. Kontakto mbështetjen.')]);
        }

        return $next($request);
    }
}
