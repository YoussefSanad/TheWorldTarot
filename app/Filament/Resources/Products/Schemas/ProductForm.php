<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Support\Money;
use Closure;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use InvalidArgumentException;

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
                            ->required(self::requiredInDefaultLocale())
                            ->maxLength(255),

                        Textarea::make('short_description')
                            ->label('Short description')
                            ->helperText('One or two lines, shown where readings are listed together.')
                            ->rows(3)
                            ->required(self::requiredInDefaultLocale()),

                        Textarea::make('long_description')
                            ->label('Full description')
                            ->helperText('The longer text on the reading\'s own page.')
                            ->rows(10)
                            ->required(self::requiredInDefaultLocale()),

                        TextInput::make('slug')
                            ->label('Web address')
                            ->helperText('The short name used in the page link, for example one-card. Lowercase letters, numbers and dashes only. Changing it changes the link, and the old link stops working.')
                            ->required(self::requiredInDefaultLocale())
                            ->maxLength(255)
                            ->rule(self::webAddressRule()),
                    ]),

                Section::make('Price')
                    ->description('Set each price yourself. Nothing is converted between currencies, so changing one price never changes another.')
                    ->schema(self::priceFields())
                    ->columns(3),

                Section::make('Availability')
                    ->schema([
                        Toggle::make('is_published')
                            ->label('Show on the website')
                            ->helperText('Turn this off to take the reading off sale. Nothing is deleted and past orders are unaffected.'),

                        TextInput::make('sort_order')
                            ->label('Position in the list')
                            ->helperText('Lower numbers appear first.')
                            ->numeric()
                            ->required(),
                    ])
                    ->columns(2),
            ]);
    }

    /**
     * Required in the default language, optional in every other one.
     *
     * Translating is incremental work. Demanding every field before an Italian
     * draft can be saved would make the panel unusable for the one job it is
     * there to support, and the `available` locale state exists precisely to
     * describe a translation in progress.
     *
     * Completeness is enforced later, by the coverage gate that decides whether
     * a language may go live, not by refusing to save. See
     * .claude/translation.md sections 4 and 5.
     */
    private static function requiredInDefaultLocale(): Closure
    {
        return fn ($livewire): bool => ($livewire->activeLocale ?? config('localization.default'))
            === config('localization.default');
    }

    /**
     * Validates the web address only when one has been typed, so a language
     * still being worked on can be saved with the field left empty.
     */
    private static function webAddressRule(): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail) {
            if (filled($value) && ! preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $value)) {
                $fail('Use lowercase letters, numbers and dashes only, for example one-card.');
            }
        };
    }

    /**
     * A seeded product starts at zero in every currency, which is deliberately
     * wrong rather than plausibly wrong. The database can only insist a price
     * exists, not that it means anything, so this is what stops a reading going
     * on sale for nothing.
     *
     * Only applies when the product is being published, so an unfinished one
     * can still be saved.
     */
    private static function priceSetBeforePublishingRule(): Closure
    {
        return fn ($get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get) {
            if (! $get('is_published')) {
                return;
            }

            try {
                $minor = Money::toMinor((string) $value);
            } catch (InvalidArgumentException) {
                // Not a number at all. The regex rule reports that itself.
                return;
            }

            if ($minor < 1) {
                $fail('Set a price above zero before showing this on the website.');
            }
        };
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
                ->rule(self::priceSetBeforePublishingRule())
                ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : Money::toMajor($state))
                ->dehydrateStateUsing(fn (?string $state): ?int => $state === null ? null : Money::toMinor($state))
            )
            ->values()
            ->all();
    }
}
