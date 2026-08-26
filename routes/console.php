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

/*
| Stripe's webhook is the quick way to learn a payment succeeded and not a
| certain one. This is the safety net under it: a notification lost in transit,
| or arriving mid-deploy, would otherwise leave somebody who has been charged
| holding a pending order until a person noticed.
|
| Every five minutes, decided 26 August 2026, and deliberately not the daily of
| the sweep above. Videos are uploaded rarely and payments arrive whenever
| people buy, so the cost of being late is a stuck card in one case and a
| customer who paid and received nothing in the other. Paired with the command's
| own fifteen minute window, a lost notification costs about twenty minutes.
|
| It costs close to nothing at that rate. The query is against our own orders
| table and makes no Stripe request at all unless something is genuinely
| outstanding, which is a number in the low tens.
|
| **The platform tick has never been measured, and this frequency is a ceiling
| rather than a floor.** It has been carried as unknown since M4. Task frequency
| lives here in code, so if Laravel Cloud runs `schedule:run` hourly then this
| is hourly whatever this line says, and if it runs daily then a lost
| notification costs a customer a day. Measuring it on a deployed environment is
| outstanding work and is not done: run `orders:reconcile` by hand in the
| meantime if a payment is known to be missing.
|
| Without overlapping, so a slow sweep never has a second one beside it asking
| Stripe the same questions.
*/
Schedule::command('orders:reconcile')
    ->everyFiveMinutes()
    ->withoutOverlapping();
