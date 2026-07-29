<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Bunny's transcode callback is the quick way to learn a video is ready, and not
| a certain one. This is the safety net under it: a callback lost in transit
| would otherwise leave a card at "being prepared" until somebody noticed.
|
| Daily, decided 29 July 2026. Videos are uploaded rarely, twenty-two of them
| once and then almost never, so the sweep exists for an exception rather than a
| routine. It costs nothing either way, since it makes no request at all unless
| something is actually in flight, and Bunny bills bandwidth rather than calls.
|
| The trade accepted: a lost callback now costs up to a day rather than five
| minutes. On a day when videos are being uploaded in a batch, run
| `php artisan cards:reconcile-videos` by hand rather than waiting.
|
| Without overlapping, so a slow sweep never has a second one beside it asking
| the same questions.
*/
Schedule::command('cards:reconcile-videos')
    ->daily()
    ->withoutOverlapping();
