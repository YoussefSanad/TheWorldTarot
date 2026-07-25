<?php

namespace App\Filament\Resources\Pages\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Not translatable: the web address is the same across languages
                // at this stage. Translated slugs arrive in a later milestone.
                TextInput::make('slug')
                    ->label('Web address')
                    ->helperText('The short name used in the page link, for example "about". Lowercase letters and dashes only.')
                    ->required()
                    ->maxLength(255),

                // Translatable: the active language tab decides which value is edited.
                TextInput::make('title')
                    ->label('Title')
                    ->helperText('The page heading shown to visitors in the selected language.')
                    ->required()
                    ->maxLength(255),

                Textarea::make('body')
                    ->label('Content')
                    ->helperText('The page text shown to visitors in the selected language.')
                    ->rows(10)
                    ->required(),
            ]);
    }
}
