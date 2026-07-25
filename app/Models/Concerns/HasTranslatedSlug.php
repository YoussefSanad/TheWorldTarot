<?php

namespace App\Models\Concerns;

use App\Models\SlugHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Lookup by translated slug, and a record of every slug ever used.
 *
 * Slugs are translatable, so /readings/one-card and /it/letture/una-carta are
 * distinct URLs on the same record. Uniqueness is enforced per locale by a
 * Postgres expression index, not here. See .claude/translation.md section 9.
 *
 * Cards reuse this in M3.
 */
trait HasTranslatedSlug
{
    public static function bootHasTranslatedSlug(): void
    {
        static::updating(function ($model) {
            $model->recordReplacedSlugs();
        });
    }

    public function slugHistory(): MorphMany
    {
        return $this->morphMany(SlugHistory::class, 'sluggable');
    }

    /**
     * Find a record by its slug in one locale.
     *
     * A slug is only ever matched against the locale it belongs to. An Italian
     * slug requested in English is a miss, not a fallback, because serving the
     * right record under the wrong URL is how two languages end up sharing one
     * address and neither ranking.
     */
    public function scopeWhereSlug(Builder $query, string $slug, string $locale): Builder
    {
        if (! array_key_exists($locale, config('localization.available'))) {
            // An undeclared locale cannot own a slug, so nothing can match.
            return $query->whereRaw('1 = 0');
        }

        return $query->whereRaw("slug->>'".$locale."' = ?", [$slug]);
    }

    /**
     * Write a history row for every locale whose slug is being replaced or
     * removed. Called on update, before the new value is saved.
     */
    protected function recordReplacedSlugs(): void
    {
        if (! $this->isDirty('slug')) {
            return;
        }

        $previous = $this->decodeSlugs($this->getOriginal('slug'));
        $current = $this->decodeSlugs($this->getAttributes()['slug'] ?? null);

        foreach ($previous as $locale => $slug) {
            if ($slug === null || $slug === '') {
                continue;
            }

            // Unchanged in this locale, so nothing was replaced.
            if (($current[$locale] ?? null) === $slug) {
                continue;
            }

            $this->slugHistory()->create([
                'locale' => $locale,
                'slug' => $slug,
            ]);
        }
    }

    /**
     * @return array<string, string|null>
     */
    private function decodeSlugs(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        return is_string($value) ? (json_decode($value, true) ?? []) : [];
    }
}
