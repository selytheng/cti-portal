<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Scheduled feed polling (spec Section 4): every five minutes, dispatch
// intelligence queries for active feeds whose polling interval elapsed.
Schedule::command('cti:poll-feeds')->everyFiveMinutes();
