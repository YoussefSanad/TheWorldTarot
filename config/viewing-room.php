<?php

use App\Services\ViewingRoom\ViewingRoomFilm;

return [

    /*
    |--------------------------------------------------------------------------
    | What the Viewing Room plays
    |--------------------------------------------------------------------------
    |
    | Structure declared in code and arriving by deploy, per CLAUDE.md section 4.
    | Which film a pass buys is a decision, not a value Jennifer owns, so it
    | lives here rather than in the database.
    |
    | Today it is the card's full Living Tarot film, signed for half an hour.
    | Replacing what a pass holder gets means writing a class against
    | App\Contracts\FilmSource and naming it here. No migration, no change to the
    | endpoint, and nothing for the frontend to do.
    |
    | **Deliberately a separate key from config/reveal.php, holding a separate
    | class today.** The two play the same film, which is on record as a knowing
    | decision. They are not the same decision twice: the reveal is public and
    | this is sold, so changing what anybody can get must never reach into what
    | somebody paid for. One key each is what keeps that from being a code
    | change.
    |
    */

    'source' => ViewingRoomFilm::class,

];
