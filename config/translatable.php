<?php

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
    | Cards join this list in M3.
    |
    */

    'models' => [
        Product::class,
    ],

];
