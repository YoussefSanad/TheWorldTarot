<?php

namespace App\Filament\Resources\Readings\Pages;

use App\Filament\Resources\Readings\ReadingResource;
use App\Filament\Resources\Readings\Tables\ReadingsTable;
use Filament\Resources\Pages\ViewRecord;

/**
 * The whole of one asking.
 *
 * **The first detail page in this panel**, and it exists because a question
 * runs to 2000 characters and a gift adds a signature and a message on top of
 * it. The list carries the first line so she can scan; this carries the rest so
 * she can write.
 */
class ViewReading extends ViewRecord
{
    protected static string $resource = ReadingResource::class;

    /**
     * The same action the list offers, from the page she reads the question on,
     * so she does not have to go back to a table to say it has gone.
     *
     * **Declared once in `ReadingsTable`** rather than written out again here.
     * Two copies of an action are two visibility rules that have to agree by
     * hand, and this one hides itself on a reading that has already gone.
     *
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [ReadingsTable::markDelivered()];
    }
}
