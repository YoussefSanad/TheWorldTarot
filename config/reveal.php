<?php

use App\Services\Reveal\FullFilmRevealVideo;

return [

    /*
    |--------------------------------------------------------------------------
    | What the homepage reveal plays
    |--------------------------------------------------------------------------
    |
    | Structure declared in code and arriving by deploy, per CLAUDE.md section 4.
    | Which video the reveal serves is a decision, not a value Jennifer owns, so
    | it lives here rather than in the database.
    |
    | Today it is the full Living Tarot film, which is the film the Viewing Room
    | sells. That was decided knowingly and is expected to change. Replacing it
    | with a preview, or with a purpose-made clip, means writing a class against
    | App\Contracts\RevealVideoSource and naming it here. No migration, no change
    | to the endpoint, and nothing for the frontend to do.
    |
    | Reasoning in docs/plans/reveal-video-plan.md.
    |
    */

    'source' => FullFilmRevealVideo::class,

];
