<?php

namespace App\Support;

class LaunchModel
{
    public static function isFree(): bool
    {
        // Fail closed. Reactivation requires a deliberate code/config release, not bank/SMTP env values.
        return config('marketplace.free_launch', true) !== false;
    }

    public static function paymentPath(string $path): bool
    {
        $path = ltrim($path, '/');

        return $path === 'payments' || str_starts_with($path, 'payments/')
            || $path === 'admin/payments' || str_starts_with($path, 'admin/payments/');
    }
}
