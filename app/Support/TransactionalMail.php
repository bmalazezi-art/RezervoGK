<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Throwable;

class TransactionalMail
{
    public static function url(string $route, array $parameters = []): string
    {
        return rtrim((string) config('app.url'), '/').route($route, $parameters, false);
    }

    public function configurationIssue(): ?string
    {
        if (! config('transactional-mail.enabled')) {
            return 'disabled';
        }
        $arrayTest = app()->runningUnitTests() && config('mail.default') === 'array';
        $smtp = config('mail.mailers.smtp', []);
        $local = config('transactional-mail.qa_local_smtp') && app()->environment(['local', 'testing'])
            && in_array($smtp['host'] ?? '', ['127.0.0.1', 'localhost', '::1'], true);
        $url = parse_url((string) config('app.url'));
        if (! $url || empty($url['host']) || isset($url['user']) || isset($url['pass'])
            || isset($url['query']) || isset($url['fragment']) || preg_match('/[\s<>"\x00-\x1f]/', (string) config('app.url'))
            || (! $arrayTest && ! ($local && in_array($url['host'], ['127.0.0.1', 'localhost', '::1'], true)) && ($url['scheme'] ?? '') !== 'https')
            || ! in_array($url['scheme'] ?? '', ['http', 'https'], true)) {
            return 'canonical_url';
        }
        $from = (string) config('mail.from.address');
        if (! filter_var($from, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', (string) config('mail.from.name'))
            || (! $arrayTest && ! $local && preg_match('/@(example\.(com|org|net)|[^@]+\.(test|invalid|localhost))$/i', $from))) {
            return 'sender';
        }
        if ($arrayTest) {
            return null;
        }
        if (config('mail.default') !== 'smtp' || ($smtp['transport'] ?? null) !== 'smtp' || ! empty($smtp['url'])) {
            return 'smtp_required';
        }
        if (empty($smtp['host']) || ! preg_match('/^[a-zA-Z0-9.:-]+$/D', $smtp['host'])
            || ! in_array($smtp['scheme'] ?? null, [null, 'smtp', 'smtps'], true)
            || ! filter_var($smtp['port'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]])
            || ($smtp['timeout'] ?? 0) < 1 || ($smtp['timeout'] ?? 0) > 30) {
            return 'smtp_settings';
        }
        if (! $local && (blank($smtp['username'] ?? null) || blank($smtp['password'] ?? null)
            || ! ($smtp['require_tls'] ?? false) || ! ($smtp['auto_tls'] ?? true)
            || ($smtp['verify_peer'] ?? true) !== true)) {
            return 'smtp_security';
        }
        if (! $local && in_array($smtp['host'], ['127.0.0.1', 'localhost', '::1'], true)) {
            return 'local_smtp_requires_qa';
        }

        return null;
    }

    public static function recordFailure(string $kind): void
    {
        // Never log the exception, recipient, request, SMTP response, body or token.
        try {
            Log::warning('Transactional email unavailable.', ['kind' => $kind]);
        } catch (Throwable) {
            // A logging outage must not expose mail data through an exception page.
        }
    }

    public function sendReset(User $user, #[\SensitiveParameter] string $token): void
    {
        try {
            if ($this->configurationIssue() !== null) {
                self::recordFailure('password_reset_configuration');
                Password::deleteToken($user);

                return;
            }
            $user->notify((new ResetPassword($token))->locale(app()->getLocale()));
        } catch (Throwable) {
            self::recordFailure('password_reset_delivery');
            // A failed delivery must allow a fresh request, not keep an unusable token.
            Password::deleteToken($user);
        }
    }
}
