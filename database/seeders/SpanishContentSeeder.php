<?php

namespace Database\Seeders;

use App\Enums\ProductKey;
use App\Models\Card;
use App\Models\Product;
use App\Support\CopyFields;
use App\Support\Locales;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Spanish copy for the products and cards, loaded once.
 *
 * ## Deliberately not registered in `DatabaseSeeder`
 *
 * The two seeders that are registered there run on **every deploy**, which is
 * why both carry a stamp: `ProductSeeder` writes `defaults_seeded_at` and
 * `CardSeeder` skips any row that exists. "Fill what is empty" repeated forever
 * also means "undo emptying something", and clearing a description is how the
 * panel takes a record off the site in a language. Refilling it on the next
 * deploy is the system overruling the person it serves, silently.
 *
 * This runs when a person asks it to:
 *
 * ```
 * php artisan db:seed --class=SpanishContentSeeder
 * ```
 *
 * So it needs no stamp column and can never overrule an edit made after it.
 * That is the whole trick, and it removes the only hard problem. Registering it
 * would reintroduce that problem and there is a test asserting nobody has.
 *
 * ## What it will and will not do
 *
 * 1. **Fills only what is blank.** A Spanish value that already exists is
 *    somebody's work and is left exactly as it is, however unpromising.
 * 2. **Never touches English.** It writes one language key.
 * 3. **Refuses a data file missing Spanish for published copy that has
 *    English and no Spanish yet**, at run time and inside one transaction, so
 *    nothing it wrote survives the refusal. The alternative is a
 *    half-translated language that the gate then refuses for reasons nobody
 *    can trace back here. A draft is not refused over, or reported, because
 *    the gate does not count one. ~~Spanish already in the panel did not
 *    count~~ until 11 September 2026: a card translated by hand still stopped
 *    the whole run, which made "type it in the panel, then seed" impossible.
 * 4. **Reports, rather than refuses, copy that has no English either.** A field
 *    blank in English is not waiting on a translator, and writing Spanish into
 *    it would not be translation, it would be inventing this business's copy
 *    in a language its owner cannot check. Because availability is calculated
 *    per language, it would also produce a Spanish library holding 22 cards and
 *    an English one holding none.
 *
 * ## Model events fire here, unlike in `DatabaseSeeder`
 *
 * `DatabaseSeeder` uses `WithoutModelEvents`; a standalone `db:seed --class=`
 * does not. So `TranslatableContentObserver` runs on every save below, each one
 * walking every published record. Harmless at this size, and it is the
 * mechanism that would take Spanish straight back down if the data were
 * incomplete, which is what rule 3 exists to prevent quietly happening.
 *
 * ## What it cannot see
 *
 * Completeness counts fields, not quality. `TODO`, or English pasted into the
 * Spanish box, passes the gate and goes live. `preview` is the only thing that
 * catches that, and it needs a person to walk the site.
 *
 * See `.claude/translation.md` section 4 and `docs/adding-a-language.md`.
 */
class SpanishContentSeeder extends Seeder
{
    private const LOCALE = 'es';

    /**
     * Every field this run skipped because it holds no English to translate.
     *
     * Public so a test and a person can both read it. These are content for the
     * client to write in English first; until they exist Spanish cannot go
     * live, and the Languages screen names each one.
     *
     * @var array<int, string>
     */
    public array $waitingOnEnglish = [];

    public function run(): void
    {
        $this->waitingOnEnglish = [];

        // One transaction, so a refusal on the last card leaves the products as
        // it found them rather than half of Spanish loaded and nobody told.
        DB::transaction(function (): void {
            $this->seedProducts(require database_path('data/translations/es-products.php'));
            $this->seedCards(require database_path('data/translations/es-cards.php'));
        });

        $this->reportWhatEnglishIsMissing();
    }

    /** @param array<string, array<string, string>> $copy */
    private function seedProducts(array $copy): void
    {
        foreach (ProductKey::seedable() as $key) {
            $product = Product::where('key', $key)->first();

            if ($product === null) {
                // ProductSeeder owns creation. A missing row means it has not
                // run here, and creating one would race that.
                throw new RuntimeException("No {$key->value} product. Run ProductSeeder first.");
            }

            $this->fillFrom($product, $copy[$key->value] ?? [], $key->value);
        }
    }

    /** @param array<int, array<string, string>> $copy */
    private function seedCards(array $copy): void
    {
        foreach (Card::orderBy('number')->get() as $card) {
            $this->fillFrom($card, $copy[$card->number] ?? [], "card {$card->number}");
        }
    }

    /**
     * Writes the Spanish this record is missing, and only that.
     *
     * Public because it is the one seam worth testing on its own: the refusal
     * in the middle is the rule this class exists to enforce, and reaching it
     * through `run()` would mean editing a data file to prove it.
     *
     * @param  array<string, string>  $values
     */
    public function fillFrom(Model $record, array $values, string $label): void
    {
        // Only a field still blank in Spanish is this seeder's business. One
        // somebody has written in the panel is left alone by rule 1, and it
        // already satisfies the gate, so the data file owes nothing for it.
        // `false` disables spatie's fallback, so this asks what Spanish
        // actually holds rather than what it would display.
        $blank = array_filter(
            $this->fieldsWithEnglish($record, $label),
            fn (string $field): bool => blank($record->getTranslation($field, self::LOCALE, false)),
        );

        // `array_filter` drops empty strings, so a key present but blank in the
        // data file counts as absent, which is what it is.
        $missing = array_diff($blank, array_keys(array_filter($values)));

        // The refusal exists because the gate would refuse, and the gate counts
        // published records only. A draft short of Spanish gets what the file
        // has, and holding everything else back over it would hide the reason.
        if ($missing !== [] && $record->is_published) {
            throw new RuntimeException(
                "No Spanish for {$label}: ".implode(', ', $missing).'. '
                .'The gate will refuse Spanish until every published record is complete, so a '
                .'partial data file is a language that cannot go live for reasons this seeder hid.'
            );
        }

        foreach (array_diff($blank, $missing) as $field) {
            $record->setTranslation($field, self::LOCALE, $values[$field]);
        }

        // Eloquent fires `saved` on a save that changed nothing, and the
        // observer walks every published record for each one.
        if ($record->isDirty()) {
            $record->save();
        }
    }

    /**
     * The fields on this record that hold English, and so have something to be
     * translated from. Everything else is skipped, and noted when the record
     * is published: the report says Spanish is waiting on it, which is only
     * true of a record the gate counts.
     *
     * @return array<int, string>
     */
    private function fieldsWithEnglish(Model $record, string $label): array
    {
        $default = Locales::default();
        $translatable = [];

        foreach ($record::requiredTranslations() as $field) {
            if (blank($record->getTranslation($field, $default, false))) {
                if ($record->is_published) {
                    $this->waitingOnEnglish[] = "{$label} needs ".CopyFields::describe($field).' in English';
                }

                continue;
            }

            $translatable[] = $field;
        }

        return $translatable;
    }

    /**
     * Says out loud what Spanish is still blocked on, since it is not this
     * seeder and no amount of re-running it will help.
     */
    private function reportWhatEnglishIsMissing(): void
    {
        if ($this->waitingOnEnglish === []) {
            return;
        }

        $this->command?->warn(sprintf(
            '%d fields hold no English, so there was nothing to translate. Spanish cannot go '
            .'live until they are written. The Languages screen names each one.',
            count($this->waitingOnEnglish),
        ));

        foreach ($this->waitingOnEnglish as $line) {
            $this->command?->line("  - {$line}");
        }
    }
}
