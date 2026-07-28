<?php

use App\Support\CopyFields;

it('describes a field in the words the client uses for it', function (string $field, string $expected) {
    expect(CopyFields::describe($field))->toBe($expected);
})->with([
    ['name', 'a name'],
    ['short_description', 'a short description'],
    ['long_description', 'a full description'],
]);

it('falls back to a readable version of an unknown field', function () {
    // Wrong wording is recoverable. A blank space in a sentence telling
    // somebody what to fix is not.
    expect(CopyFields::describe('meaning_reversed'))->toBe('a meaning reversed');
});

it('reads several fields as a list a person would say out loud', function () {
    expect(CopyFields::sentence(['name', 'short_description', 'long_description']))
        ->toBe('a name, a short description and a full description')
        ->and(CopyFields::sentence(['name', 'long_description']))
        ->toBe('a name and a full description')
        ->and(CopyFields::sentence(['name']))->toBe('a name')
        ->and(CopyFields::sentence([]))->toBe('');
});
