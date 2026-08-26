<?php

use Illuminate\Support\Facades\Cache;

function tickKey(): string
{
    return 'scheduler-ticks:'.parse_url((string) config('app.url'), PHP_URL_HOST);
}

/**
 * The key the command wrote to before 26 August 2026, when it was named after
 * the environment rather than the host.
 */
function legacyTickKey(): string
{
    return 'scheduler-ticks:'.app()->environment();
}

/**
 * The measurement's own proof. It is a temporary command, and it still gets
 * tests, because a heartbeat that silently records nothing reports exactly what
 * a platform that never ticks reports.
 */
beforeEach(function () {
    Cache::forget(tickKey());
    Cache::forget(legacyTickKey());
});

it('records the moment it ran', function () {
    $this->artisan('scheduler:check')->assertSuccessful();

    expect(Cache::get(tickKey()))->toHaveCount(1);
});

it('keeps a tick across many runs, since the gaps are the measurement', function () {
    $this->artisan('scheduler:check')->assertSuccessful();
    $this->artisan('scheduler:check')->assertSuccessful();
    $this->artisan('scheduler:check')->assertSuccessful();

    expect(Cache::get(tickKey()))->toHaveCount(3);
});

it('bounds what it keeps, so a forgotten heartbeat cannot grow forever', function () {
    Cache::forever(
        tickKey(),
        array_fill(0, 240, now()->toIso8601String())
    );

    $this->artisan('scheduler:check')->assertSuccessful();

    expect(Cache::get(tickKey()))->toHaveCount(240);
});

it('reports nothing recorded as a failure rather than as a healthy tick', function () {
    $this->artisan('scheduler:check --report')
        ->expectsOutputToContain('Nothing has been recorded, under either key.')
        ->expectsOutputToContain(legacyTickKey())
        ->assertFailed();
});

it('refuses to call one tick an interval', function () {
    $this->artisan('scheduler:check')->assertSuccessful();

    $this->artisan('scheduler:check --report')
        ->expectsOutputToContain('One tick is not an interval.')
        ->assertFailed();
});

it('measures the interval from the gaps between ticks', function () {
    $start = now()->subMinutes(75);

    Cache::forever(tickKey(), [
        $start->copy()->toIso8601String(),
        $start->copy()->addMinutes(5)->toIso8601String(),
        $start->copy()->addMinutes(10)->toIso8601String(),
        $start->copy()->addMinutes(70)->toIso8601String(),
        $start->copy()->addMinutes(75)->toIso8601String(),
    ]);

    $this->artisan('scheduler:check --report')
        ->expectsOutputToContain('ticks about every 5 minutes');
});

it('says a slow tick makes every frequency in the schedule a ceiling', function () {
    // Ending at now, because a series that stops in the past is a stopped
    // heartbeat and the report says so before it says anything about rate.
    $ticks = [];

    for ($i = 3; $i >= 0; $i--) {
        $ticks[] = now()->subHours($i)->toIso8601String();
    }

    Cache::forever(tickKey(), $ticks);

    $this->artisan('scheduler:check --report')
        ->expectsOutputToContain('slower than a minute');
});

it('will not call a measurement conclusive before it has watched for an hour', function () {
    Cache::forever(tickKey(), [
        now()->subMinutes(2)->toIso8601String(),
        now()->subMinute()->toIso8601String(),
        now()->toIso8601String(),
    ]);

    $this->artisan('scheduler:check --report')
        ->expectsOutputToContain('less than an hour')
        ->assertFailed();
});

it('throws the series away on request, so a measurement can be restarted', function () {
    $this->artisan('scheduler:check')->assertSuccessful();
    $this->artisan('scheduler:check --forget')->assertSuccessful();

    expect(Cache::get(tickKey()))->toBeNull();
});

it('calls a heartbeat that has gone quiet what it is, rather than waiting for a second tick', function () {
    Cache::forever(tickKey(), [
        now()->subHour()->toIso8601String(),
    ]);

    $this->artisan('scheduler:check --report')
        ->expectsOutputToContain('has gone quiet')
        ->assertFailed();
});

it('names the target as well as the environment, since every Laravel Cloud environment says production', function () {
    $this->artisan('scheduler:check')->assertSuccessful();

    $this->artisan('scheduler:check --report')
        ->expectsOutputToContain((string) config('app.url'));
});

it('separates one environment from another by host, since every Laravel Cloud environment says production', function () {
    config(['app.url' => 'https://staging-api.theworldtarot.com']);
    $this->artisan('scheduler:check')->assertSuccessful();

    config(['app.url' => 'https://api.theworldtarot.com']);
    $this->artisan('scheduler:check')->assertSuccessful();

    expect(Cache::get('scheduler-ticks:staging-api.theworldtarot.com'))->toHaveCount(1)
        ->and(Cache::get('scheduler-ticks:api.theworldtarot.com'))->toHaveCount(1);
});

/*
| The key changed from the environment to the host on 26 August 2026. These
| two are about the rename itself rather than about the scheduler, and they go
| when the command does.
*/

it('reads a series left under the old environment key, rather than calling the platform dead', function () {
    Cache::forever(legacyTickKey(), [
        now()->subMinutes(2)->toIso8601String(),
        now()->subMinute()->toIso8601String(),
        now()->toIso8601String(),
    ]);

    $this->artisan('scheduler:check --report')
        ->doesntExpectOutputToContain('Nothing has been recorded')
        ->expectsOutputToContain(legacyTickKey());
});

it('moves an adopted series onto the host key at the next tick, leaving no second copy', function () {
    Cache::forever(legacyTickKey(), [
        now()->subMinute()->toIso8601String(),
    ]);

    $this->artisan('scheduler:check')->assertSuccessful();

    expect(Cache::get(tickKey()))->toHaveCount(2)
        ->and(Cache::get(legacyTickKey()))->toBeNull();
});
