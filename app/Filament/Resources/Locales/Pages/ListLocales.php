<?php

namespace App\Filament\Resources\Locales\Pages;

use App\Filament\Resources\Locales\LocaleResource;
use Filament\Resources\Pages\ListRecords;

class ListLocales extends ListRecords
{
    protected static string $resource = LocaleResource::class;

    public function getSubheading(): string
    {
        return 'A language goes on the website once everything on sale has been translated into it. '
            .'Taking one down takes effect straight away.';
    }

    /**
     * No create action. A language needs its wording shipped in the website's
     * own code first, so adding one is a developer's job.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
