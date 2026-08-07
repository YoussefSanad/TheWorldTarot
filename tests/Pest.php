<?php

use App\Support\Locales;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->beforeEach(function (): void {
        // The locale registry is read once and cached for the request. That
        // cache is static, so without this it survives from one test into the
        // next and a language looks live because an earlier test made it so.
        //
        // Production is unaffected, since each request starts a new process, but
        // a test that changes a locale after making a request still has to
        // forget it explicitly.
        Locales::forget();

        // **No test reaches a third party, and one that tries fails loudly.**
        //
        // A handful of files asked for this and most did not, which turned out
        // not to be theoretical. The queue runs inline here, so a job dispatched
        // from a model event runs inside the test that saved the model, and when
        // `promoteIncomingVideo` learned to delete the video it supersedes, the
        // promotion test began sending a real DELETE to the real Bunny library.
        // It stayed green, because the guid was a factory uuid, Bunny answered
        // 404, and a 404 counts as deleted. It only surfaced on a machine with
        // no `BUNNY_API_KEY`, where it then failed for entirely the wrong reason.
        //
        // Green while quietly talking to a live third party is the worst failure
        // mode this suite has, and it is the second time this project has hit
        // it. Global rather than per file, so it cannot be forgotten again.
        Http::preventStrayRequests();
    })
    ->in('Feature');
