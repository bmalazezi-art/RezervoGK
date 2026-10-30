<?php

namespace App\Support;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class GoalkeeperEligibility
{
    public static function approvedPeriods(Builder $periods): Builder
    {
        return $periods->whereHas('manualPayment', fn (Builder $payments) => $payments->where('status', 'approved'));
    }

    public static function eligibleUsers(Builder $users, ?CarbonImmutable $at = null): Builder
    {
        if (LaunchModel::isFree()) {
            return $users;
        }
        $at ??= GoalkeeperTrial::now();

        return $users->where(fn (Builder $access) => GoalkeeperTrial::eligibleUsers($access, $at)
            ->orWhereHas('subscriptions', fn (Builder $periods) => self::approvedPeriods($periods)
                ->where('starts_at', '<=', $at)->where('ends_at', '>', $at)));
    }

    public static function publicProfiles(Builder $profiles): Builder
    {
        return $profiles->where('is_published', true)->where('is_suspended', false)->where('admin_hidden', false)
            ->whereHas('user', fn (Builder $users) => self::eligibleUsers($users->where('is_suspended', false)));
    }

    public static function status(User $user): array
    {
        if (LaunchModel::isFree()) {
            $profile = $user->goalkeeperProfile;

            return ['isEligible' => $profile && self::publicProfiles($profile->newQuery())->whereKey($profile->id)->exists(),
                'freeLaunch' => true, 'profileStatus' => ! $profile ? 'unclaimed' : ($profile->is_suspended ? 'suspended' : ($profile->is_published ? 'published' : 'unpublished'))];
        }
        $now = GoalkeeperTrial::now();
        $period = self::approvedPeriods($user->subscriptions()->getQuery())->orderBy('ends_at', 'desc')->first();

        return ['isEligible' => self::eligibleUsers(User::whereKey($user->id), $now)->exists(),
            'subscriptionEndsAt' => $period?->ends_at->toIso8601String(),
            'subscriptionEndsAtLabel' => $period?->ends_at->setTimezone(config('marketplace.timezone'))->format(app()->getLocale() === 'en' ? 'd/m/Y H:i' : 'd.m.Y H:i'),
            'subscriptionStatus' => ! $period ? 'inactive' : ($period->ends_at->lessThanOrEqualTo($now) ? 'expired' : (self::approvedPeriods($user->subscriptions()->getQuery())->where('starts_at', '<=', $now)->where('ends_at', '>', $now)->exists() ? 'active' : 'scheduled'))];
    }
}
