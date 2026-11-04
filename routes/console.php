<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Expired tokens are already refused; this only clears the rows.
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// The app's own DocuSign emails: the next signer, reminders, completion.
// The safety net for a signer who closes DocuSign without coming back. Not
// more often: DocuSign allows polling an envelope once per 15 minutes.
Schedule::command('agreements:follow-up')->everyFifteenMinutes()->withoutOverlapping();
