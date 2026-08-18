<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * READINESS GATES — RECOMPUTED DAILY.
 *
 * Without this, ReadinessGateRecomputer is never called and every gate keeps
 * answering with whenever it was last computed by hand. That is exactly how
 * "My Capability" reported 0% coverage for a tenant sitting at 78.9%.
 *
 * DAILY, NOT HOURLY, and the reason is the design: gates carry
 * `sustained_periods` — a value must hold across N consecutive computations
 * before the gate opens. One run per day makes "three consecutive passes" mean
 * three days of holding, which is a meaningful claim. Running hourly would make
 * it three hours, which is not.
 *
 * withoutOverlapping() because a slow run must not have a second one start
 * behind it and advance the counter twice for the same period.
 */
Schedule::command('readiness:recompute --quiet-summary')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer();
