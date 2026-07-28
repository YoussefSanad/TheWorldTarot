<?php

use App\Support\Locales;
use Tests\TestCase;

pest()->extend(TestCase::class)
    // The locale registry is read once and cached for the request. That cache
    // is static, so without this it survives from one test into the next and a
    // language looks live because an earlier test made it so.
    //
    // Production is unaffected, since each request starts a new process, but a
    // test that changes a locale after making a request still has to forget it
    // explicitly.
    ->beforeEach(fn () => Locales::forget())
    ->in('Feature');
