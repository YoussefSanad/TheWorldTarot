<?php

namespace App\Filament\Resources\Readings\Pages;

use App\Filament\Resources\Readings\ReadingResource;
use Filament\Resources\Pages\ListRecords;

class ListReadings extends ListRecords
{
    protected static string $resource = ReadingResource::class;

    /**
     * No create action. A reading arrives by somebody asking for one.
     *
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
