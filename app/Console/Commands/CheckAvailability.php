<?php

namespace App\Console\Commands;

use App\Models\Card;
use App\Models\Product;
use App\Support\Locales;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Asks the availability rule the same question three ways, on real rows, and
 * reports anything that answers differently.
 *
 * The suite proves this on rows a factory made up. That is the weaker half of
 * the proof, and this project has been caught by the difference before: a
 * signature the tests agreed with was refused by Bunny for five days. Real rows
 * carry states nobody thought to write a factory for, which is exactly the
 * class of row a hand-maintained pair of implementations used to get wrong.
 *
 * What a disagreement looks like from outside, and why nothing else catches it:
 * the Library listing takes `in_viewing_room` from the predicate while the
 * video route takes its 404 from the query, so the two never meet. A card that
 * is listed, says it is watchable, and 404s when watched is what a customer
 * sees instead.
 *
 * Read only. It writes nothing and is safe against production.
 *
 * On Laravel Cloud, run it from the environment's command runner:
 *
 *     php artisan availability:check
 *     php artisan availability:check --explain
 */
class CheckAvailability extends Command
{
    protected $signature = 'availability:check
        {--locale=* : Languages to ask about, defaulting to every language in the registry}
        {--explain : Also list everything that is off the website, and what it is waiting for}';

    protected $description = 'Prove the query, the predicate and the panel agree about every card and product';

    /**
     * @var array<int, string>
     */
    private array $disagreements = [];

    public function handle(): int
    {
        $locales = $this->option('locale') ?: Locales::codes();

        $this->components->twoColumnDetail('Environment', app()->environment());
        $this->components->twoColumnDetail('Database', (string) DB::connection()->getDatabaseName());
        $this->components->twoColumnDetail('Languages', implode(', ', $locales));
        $this->newLine();

        // Trashed rows included deliberately. The two renderings used to
        // disagree about exactly those, one of them holding an opinion from the
        // soft delete global scope that it never stated.
        $cards = Card::withTrashed()->get();
        $products = Product::withTrashed()->get();

        foreach ($locales as $locale) {
            $this->checkTheWebsite('Cards', $cards, Card::withTrashed()->availableIn($locale)->pluck('id')->all(), $locale);
            $this->checkTheWebsite('Products', $products, Product::withTrashed()->availableIn($locale)->pluck('id')->all(), $locale);
            $this->checkTheViewingRoom($cards, $locale);
        }

        if ($this->option('explain')) {
            $this->explain($cards, $products, $locales);
        }

        return $this->report();
    }

    /**
     * The Library and the shop: is this record on the website in this language.
     *
     * @param  Collection<int, Model>  $records
     * @param  array<int, int>  $byQuery
     */
    private function checkTheWebsite(string $label, Collection $records, array $byQuery, string $locale): void
    {
        $listed = array_flip($byQuery);

        $this->compare(
            $label,
            $records,
            $locale,
            fn (Model $record): bool => isset($listed[$record->getKey()]),
            fn (Model $record): bool => $record->isAvailableIn($locale),
            fn (Model $record): bool => $record->whatIsMissing($locale) === [],
        );
    }

    /**
     * @param  Collection<int, Card>  $cards
     */
    private function checkTheViewingRoom(Collection $cards, string $locale): void
    {
        $listed = array_flip(Card::withTrashed()->viewableIn($locale)->pluck('id')->all());

        $this->compare(
            'Viewing Room',
            $cards,
            $locale,
            fn (Card $card): bool => isset($listed[$card->getKey()]),
            fn (Card $card): bool => $card->isViewableIn($locale),
            fn (Card $card): bool => $card->whatTheViewingRoomIsWaitingFor($locale) === [],
        );
    }

    /**
     * @param  Collection<int, Model>  $records
     * @param  callable(Model): bool  $query
     * @param  callable(Model): bool  $predicate
     * @param  callable(Model): bool  $prose
     */
    private function compare(string $label, Collection $records, string $locale, callable $query, callable $predicate, callable $prose): void
    {
        $language = $this->languageName($locale);
        $disagreed = 0;

        foreach ($records as $record) {
            $found = self::disagreement($query($record), $predicate($record), $prose($record));

            if ($found === null) {
                continue;
            }

            $disagreed++;
            $this->disagreements[] = "{$label}, {$language}: {$this->name($record)} {$found}";
        }

        $this->components->twoColumnDetail(
            "{$label}, {$language}",
            $disagreed === 0
                ? "{$records->count()} rows, <fg=green>agreed</>"
                : "<fg=red>{$disagreed} of {$records->count()} rows disagreed</>",
        );
    }

    /**
     * What disagreed, or null when all three renderings said the same thing.
     *
     * Public because it is the whole judgement this command makes, and the one
     * part of it that can be proved without a database. The command running
     * clean says nothing about whether it would have noticed.
     */
    public static function disagreement(bool $query, bool $predicate, bool $prose): ?string
    {
        if ($query === $predicate && $predicate === $prose) {
            return null;
        }

        $said = [
            'the listing says '.self::yesOrNo($query),
            'a record in hand says '.self::yesOrNo($predicate),
            'the panel says '.($prose ? 'it is finished' : 'it is waiting for something'),
        ];

        return 'is not one thing: '.implode(', ', $said);
    }

    private static function yesOrNo(bool $answer): string
    {
        return $answer ? 'yes' : 'no';
    }

    /**
     * Everything off the website, in the words the panel would use.
     *
     * The third rendering read by eye. An agreement check proves the three say
     * the same thing and cannot prove they say something a person can act on.
     *
     * @param  Collection<int, Card>  $cards
     * @param  Collection<int, Product>  $products
     * @param  array<int, string>  $locales
     */
    private function explain(Collection $cards, Collection $products, array $locales): void
    {
        foreach ($locales as $locale) {
            $this->newLine();
            $this->components->info('Waiting on something, '.$this->languageName($locale).':');
            $waiting = 0;

            foreach ([$cards, $products] as $records) {
                foreach ($records as $record) {
                    $missing = $record->whatIsMissing($locale);

                    if ($missing !== []) {
                        $waiting++;
                        $this->components->twoColumnDetail($this->name($record), 'needs '.collect($missing)->join(', ', ' and '));
                    }
                }
            }

            foreach ($cards as $card) {
                $waitingFor = $card->whatTheViewingRoomIsWaitingFor($locale);

                if ($waitingFor !== []) {
                    $waiting++;
                    $this->components->twoColumnDetail(
                        $this->name($card).', Viewing Room',
                        'needs '.collect($waitingFor)->join(', ', ' and '),
                    );
                }
            }

            if ($waiting === 0) {
                $this->components->twoColumnDetail('Everything', '<fg=green>on the website</>');
            }
        }
    }

    private function report(): int
    {
        $this->newLine();

        if ($this->disagreements === []) {
            $this->components->info('The query, the predicate and the panel agree about every row.');

            return self::SUCCESS;
        }

        // Every one of these is a customer seeing one thing in a listing and
        // another when they act on it.
        $this->components->error(count($this->disagreements).' rows are answered differently depending which way the rule is asked:');

        foreach ($this->disagreements as $disagreement) {
            $this->components->bulletList([$disagreement]);
        }

        return self::FAILURE;
    }

    private function name(Model $record): string
    {
        $label = $record->translationLabel();
        $removed = $record->trashed() ? ', removed' : '';

        return ($label !== '' ? $label : 'Untitled')." (#{$record->getKey()}{$removed})";
    }

    private function languageName(string $locale): string
    {
        return Locales::find($locale)?->english_name ?? $locale;
    }
}
