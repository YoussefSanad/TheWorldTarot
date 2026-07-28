<?php

namespace App\Filament\Resources\Locales\Tables;

use App\Enums\LocaleStatus;
use App\Models\Locale;
use App\Support\Locales;
use App\Support\TranslationCoverage;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class LocalesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('english_name')
                    ->label('Language'),

                TextColumn::make('native_name')
                    ->label('Called'),

                TextColumn::make('status')
                    ->label('Where it shows')
                    ->badge()
                    ->formatStateUsing(fn (LocaleStatus $state): string => match ($state) {
                        LocaleStatus::Draft => 'Being translated',
                        LocaleStatus::Preview => 'Visible to you only',
                        LocaleStatus::Live => 'On the website',
                    })
                    ->color(fn (LocaleStatus $state): string => match ($state) {
                        LocaleStatus::Draft => 'gray',
                        LocaleStatus::Preview => 'warning',
                        LocaleStatus::Live => 'success',
                    }),

                TextColumn::make('progress')
                    ->label('Translated')
                    ->state(function (Locale $record): string {
                        if ($record->code === Locales::default()) {
                            return 'Original';
                        }

                        $progress = TranslationCoverage::progress($record->code);

                        return "{$progress['complete']} of {$progress['total']}";
                    }),
            ])
            ->recordActions([
                self::previewAction(),
                self::publishAction(),
                self::withdrawAction(),
            ])
            ->toolbarActions([]);
    }

    /**
     * Reviewing the site in a language before customers can reach it. Never
     * gated, because the whole point is to look at it before it is finished.
     */
    private static function previewAction(): Action
    {
        return Action::make('preview')
            ->label('Let me review it')
            ->icon(Heroicon::OutlinedEye)
            ->visible(fn (Locale $record): bool => $record->status === LocaleStatus::Draft
                && $record->code !== Locales::default())
            ->action(function (Locale $record): void {
                $record->update(['status' => LocaleStatus::Preview]);
                Locales::forget();

                Notification::make()
                    ->success()
                    ->title("{$record->english_name} is ready for you to review")
                    ->body('Only you can see it. Customers still see the site in English.')
                    ->send();
            });
    }

    /**
     * The gate. A language cannot go in front of customers while anything
     * published is missing a translation, and the refusal says exactly what is
     * missing rather than that something is wrong.
     */
    private static function publishAction(): Action
    {
        return Action::make('publish')
            ->label('Show on the website')
            ->icon(Heroicon::OutlinedGlobeAlt)
            ->requiresConfirmation()
            ->modalDescription('Customers will be able to read the site in this language.')
            ->visible(fn (Locale $record): bool => ! $record->isLive()
                && $record->code !== Locales::default())
            ->action(function (Locale $record): void {
                $outstanding = TranslationCoverage::outstanding($record->code);

                if ($outstanding->isNotEmpty()) {
                    Notification::make()
                        ->danger()
                        ->title("{$record->english_name} is not finished yet")
                        ->body(self::outstandingMessage($outstanding))
                        ->persistent()
                        ->send();

                    return;
                }

                $record->update(['status' => LocaleStatus::Live]);
                Locales::forget();

                Notification::make()
                    ->success()
                    ->title("The site is now available in {$record->english_name}")
                    ->send();
            });
    }

    /**
     * Pulling a language back. Never gated and never confirmed away: if
     * something is wrong on the Spanish site, waiting is the wrong answer.
     */
    private static function withdrawAction(): Action
    {
        return Action::make('withdraw')
            ->label('Take it down')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->visible(fn (Locale $record): bool => $record->status !== LocaleStatus::Draft
                && $record->code !== Locales::default())
            ->action(function (Locale $record): void {
                $record->update(['status' => LocaleStatus::Draft]);
                Locales::forget();

                Notification::make()
                    ->success()
                    ->title("{$record->english_name} has been taken down")
                    ->body('Nothing was deleted. The translations are still here.')
                    ->send();
            });
    }

    /**
     * @param  Collection<int, array{label: string, fields: array<int, string>}>  $outstanding
     */
    private static function outstandingMessage($outstanding): string
    {
        $lines = $outstanding->take(3)->map(function (array $row): string {
            $fields = collect($row['fields'])
                ->map(fn (string $field): string => match ($field) {
                    'name' => 'name',
                    'short_description' => 'short description',
                    'long_description' => 'full description',
                    default => str_replace('_', ' ', $field),
                })
                ->join(', ', ' and ');

            return "{$row['label']} needs a {$fields}.";
        });

        if ($outstanding->count() > 3) {
            $lines->push('And '.($outstanding->count() - 3).' more.');
        }

        return $lines->join(' ');
    }
}
