<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

/** Server-side (session) state for the emailed admin sign-in code. Only a keyed hash of the code is stored. */
class AdminLoginChallenge
{
    private const KEY = 'admin_login_challenge';

    public function __construct(private TransactionalMail $mail) {}

    public static function required(User $user): bool
    {
        return $user->is_admin && (bool) config('admin-security.email_code');
    }

    public function start(Request $request, User $user, bool $remember): bool
    {
        $request->session()->put(self::KEY, ['user_id' => $user->id, 'remember' => $remember]);

        return $this->send($request);
    }

    public function send(Request $request): bool
    {
        $state = $this->state($request);
        $user = $state ? User::find($state['user_id']) : null;
        if (! $user) {
            $this->clear($request);

            return false;
        }
        $code = (string) random_int(100000, 999999);
        $minutes = (int) config('admin-security.code_minutes');
        $request->session()->put(self::KEY, [
            ...$state,
            'code_hash' => $this->hash($code),
            'expires_at' => now()->addMinutes($minutes)->getTimestamp(),
            'sent_at' => now()->getTimestamp(),
            'attempts' => 0,
        ]);

        if (! $this->mail->sendAdminCode($user, $code, $minutes)) {
            $this->clear($request);

            return false;
        }

        return true;
    }

    public function state(Request $request): ?array
    {
        $state = $request->session()->get(self::KEY);

        return is_array($state) && isset($state['user_id']) ? $state : null;
    }

    public function active(Request $request): bool
    {
        $state = $this->state($request);

        return $state !== null && isset($state['expires_at']) && $state['expires_at'] >= now()->getTimestamp();
    }

    public function canResend(Request $request): bool
    {
        $state = $this->state($request);

        return $state !== null && now()->getTimestamp() - ($state['sent_at'] ?? 0) >= (int) config('admin-security.resend_after_seconds');
    }

    /** @return 'ok'|'wrong'|'locked'|'expired' */
    public function check(Request $request, string $code): string
    {
        $state = $this->state($request);
        if (! $state || ! $this->active($request)) {
            $this->clear($request);

            return 'expired';
        }
        $state['attempts'] = ($state['attempts'] ?? 0) + 1;
        if (hash_equals($state['code_hash'], $this->hash($code))) {
            return 'ok';
        }
        if ($state['attempts'] >= (int) config('admin-security.max_attempts')) {
            $this->clear($request);

            return 'locked';
        }
        $request->session()->put(self::KEY, $state);

        return 'wrong';
    }

    public function clear(Request $request): void
    {
        $request->session()->forget(self::KEY);
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
