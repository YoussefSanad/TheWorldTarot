<?php

namespace App\Support;

/**
 * Turns a column name into the words Jennifer uses for it.
 *
 * One list, because there were two: the reading editor said what a reading was
 * waiting for, the languages screen said what a language was waiting for, and
 * both had their own copy of the same `match`. Cards use the same field names
 * as products, so a third model would have made it three.
 *
 * Anything unrecognised falls back to the column name with its underscores
 * removed, which is wrong but readable, rather than blank.
 */
final class CopyFields
{
    /**
     * One field, with its article, ready to drop into a sentence.
     */
    public static function describe(string $field): string
    {
        return match ($field) {
            'name' => 'a name',
            'short_description' => 'a short description',
            'long_description' => 'a full description',
            default => 'a '.str_replace('_', ' ', $field),
        };
    }

    /**
     * Several fields as a list a person would read out.
     *
     * @param  array<int, string>  $fields
     */
    public static function sentence(array $fields): string
    {
        return collect($fields)
            ->map(fn (string $field): string => self::describe($field))
            ->join(', ', ' and ');
    }
}
