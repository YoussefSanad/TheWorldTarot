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
| routine.
|
| **`daily()` is `0 0 * * *`, which needs an invocation inside the midnight
| minute itself.** The platform invokes every five minutes, so this runs if that
| cadence is aligned to the hour and has never run if it is not. See the note on
| the sweep below. It costs nothing either way, since it makes no request at all unless
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
| **The platform invokes `schedule:run` every five minutes**, measured on
| staging 26 August 2026 and carried as unknown since M4 before that. That is
| not the same as this task running every five minutes. `schedule:run` runs an
| event only when its expression matches the minute it was invoked, so the
| every-fifth-minute expression here fires when the platform's invocations land
| on minutes 0, 5, 10 and so on, and never at all if they land between. **Whether this environment's cadence is
| aligned is not yet known**, and until it is, run `orders:reconcile` by hand if
| a payment is known to be missing.
|
| Without overlapping, so a slow sweep never has a second one beside it asking
| Stripe the same questions.
*/
Schedule::command('orders:reconcile')
    ->everyFiveMinutes()
    ->withoutOverlapping();
