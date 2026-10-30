<?php

namespace App\Support;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class GoalkeeperTrial
{
    public const SECONDS = 30 * 24 * 60 * 60;

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    public static function start(User $user): void
    {
        $now = self::now()->startOfSecond();
        // A conditional write is idempotent, including concurrent calls. Never renew a trial.
        User::whereKey($user->getKey())->whereNull('trial_started_at')->whereNull('trial_ends_at')->update([
            'trial_started_at' => $now, 'trial_ends_at' => $now->addSeconds(self::SECONDS),
        ]);
        $user->refresh();
    }

    public static function eligibleUsers(Builder $users, ?CarbonImmutable $at = null): Builder
    {
        $at ??= self::now();

        return $users->where('trial_started_at', '<=', $at)->where('trial_ends_at', '>', $at);
    }

    public static function publicProfiles(Builder $profiles, ?CarbonImmutable $at = null): Builder
    {
        return $profiles->whereHas('user', fn (Builder $users) => GoalkeeperEligibility::eligibleUsers($users, $at));
    }

    public static function status(User $user): array
    {
        $now = self::now();
        $active = self::eligibleUsers(User::whereKey($user->getKey()), $now)->exists();
        $ends = $user->trial_ends_at;

        return [
            'isEligible' => $active,
            'remainingDays' => $active ? (int) ceil($now->diffInSeconds($ends, false) / 86400) : 0,
            'startedAt' => $user->trial_started_at?->toIso8601String(),
            'endsAt' => $ends?->toIso8601String(),
            'endsAtLabel' => $ends?->setTimezone(config('marketplace.timezone'))->format(app()->getLocale() === 'en' ? 'd/m/Y H:i' : 'd.m.Y H:i'),
            'status' => $active ? 'active' : ($ends && $ends->lessThanOrEqualTo($now) ? 'expired' : 'not_started'),
        ];
    }
}
