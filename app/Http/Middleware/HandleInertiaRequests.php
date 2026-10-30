<?php

namespace App\Http\Middleware;

use App\Models\PlatformSetting;
use App\Support\LaunchModel;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'locale' => app()->getLocale(),
            'freeLaunch' => LaunchModel::isFree(),
            'platformAnnouncement' => fn () => (string) PlatformSetting::value('announcement', ''),
            'auth' => fn () => ['user' => $request->user()?->only(['id', 'name', 'email']), 'canReviewPayments' => ! LaunchModel::isFree() && ($request->user()?->can('review-payments') ?? false), 'canReviewReports' => $request->user()?->can('review-reports') ?? false, 'canManageSystem' => $request->user()?->can('manage-system-settings') ?? false],
            'flash' => fn () => ['status' => $request->session()->get('status')],
        ];
    }
}
