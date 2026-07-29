<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Bunny's transcode callback is the quick way to learn a video is ready, and not
| a certain one. This is what makes a lost callback cost five minutes instead of
| needing somebody to notice a card that never appeared.
|
| Without overlapping, so a slow sweep never has a second one beside it asking
| the same questions.
*/
Schedule::command('cards:reconcile-videos')
    ->everyFiveMinutes()
    ->withoutOverlapping();
