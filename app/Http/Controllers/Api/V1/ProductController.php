<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProductKey;
use App\Enums\ProductType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProductCollection;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\Product;
use Dedoc\Scramble\Attributes\QueryParameter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ProductController extends Controller
{
    /**
     * Everything on sale.
     *
     * In the order Jennifer arranged them, and only what a customer in this
     * language can actually buy.
     *
     * **The viewing room pass is listed here alongside the readings.** It was
     * excluded until 20 August 2026, on the grounds that a pass has a page of
     * its own rather than belonging in a list of readings. That was the wrong
     * cut: the homepage's Choose Your Journey section sells all four things
     * side by side, so excluding one meant the frontend fetching it separately
     * to rebuild a list this endpoint could have returned whole.
     *
     * `type` distinguishes them, which is what it is for.
     *
     * **Add-ons are still excluded**, and explicitly rather than by omission.
     * Nothing sells an add-on on its own: the rush upgrade is bought alongside
     * a reading, so it has no page and no place in a list of things to buy.
     * It has no row yet either, which is exactly why the exclusion is written
     * now: adding `Rush24h` to `ProductKey::seedable()` in M5 would otherwise
     * put it on the homepage, and the person doing that has no reason to look
     * here.
     */
    #[QueryParameter('currency', 'The currency to price in. Falls back to the visitor\'s country.', required: false, type: 'string', example: 'GBP')]
    public function index(): ProductCollection
    {
        // Only what a customer in this language can actually see: copy in
        // their language, and a real price in every currency.
        $products = Product::availableIn(app()->getLocale())
            ->whereIn('type', [ProductType::Reading, ProductType::Pass])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return new ProductCollection($products);
    }

    /**
     * One product, found by its key.
     *
     * The key is the same in every language, so a product has one address
     * rather than one per locale, and renaming a reading can never break a
     * link.
     *
     * Anything not published is a 404 rather than a 403. Whether an unreleased
     * reading exists is not public information, and so is whether a key outside
     * the catalogue means anything.
     */
    #[QueryParameter('currency', 'The currency to price in. Falls back to the visitor\'s country.', required: false, type: 'string', example: 'GBP')]
    public function show(string $locale, string $key): ProductResource
    {
        // Route parameters reach a controller in the order they appear in the
        // path, not by matching names, so the language segment has to be taken
        // even though the middleware has already dealt with it. Leaving it out
        // silently passes "en" as the product key.
        $productKey = ProductKey::tryFrom($key);

        if ($productKey === null) {
            throw new NotFoundHttpException;
        }

        $product = Product::availableIn(app()->getLocale())
            ->where('key', $productKey)
            ->first();

        if ($product === null) {
            throw new NotFoundHttpException;
        }

        return new ProductResource($product);
    }
}
