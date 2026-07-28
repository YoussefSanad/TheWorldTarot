<?php

use App\Models\Card;
use App\Models\Product;

return [

    /*
    |--------------------------------------------------------------------------
    | Translatable models
    |--------------------------------------------------------------------------
    |
    | The models the language gate walks when deciding whether a locale is
    | complete enough to go live. Declared in code because adding a translatable
    | model is a developer act, unlike adding a language, which is a row.
    |
    | Joining this list is the whole of what makes a model translatable in the
    | sense that matters: it gains the completeness calculation, the publish
    | gate, and the observer that takes a language down when something appears
    | on the site untranslated. Cards joined on 28 July 2026 and needed no
    | other wiring.
    |
    */

    'models' => [
        Product::class,
        Card::class,
    ],

];
