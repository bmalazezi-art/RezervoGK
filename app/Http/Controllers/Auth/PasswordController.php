<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\TransactionalMail;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PasswordController extends Controller
{
    public function request(): Response
    {
        return Inertia::render('ForgotPassword');
    }

    public function email(Request $request): RedirectResponse
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:254']]);
        try {
            Password::sendResetLink($data);
        } catch (\Throwable) {
            TransactionalMail::recordFailure('password_reset_request');
        }

        // Do not reveal whether this email address has an account.
        return back()->with('status', __('Nëse ky email ka llogari, do të marrësh një lidhje për rivendosjen e fjalëkalimit.'));
    }

    public function reset(Request $request, string $token): Response
    {
        Inertia::encryptHistory();

        return Inertia::render('ResetPassword', [
            'token' => $token, 'email' => is_string($request->query('email')) ? $request->query('email') : '',
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:254'],
            'password' => ['bail', 'required', 'string', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers(),
                function ($attribute, $value, $fail) {
                    if (strlen($value) > 72) {
                        $fail(__('Fjalëkalimi duhet të ketë deri në 72 bajte.'));
                    }
                }],
        ]);
        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __('Lidhja e rivendosjes është e pavlefshme ose ka skaduar.')]);
        }

        Inertia::clearHistory();

        return to_route('login')->with('status', __('Fjalëkalimi u ndryshua. Kyçu me fjalëkalimin e ri.'));
    }
}
