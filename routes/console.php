<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Scheduled commands, run by the `scheduler` container (`php artisan schedule:work`; see
| docker-compose.yml). Hourly, at half past:
|
| - The ECB publishes its reference rates once a working day (about 16:00 CET); an hourly refresh
|   picks the new rate up within the hour, and a run that fails is retried an hour later. Rates
|   are stored once per publication date, so the extra runs add nothing.
| - Stale credit holds (left by a worker that died mid-step) are returned within the hour.
*/
Schedule::command('boschifai:refresh-exchange-rates')->hourlyAt(30)->withoutOverlapping();

Schedule::command('boschifai:release-stale-holds')->hourlyAt(30)->withoutOverlapping();

// Credits past their 36-month validity, and the 60/14-day reminder emails (D6).
Schedule::command('boschifai:expire-credits')->hourlyAt(30)->withoutOverlapping();
