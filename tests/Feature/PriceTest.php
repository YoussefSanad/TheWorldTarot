<?php

use App\Models\Price;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\PriceableThing;

uses(RefreshDatabase::class);

beforeEach(function () {
    // No real priceable model exists until M2, so the fixture gets a table for
    // the duration of the test. RefreshDatabase rolls it back afterwards.
    Schema::create('priceable_things', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    $this->thing = PriceableThing::create(['name' => 'A reading']);
});

it('resolves the amount for a requested currency', function () {
    $this->thing->setPriceIn('GBP', 1900);
    $this->thing->setPriceIn('USD', 2500);

    expect($this->thing->amountIn('GBP'))->toBe(1900)
        ->and($this->thing->amountIn('USD'))->toBe(2500);
});

it('returns null for a currency it has no price in, rather than converting', function () {
    $this->thing->setPriceIn('GBP', 1900);

    // A missing currency means not for sale in that currency. Falling back to
    // another currency's number would show a customer the wrong price.
    expect($this->thing->amountIn('EUR'))->toBeNull()
        ->and($this->thing->priceIn('EUR'))->toBeNull();
});

it('returns amounts as integer minor units', function () {
    $this->thing->setPriceIn('GBP', 1900);

    $amount = $this->thing->fresh()->amountIn('GBP');

    // 1900 means 19.00, and it must arrive as an int. A numeric string would
    // become a float on the first arithmetic operation and lose pennies.
    expect($amount)->toBeInt()->toBe(1900);
});

it('stores the amount column as an integer type, not a decimal', function () {
    $type = DB::selectOne(
        "select data_type from information_schema.columns
         where table_name = 'prices' and column_name = 'amount'"
    )->data_type;

    expect($type)->toBe('integer');
});

it('refuses a second price in the same currency for the same thing', function () {
    $this->thing->setPriceIn('GBP', 1900);

    // Enforced by the unique index, not by application logic, so a race or a
    // direct write cannot leave two conflicting GBP prices behind.
    expect(fn () => $this->thing->prices()->create([
        'currency_code' => 'GBP',
        'amount' => 2400,
    ]))->toThrow(QueryException::class);
});

it('replaces an existing price rather than adding a second row', function () {
    $this->thing->setPriceIn('GBP', 1900);
    $this->thing->setPriceIn('GBP', 2400);

    expect($this->thing->prices()->count())->toBe(1)
        ->and($this->thing->fresh()->amountIn('GBP'))->toBe(2400);
});

it('normalises currency codes to uppercase on write', function () {
    $this->thing->setPriceIn('gbp', 1900);

    expect(Price::first()->currency_code)->toBe('GBP')
        ->and($this->thing->fresh()->amountIn('gbp'))->toBe(1900);
});

it('keeps prices scoped to their own priceable', function () {
    $other = PriceableThing::create(['name' => 'Another reading']);

    $this->thing->setPriceIn('GBP', 1900);
    $other->setPriceIn('GBP', 4500);

    expect($this->thing->fresh()->amountIn('GBP'))->toBe(1900)
        ->and($other->fresh()->amountIn('GBP'))->toBe(4500);
});
