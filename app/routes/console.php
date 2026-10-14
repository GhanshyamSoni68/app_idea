<?php

use Illuminate\Support\Facades\Schedule;

// Scheduled work (docs/02 §7). The scheduler runs as one extra process beside the web app.
Schedule::command('accounts:purge')->hourly()->withoutOverlapping();
Schedule::command('housekeeping:purge')->hourly()->withoutOverlapping();
Schedule::command('generation:backfill')->hourly()->withoutOverlapping();
