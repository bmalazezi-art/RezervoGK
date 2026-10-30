<?php

use App\Support\LaunchModel;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The deployment scheduler runs this once per minute; SMTP remains opt-in.
if (! LaunchModel::isFree()) {
    Schedule::command('mail:deliver-transfers')->everyMinute()->withoutOverlapping(15);
}

Schedule::command('account-deletion:cleanup-files')->everyMinute()->withoutOverlapping(5);
