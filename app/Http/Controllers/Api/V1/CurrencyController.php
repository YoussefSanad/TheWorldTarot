<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CurrencyIndexResource;
use App\Support\RequestContext;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\QueryParameter;

class CurrencyController extends Controller
{
    /**
     * The currencies the site sells in, plus the one resolved for this request.
     *
     * The resolution itself already happened in ResolveRequestContext. Reading
     * the decided value rather than detecting again here is what guarantees the
     * currency shown on a product page is the currency charged at checkout.
     */
    // Both are read by ResolveRequestContext rather than by this controller, so
    // they cannot be inferred from the signature and are declared here instead.
    #[QueryParameter(
        'currency',
        'An explicit currency, one of USD, EUR or GBP. Honoured exactly as sent, with no re-detection from the country. A currency we do not sell in is ignored.',
        required: false,
        type: 'string',
        example: 'GBP',
    )]
    #[HeaderParameter(
        'CF-IPCountry',
        'Set by Cloudflare, not by you. Consulted only when no explicit currency is supplied. Absent outside Cloudflare, in which case the default applies.',
        required: false,
        type: 'string',
        example: 'GB',
    )]
    public function __invoke(RequestContext $context): CurrencyIndexResource
    {
        $available = collect(config('currencies.available'))
            ->map(fn (array $currency, string $code) => [
                'code' => $code,
                'symbol' => $currency['symbol'],
            ])
            ->values();

        return new CurrencyIndexResource([
            'available' => $available,
            'detected' => $context->currency,
        ]);
    }
}
