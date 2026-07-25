<?php

namespace Tests\Fixtures;

use App\Models\Concerns\HasPrices;
use Illuminate\Database\Eloquent\Model;

/**
 * A stand-in for the first real priceable model, which arrives with reading
 * products in M2. It exists so the prices table and the HasPrices trait can be
 * proven in M1 without inventing a domain model ahead of its milestone.
 *
 * Its table is created by the test that uses it.
 */
class PriceableThing extends Model
{
    use HasPrices;

    protected $table = 'priceable_things';

    protected $fillable = ['name'];
}
