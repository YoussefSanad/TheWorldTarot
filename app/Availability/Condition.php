<?php

namespace App\Availability;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One thing a record needs before it is on the website.
 *
 * A condition holds three things that used to be written in three places: how
 * to ask it of a table, how to ask it of a record in memory, and what to say
 * when the answer is no. They are constructor arguments rather than optional
 * extras, so a condition cannot be added to the gate and forgotten in the
 * explanation, which is how a card ended up off the website with the panel
 * unable to say why.
 *
 * The wording is hers, not ours. It finishes the sentence "it needs ...".
 */
final class Condition
{
    /**
     * @param  Closure(Model, string): (string|array<int, string>)  $missing
     * @param  Closure(Builder<covariant Model>, string): mixed  $query
     * @param  Closure(Model, string): bool  $record
     */
    private function __construct(
        private readonly Closure $missing,
        private readonly Closure $query,
        private readonly Closure $record,
    ) {}

    /**
     * Both renderings and the wording, declared together.
     *
     * Every closure is handed the record or the query and the locale being
     * asked about, and is free to ignore the locale. Most conditions do.
     *
     * @param  string|Closure(Model, string): (string|array<int, string>)  $missing
     * @param  Closure(Builder<covariant Model>, string): mixed  $query
     * @param  Closure(Model, string): bool  $record
     */
    public static function make(string|Closure $missing, Closure $query, Closure $record): self
    {
        return new self(
            is_string($missing) ? fn (): string => $missing : $missing,
            $query,
            $record,
        );
    }

    /**
     * The condition as a query. Narrows the builder in place, the way a scope
     * does.
     *
     * @param  Builder<covariant Model>  $query
     */
    public function constrain(Builder $query, string $locale): void
    {
        ($this->query)($query, $locale);
    }

    /**
     * The condition as a predicate, asked of a record already in memory.
     */
    public function isMetBy(Model $record, string $locale): bool
    {
        return (bool) ($this->record)($record, $locale);
    }

    /**
     * The condition as prose, for a record that does not meet it.
     *
     * An array rather than a string, because one condition can be waiting for
     * several things at once: copy in a language is one rule and three fields.
     *
     * @return array<int, string>
     */
    public function whatIsMissing(Model $record, string $locale): array
    {
        $missing = ($this->missing)($record, $locale);

        return is_array($missing) ? array_values($missing) : [$missing];
    }
}
