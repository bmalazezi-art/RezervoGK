<?php

namespace App\Providers;

use App\Models\GoalkeeperProfile;
use App\Models\User;
use App\Policies\GoalkeeperProfilePolicy;
use App\Support\TransactionalMail;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Recovery tokens must never be sent to an origin supplied by a request.
        ResetPassword::createUrlUsing(fn ($user, string $token) => TransactionalMail::url('password.reset', [
            'token' => $token, 'email' => $user->getEmailForPasswordReset(), 'locale' => app()->getLocale(),
        ]));
        ResetPassword::toMailUsing(function ($user, string $token) {
            $url = TransactionalMail::url('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset(), 'locale' => app()->getLocale()]);

            return (new MailMessage)->subject(__('Njoftim për rivendosjen e fjalëkalimit'))
                ->action(__('Rivendos fjalëkalimin'), $url)
                ->view(['html' => 'mail.password-reset', 'text' => 'mail.password-reset-text'], [
                    'title' => __('Rivendos fjalëkalimin'), 'preheader' => __('Lidhje e përkohshme për rivendosjen e fjalëkalimit.'),
                    'minutes' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire'),
                    'actionText' => __('Rivendos fjalëkalimin'), 'actionUrl' => $url,
                ]);
        });

        Gate::define('review-payments', fn (User $user) => $user->is_admin);
        Gate::define('review-reports', fn (User $user) => $user->is_admin);
        Gate::define('manage-admin-access', fn (User $user) => $user->isSuperAdmin() && ! $user->is_suspended);
        Gate::define('manage-system-settings', fn (User $user) => $user->isSuperAdmin() && ! $user->is_suspended);
        Gate::policy(GoalkeeperProfile::class, GoalkeeperProfilePolicy::class);
        RateLimiter::for('registration', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('profile-reports', fn (Request $request) => Limit::perHour(5)->by(hash('sha256', (string) $request->ip())));
        foreach (['login', 'password-email', 'password-reset'] as $name) {
            RateLimiter::for($name, function (Request $request) {
                $email = $request->input('email');
                $email = is_string($email) ? mb_strtolower(trim($email)) : '';

                return [
                    Limit::perMinute(20)->by('ip:'.hash('sha256', $request->ip())),
                    Limit::perMinute(5)->by('account:'.hash('sha256', $email.'|'.$request->ip())),
                ];
            });
        }
    }
}
