<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 8:00 AM Philippine time (the app itself runs on UTC).
Schedule::command('members:send-expiry-reminders')->dailyAt('08:00')->timezone('Asia/Manila');
