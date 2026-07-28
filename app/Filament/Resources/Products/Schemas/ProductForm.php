<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Support\Locales;
use App\Support\Money;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Deliberately absent: key, type and fulfilment. They are structure rather than
 * content, they mean nothing to a non-technical user, and changing one would
 * break the frontend or the checkout. See CLAUDE.md section 4.
 */
class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('What customers read')
                    ->description('The language tab at the top decides which language you are editing.')
                    ->schema([
                        TextInput::make('name')
                            ->label('Name')
                            ->helperText('Shown on the reading page and in the checkout.')
                            ->maxLength(255),

                        Textarea::make('short_description')
                            ->label('Short description')
                            ->helperText('One or two lines, shown where readings are listed together.')
                            ->rows(3),

                        Textarea::make('long_description')
                            ->label('Full description')
                            ->helperText('The longer text on the reading\'s own page.')
                            ->rows(10),

                    ]),

                Section::make('Price')
                    ->description('Set each price yourself. Nothing is converted between currencies, so changing one price never changes another.')
                    ->schema(self::priceFields())
                    ->columns(3),

                Section::make('Availability')
                    ->schema([
                        Toggle::make('is_published')
                            ->label('Show on the website')
                            ->helperText(fn ($livewire): string => self::editingDefaultLocale($livewire)
                                ? 'Turn this off to take the reading off sale. Nothing is deleted and past orders are unaffected.'
                                : 'Switch to '.Locales::defaultName().' to change this.')
                            // Publishing is decided once, in the default
                            // language, rather than differently per language. A
                            // reading is on sale or it is not.
                            ->disabled(fn ($livewire): bool => ! self::editingDefaultLocale($livewire)),

                        TextInput::make('sort_order')
                            ->label('Position in the list')
                            ->helperText('Lower numbers appear first.')
                            ->numeric()
                            ->required(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function editingDefaultLocale(mixed $livewire): bool
    {
        return ($livewire->activeLocale ?? Locales::default()) === Locales::default();
    }

    /**
     * One field per configured currency, so the form follows
     * config/currencies.php rather than hardcoding three inputs.
     *
     * Entered in major units and stored in minor units. That conversion lives
     * in Money and happens only here, at the form boundary.
     *
     * @return array<int, TextInput>
     */
    private static function priceFields(): array
    {
        return collect(config('currencies.available'))
            ->map(fn (array $currency, string $code) => TextInput::make('price_'.strtolower($code))
                ->label($currency['label'])
                ->prefix($currency['symbol'])
                ->required()
                ->rule('regex:/^\d+([.,]\d{1,2})?$/')
                ->validationMessages([
                    'regex' => 'Enter an amount like 19.00.',
                ])
                ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : Money::toMajor($state))
                ->dehydrateStateUsing(fn (?string $state): ?int => $state === null ? null : Money::toMinor($state))
            )
            ->values()
            ->all();
    }
}
