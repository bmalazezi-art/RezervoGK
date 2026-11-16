<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AdminLoginChallenge;
use App\Support\LaunchModel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Login');
    }

    public function createAdmin(Request $request): Response
    {
        // Admins land in the admin panel after signing in from here.
        $request->session()->put('url.intended', route('admin.dashboard'));

        return Inertia::render('Login', ['admin' => true]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['bail', 'required', 'string',
                function ($attribute, $value, $fail) {
                    if (strlen($value) > 72) {
                        $fail(__('Email-i ose fjalëkalimi nuk është i saktë.'));
                    }
                }],
            'remember' => ['sometimes', 'boolean'],
        ]);
        $user = User::where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => __('Email-i ose fjalëkalimi nuk është i saktë.')]);
        }
        if ($user->is_suspended) {
            throw ValidationException::withMessages(['email' => __('Llogaria juaj është pezulluar. Ju lutemi kontaktoni mbështetjen nëse mendoni se ky është një gabim.')]);
        }
        $intended = $request->session()->get('url.intended');
        if (! $user->is_admin && is_string($intended) && str_starts_with((string) parse_url($intended, PHP_URL_PATH), '/admin')) {
            // A goalkeeper who signed in from the admin login goes to their own dashboard, not a 403.
            $request->session()->forget('url.intended');
        }
        if (AdminLoginChallenge::required($user)) {
            // Password was right; admins still need the emailed code before they are signed in.
            $request->session()->regenerate();
            if (! app(AdminLoginChallenge::class)->start($request, $user, $request->boolean('remember'))) {
                throw ValidationException::withMessages(['email' => __('Kodi i verifikimit nuk mund të dërgohej. Provo përsëri pas pak.')]);
            }

            return to_route('admin.verify');
        }
        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        // Drop signed-out pages from browser history so Back cannot show the
        // login form to a signed-in user; it reloads from the server instead.
        Inertia::clearHistory();

        if (LaunchModel::isFree()) {
            $intended = $request->session()->get('url.intended');
            if (is_string($intended) && LaunchModel::paymentPath((string) parse_url($intended, PHP_URL_PATH))) {
                $request->session()->forget('url.intended');
            }

            if ($request->user()->is_admin) {
                return redirect()->intended(route('admin.dashboard'));
            }

            return redirect()->intended(route($request->user()->goalkeeperProfile ? 'dashboard' : 'home'));
        }

        return redirect()->intended(route($request->user()->is_admin ? 'admin.payments' : 'dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Inertia::clearHistory();

        return to_route('home');
    }
}
