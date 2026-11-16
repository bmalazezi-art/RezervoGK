<?php

use App\Http\Controllers\AccountDeletionController;
use App\Http\Controllers\AccountSettingsController;
use App\Http\Controllers\AdminAnalyticsController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AdminExportController;
use App\Http\Controllers\AdminGoalkeeperReportController;
use App\Http\Controllers\AdminGoalkeepersController;
use App\Http\Controllers\AdminHealthController;
use App\Http\Controllers\AdminInsightsController;
use App\Http\Controllers\AdminPaymentController;
use App\Http\Controllers\AdminSystemController;
use App\Http\Controllers\AdminUsersController;
use App\Http\Controllers\Auth\AdminVerificationController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\RegistrationController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GoalkeeperController;
use App\Http\Controllers\GoalkeeperReportController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\ManualPaymentController;
use App\Http\Controllers\ProfileAnalyticsController;
use App\Http\Controllers\ProfilePhotoController;
use App\Http\Controllers\WeeklyHoursController;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureRegistrationIsEnabled;
use App\Http\Middleware\ProtectRecoveryResponse;
use Illuminate\Support\Facades\Route;

Route::pattern('payment', '[1-9][0-9]*');
Route::post('/locale', LocaleController::class)->middleware('throttle:60,1')->name('locale.update');

Route::get('/', HomeController::class)->name('home');
Route::get('/browse', [GoalkeeperController::class, 'index'])->name('browse');
Route::redirect('/keepers', '/browse')->name('keepers.index');
Route::get('/p/{slug}', [GoalkeeperController::class, 'showBySlug'])
    ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')->name('keepers.public');
Route::get('/keepers/{keeper}', [GoalkeeperController::class, 'show'])
    ->where('keeper', '[1-9][0-9]*')->name('keepers.show');
Route::post('/keepers/{keeper}/analytics', [ProfileAnalyticsController::class, 'store'])
    ->where('keeper', '[1-9][0-9]*')->name('keepers.analytics.store');
Route::post('/keepers/{keeper}/reports', [GoalkeeperReportController::class, 'store'])
    ->where('keeper', '[1-9][0-9]*')->middleware('throttle:profile-reports')->name('keepers.reports.store');
Route::redirect('/pricing', '/');
Route::inertia('/how-it-works', 'Information', ['pageKey' => 'howItWorks'])->name('how-it-works');
Route::inertia('/faq', 'Information', ['pageKey' => 'faq'])->name('faq');
Route::inertia('/contact', 'Information', ['pageKey' => 'contact'])->name('contact');
Route::inertia('/privacy', 'Information', ['pageKey' => 'privacy'])->name('privacy');
Route::inertia('/terms', 'Information', ['pageKey' => 'terms'])->name('terms');

Route::middleware('guest')->group(function () {
    Route::get('/register', [RegistrationController::class, 'create'])->middleware(EnsureRegistrationIsEnabled::class)->name('register');
    Route::post('/register', [RegistrationController::class, 'store'])->middleware(['throttle:registration', EnsureRegistrationIsEnabled::class])->name('register.store');
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::get('/admin/login', [SessionController::class, 'createAdmin'])->name('admin.login');
    Route::get('/admin/verify', [AdminVerificationController::class, 'show'])->name('admin.verify');
    Route::post('/admin/verify', [AdminVerificationController::class, 'verify'])->middleware('throttle:10,1')->name('admin.verify.store');
    Route::post('/admin/verify/resend', [AdminVerificationController::class, 'resend'])->middleware('throttle:3,10')->name('admin.verify.resend');
    Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:login')->name('login.store');
    Route::middleware(ProtectRecoveryResponse::class)->group(function () {
        Route::get('/forgot-password', [PasswordController::class, 'request'])->name('password.request');
        Route::post('/forgot-password', [PasswordController::class, 'email'])->middleware('throttle:password-email')->name('password.email');
        Route::get('/reset-password/{token}', [PasswordController::class, 'reset'])->name('password.reset');
        Route::post('/reset-password', [PasswordController::class, 'update'])->middleware('throttle:password-reset')->name('password.update');
    });
});

Route::middleware(['auth', 'auth.session', EnsureAccountIsActive::class, 'cache.headers:no_store;private'])->group(function () {
    Route::post('/payments', [ManualPaymentController::class, 'store'])->middleware('throttle:10,1')->name('payments.store');
    Route::post('/payments/{payment}/submit', [ManualPaymentController::class, 'submit'])->middleware('throttle:10,1')->name('payments.submit');
    Route::get('/payments/{payment}/receipt', [ManualPaymentController::class, 'receipt'])->name('payments.receipt');
    Route::middleware('can:review-payments')->prefix('admin')->group(function () {
        Route::get('/payments', [AdminPaymentController::class, 'index'])->name('admin.payments');
        Route::post('/payments/{payment}/review', [AdminPaymentController::class, 'review'])->middleware('throttle:30,1')->name('admin.payments.review');
    });
    Route::middleware('can:review-reports')->prefix('admin')->group(function () {
        Route::get('/', AdminDashboardController::class)->name('admin.dashboard');
        Route::get('/analytics', AdminAnalyticsController::class)->name('admin.analytics');
        Route::get('/users', AdminUsersController::class)->name('admin.users');
        Route::get('/users/{user}', [AdminUsersController::class, 'show'])->whereNumber('user')->name('admin.users.show');
        Route::post('/users/{user}/password-reset', [AdminUsersController::class, 'sendPasswordReset'])->whereNumber('user')->middleware('throttle:10,1')->name('admin.users.password-reset');
        Route::patch('/users/{user}/suspension', [AdminUsersController::class, 'updateSuspension'])->whereNumber('user')->middleware('throttle:20,1')->name('admin.users.suspension');
        Route::get('/goalkeepers', [AdminGoalkeepersController::class, 'index'])->name('admin.goalkeepers');
        Route::get('/goalkeepers/{profile}', [AdminGoalkeepersController::class, 'show'])->whereNumber('profile')->name('admin.goalkeepers.show');
        Route::patch('/goalkeepers/{profile}/highlight', [AdminGoalkeepersController::class, 'highlight'])->whereNumber('profile')->middleware('throttle:30,1')->name('admin.goalkeepers.highlight');
        Route::get('/insights', AdminInsightsController::class)->name('admin.insights');
        Route::patch('/goalkeepers/{profile}/moderation', [AdminGoalkeepersController::class, 'moderate'])->whereNumber('profile')->middleware('throttle:20,1')->name('admin.goalkeepers.moderate');
        Route::get('/reports', [AdminGoalkeeperReportController::class, 'index'])->name('admin.reports');
        Route::get('/reports/{report}', [AdminGoalkeeperReportController::class, 'show'])->whereNumber('report')->name('admin.reports.show');
        Route::patch('/reports/{report}', [AdminGoalkeeperReportController::class, 'update'])->whereNumber('report')->middleware('throttle:30,1')->name('admin.reports.update');
        Route::post('/reports/{report}/listing-moderation', [AdminGoalkeeperReportController::class, 'moderateListing'])->whereNumber('report')->middleware('throttle:20,1')->name('admin.reports.listing-moderation');
        Route::get('/moderation', [AdminGoalkeeperReportController::class, 'index'])->name('admin.moderation');
        Route::get('/moderation/{report}', [AdminGoalkeeperReportController::class, 'show'])->whereNumber('report')->name('admin.moderation.show');
        Route::patch('/moderation/{report}', [AdminGoalkeeperReportController::class, 'update'])->whereNumber('report')->middleware('throttle:30,1')->name('admin.moderation.update');
        Route::post('/moderation/{report}/listing-moderation', [AdminGoalkeeperReportController::class, 'moderateListing'])->whereNumber('report')->middleware('throttle:20,1')->name('admin.moderation.listing-moderation');
    });
    Route::middleware('can:manage-system-settings')->prefix('admin')->group(function () {
        Route::get('/settings', [AdminSystemController::class, 'index'])->name('admin.settings');
        Route::delete('/users/{user}', [AdminUsersController::class, 'destroy'])->whereNumber('user')->middleware('throttle:10,1')->name('admin.users.destroy');
        Route::get('/health', AdminHealthController::class)->name('admin.health');
        Route::get('/export/users.csv', [AdminExportController::class, 'users'])->middleware('throttle:10,1')->name('admin.export.users');
        Route::get('/export/goalkeepers.csv', [AdminExportController::class, 'goalkeepers'])->middleware('throttle:10,1')->name('admin.export.goalkeepers');
        Route::patch('/settings', [AdminSystemController::class, 'updateSettings'])->middleware('throttle:20,1')->name('admin.settings.update');
    });
    Route::middleware('can:manage-admin-access')->prefix('admin')->group(function () {
        Route::patch('/admins/{user}', [AdminSystemController::class, 'updateAdmin'])->whereNumber('user')->middleware('throttle:20,1')->name('admin.access.update');
    });
    Route::get('/dashboard', [DashboardController::class, 'show'])->name('dashboard');
    Route::delete('/account', AccountDeletionController::class)->name('account.destroy');
    Route::put('/account/password', [AccountSettingsController::class, 'updatePassword'])->name('account.password.update');
    Route::post('/keepers/{keeper}/photo', [ProfilePhotoController::class, 'update'])
        ->where('keeper', '[1-9][0-9]*')->middleware('throttle:20,1')->name('keepers.photo.update');
    Route::patch('/keepers/{keeper}', [DashboardController::class, 'update'])
        ->where('keeper', '[1-9][0-9]*')->name('keepers.update');
    Route::put('/keepers/{keeper}/weekly-hours', [WeeklyHoursController::class, 'update'])
        ->where('keeper', '[1-9][0-9]*')->name('weekly-hours.update');
    Route::post('/keepers/{keeper}/availability', [AvailabilityController::class, 'store'])->name('availability.store');
    Route::delete('/keepers/{keeper}/availability/{date}', [AvailabilityController::class, 'destroy'])->name('availability.destroy');
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');
});
