<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AdminLoginChallenge;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AdminVerificationController extends Controller
{
    public function show(Request $request, AdminLoginChallenge $challenge): Response|RedirectResponse
    {
        if (! $challenge->active($request)) {
            $challenge->clear($request);

            return to_route('admin.login')->with('status', __('Kodi ka skaduar. Kyçu përsëri.'));
        }
        $email = (string) User::whereKey($challenge->state($request)['user_id'])->value('email');

        return Inertia::render('AdminVerify', ['email' => self::mask($email), 'minutes' => (int) config('admin-security.code_minutes')]);
    }

    public function verify(Request $request, AdminLoginChallenge $challenge): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'regex:/^\s*\d{3}\s?\d{3}\s*$/']], [
            'code.regex' => __('Shkruaj kodin 6-shifror nga email-i.'),
        ]);
        $state = $challenge->state($request);
        $result = $challenge->check($request, preg_replace('/\D/', '', $data['code']));

        if ($result === 'expired') {
            return to_route('admin.login')->with('status', __('Kodi ka skaduar. Kyçu përsëri.'));
        }
        if ($result === 'locked') {
            return to_route('admin.login')->with('status', __('Shumë përpjekje të gabuara. Kyçu përsëri për një kod të ri.'));
        }
        if ($result === 'wrong') {
            return back()->withErrors(['code' => __('Kodi nuk është i saktë.')]);
        }

        $user = User::find($state['user_id']);
        $challenge->clear($request);
        if (! $user || ! $user->is_admin || $user->is_suspended) {
            return to_route('admin.login');
        }
        Auth::login($user, (bool) ($state['remember'] ?? false));
        $request->session()->regenerate();
        Inertia::clearHistory();

        return redirect()->intended(route('admin.dashboard'));
    }

    public function resend(Request $request, AdminLoginChallenge $challenge): RedirectResponse
    {
        if (! $challenge->state($request)) {
            return to_route('admin.login');
        }
        if (! $challenge->canResend($request)) {
            return back()->withErrors(['code' => __('Prit pak para se të kërkosh një kod të ri.')]);
        }
        if (! $challenge->send($request)) {
            return to_route('admin.login')->with('status', __('Kodi nuk mund të dërgohej. Provo përsëri pas pak.'));
        }

        return back()->with('status', __('U dërgua një kod i ri.'));
    }

    private static function mask(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return Str::substr($name, 0, 1).str_repeat('•', max(2, min(6, Str::length($name) - 1))).'@'.$domain;
    }
}
