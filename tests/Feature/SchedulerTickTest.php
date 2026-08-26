<?php

use Illuminate\Support\Facades\Cache;

/**
 * The measurement's own proof. It is a temporary command, and it still gets
 * tests, because a heartbeat that silently records nothing reports exactly what
 * a platform that never ticks reports.
 */
beforeEach(function () {
    Cache::forget('scheduler-ticks:'.app()->environment());
});

it('records the moment it ran', function () {
    $this->artisan('scheduler:check')->assertSuccessful();

    expect(Cache::get('scheduler-ticks:'.app()->environment()))->toHaveCount(1);
});

it('keeps a tick across many runs, since the gaps are the measurement', function () {
    $this->artisan('scheduler:check')->assertSuccessful();
    $this->artisan('scheduler:check')->assertSuccessful();
    $this->artisan('scheduler:check')->assertSuccessful();

    expect(Cache::get('scheduler-ticks:'.app()->environment()))->toHaveCount(3);
});

it('bounds what it keeps, so a forgotten heartbeat cannot grow forever', function () {
    Cache::forever(
        'scheduler-ticks:'.app()->environment(),
        array_fill(0, 240, now()->toIso8601String())
    );

    $this->artisan('scheduler:check')->assertSuccessful();

    expect(Cache::get('scheduler-ticks:'.app()->environment()))->toHaveCount(240);
});

it('reports nothing recorded as a failure rather than as a healthy tick', function () {
    $this->artisan('scheduler:check --report')
        ->expectsOutputToContain('Nothing has been recorded.')
        ->assertFailed();
});

it('refuses to call one tick an interval', function () {
    $this->artisan('scheduler:check')->assertSuccessful();

    $this->artisan('scheduler:check --report')
        ->expectsOutputToContain('One tick is not an interval.')
        ->assertFailed();
});

it('measures the interval from the gaps between ticks', function () {
    $start = now()->startOfHour();

    Cache::forever('scheduler-ticks:'.app()->environment(), [
        $start->toIso8601String(),
        $start->copy()->addMinutes(5)->toIso8601String(),
        $start->copy()->addMinutes(10)->toIso8601String(),
        $start->copy()->addMinutes(70)->toIso8601String(),
        $start->copy()->addMinutes(75)->toIso8601String(),
    ]);

    $this->artisan('scheduler:check --report')
        ->expectsOutputToContain('ticks about every 5 minutes');
});

it('says a slow tick makes every frequency in the schedule a ceiling', function () {
    $start = now()->startOfDay();
    $ticks = [];

    for ($i = 0; $i <= 3; $i++) {
        $ticks[] = $start->copy()->addHours($i)->toIso8601String();
    }

    Cache::forever('scheduler-ticks:'.app()->environment(), $ticks);

    $this->artisan('scheduler:check --report')
        ->expectsOutputToContain('slower than a minute');
});

it('will not call a measurement conclusive before it has watched for an hour', function () {
    $start = now()->startOfHour();

    Cache::forever('scheduler-ticks:'.app()->environment(), [
        $start->toIso8601String(),
        $start->copy()->addMinute()->toIso8601String(),
        $start->copy()->addMinutes(2)->toIso8601String(),
    ]);

    $this->artisan('scheduler:check --report')
        ->expectsOutputToContain('less than an hour')
        ->assertFailed();
});

it('throws the series away on request, so a measurement can be restarted', function () {
    $this->artisan('scheduler:check')->assertSuccessful();
    $this->artisan('scheduler:check --forget')->assertSuccessful();

    expect(Cache::get('scheduler-ticks:'.app()->environment()))->toBeNull();
});
