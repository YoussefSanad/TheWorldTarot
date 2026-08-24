<?php

namespace App\Availability;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Everything a record needs before it is on the website, declared once.
 *
 * "Is this on the website in this language" was answered by two separate
 * implementations, one in SQL and one in PHP, kept in agreement by hand, and
 * explained to Jennifer by a third written out beside them. They answered
 * different callers, so nothing ever noticed when they disagreed.
 *
 * The conditions are stated here and the three answers are rendered from them:
 * a query for listings, a predicate for a record in hand, and prose for the
 * panel. Adding a condition changes all three at once, which is the point.
 *
 * Order is the order she reads them in. The query does not care.
 */
final class Availability
{
    /**
     * @var array<int, Condition>
     */
    private readonly array $conditions;

    private function __construct(Condition ...$conditions)
    {
        $this->conditions = $conditions;
    }

    public static function of(Condition ...$conditions): self
    {
        return new self(...$conditions);
    }

    /**
     * The declaration as a query, for a listing that must not leak a record
     * nobody should see.
     *
     * @param  Builder<covariant Model>  $query
     * @return Builder<covariant Model>
     */
    public function constrain(Builder $query, string $locale): Builder
    {
        foreach ($this->conditions as $condition) {
            $condition->constrain($query, $locale);
        }

        return $query;
    }

    /**
     * The declaration as a predicate, for a record already loaded.
     */
    public function satisfiedBy(Model $record, string $locale): bool
    {
        foreach ($this->conditions as $condition) {
            if (! $condition->isMetBy($record, $locale)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The declaration as prose. Empty exactly when `satisfiedBy` is true.
     *
     * Deduplicated, because a record unfinished in the original language and
     * in the one being read is waiting for one name, not two.
     *
     * @return array<int, string>
     */
    public function unmetBy(Model $record, string $locale): array
    {
        $missing = [];

        foreach ($this->conditions as $condition) {
            if ($condition->isMetBy($record, $locale)) {
                continue;
            }

            foreach ($condition->whatIsMissing($record, $locale) as $words) {
                $missing[] = $words;
            }
        }

        return array_values(array_unique($missing));
    }

    /**
     * The whole declaration as a single condition of another one.
     *
     * What the Viewing Room is built on. Being in it means being in the Library
     * and having a film, and the first half is this declaration entire rather
     * than a copy of it, so a card cannot be watchable without being readable.
     * She reads the whole of it as one line, which is why the wording is given
     * here rather than gathered from the conditions inside.
     *
     * @param  string|Closure(Model, string): (string|array<int, string>)  $missing
     */
    public function asCondition(string|Closure $missing): Condition
    {
        return Condition::make(
            missing: $missing,
            query: fn (Builder $query, string $locale): Builder => $this->constrain($query, $locale),
            record: fn (Model $record, string $locale): bool => $this->satisfiedBy($record, $locale),
        );
    }
}
