<?php

/**
 * Spanish copy for the Major Arcana, keyed by card `number`, 0 to 21.
 *
 * Keyed by number and not by name, deliberately: the name is Jennifer's to edit
 * in the panel, and a lookup on it breaks the day she does.
 *
 * ## Why there are only names here
 *
 * **The English descriptions do not exist yet.** `CardSeeder` seeds names only
 * and leaves `short_description` and `long_description` empty on purpose. A
 * card is on the website once it is written, and a freshly seeded deck is
 * invisible without anything having to be switched off. Checked against the
 * development database on 9 September 2026: 22 cards, 0 with any English
 * description.
 *
 * So there is nothing to translate. Writing Spanish descriptions here would not
 * be translation, it would be inventing this business's card copy in a language
 * its owner cannot check. And because availability is calculated per language,
 * the result would be a **Spanish library holding 22 cards and an English one
 * holding none.** That is a worse failure than the one it would fix.
 *
 * **These 44 strings are Jennifer's to write, in English, first.** The seeder
 * reports them rather than throwing, because a field with no English is not a
 * gap in this file. Until they exist, Spanish cannot go live: every published
 * card counts against the gate whether or not a customer can reach it today,
 * and the Languages screen will name each one.
 *
 * The names below are the established Spanish titles for the Rider-Waite deck,
 * matching the numbering `CardSeeder` uses: Strength at 8, Justice at 11.
 *
 * @return array<int, array<string, string>>
 */
return [
    0 => ['name' => 'El Loco'],
    1 => ['name' => 'El Mago'],
    2 => ['name' => 'La Suma Sacerdotisa'],
    3 => ['name' => 'La Emperatriz'],
    4 => ['name' => 'El Emperador'],
    5 => ['name' => 'El Hierofante'],
    6 => ['name' => 'Los Enamorados'],
    7 => ['name' => 'El Carro'],
    8 => ['name' => 'La Fuerza'],
    9 => ['name' => 'El Ermitaño'],
    10 => ['name' => 'La Rueda de la Fortuna'],
    11 => ['name' => 'La Justicia'],
    12 => ['name' => 'El Colgado'],
    13 => ['name' => 'La Muerte'],
    14 => ['name' => 'La Templanza'],
    15 => ['name' => 'El Diablo'],
    16 => ['name' => 'La Torre'],
    17 => ['name' => 'La Estrella'],
    18 => ['name' => 'La Luna'],
    19 => ['name' => 'El Sol'],
    20 => ['name' => 'El Juicio'],
    21 => ['name' => 'El Mundo'],
];
