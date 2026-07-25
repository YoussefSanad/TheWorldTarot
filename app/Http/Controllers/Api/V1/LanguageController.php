<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\LanguageCollection;
use App\Models\Locale;

class LanguageController extends Controller
{
    /**
     * The languages the public site can be read in.
     *
     * Live locales only. A locale still being translated, or one under review,
     * must never reach this list: the frontend uses it to decide whether to
     * render the language selector at all, so listing an unfinished language
     * would put a half translated site one click away.
     */
    public function __invoke(): LanguageCollection
    {
        $locales = Locale::live()
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();

        return new LanguageCollection($locales);
    }
}
