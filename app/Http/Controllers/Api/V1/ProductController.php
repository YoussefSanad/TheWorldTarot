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
     * The readings on sale.
     *
     * Published readings only, in the order Jennifer arranged them. The
     * viewing room pass is not listed here: it has its own page rather than
     * sitting in a list of readings, and is fetched by its own key.
     */
    #[QueryParameter('locale', 'The language to read the copy in. Falls back to English.', required: false, type: 'string', example: 'es')]
    #[QueryParameter('currency', 'The currency to price in. Falls back to the visitor\'s country.', required: false, type: 'string', example: 'GBP')]
    public function index(): ProductCollection
    {
        // Only what is finished in this language. A product still being
        // translated is absent from that language rather than appearing in
        // English among Spanish ones.
        $products = Product::published()
            ->ofType(ProductType::Reading)
            ->completeIn(app()->getLocale())
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
    #[QueryParameter('locale', 'The language to read the copy in. Falls back to English.', required: false, type: 'string', example: 'es')]
    #[QueryParameter('currency', 'The currency to price in. Falls back to the visitor\'s country.', required: false, type: 'string', example: 'GBP')]
    public function show(string $key): ProductResource
    {
        $productKey = ProductKey::tryFrom($key);

        if ($productKey === null) {
            throw new NotFoundHttpException;
        }

        $product = Product::published()
            ->completeIn(app()->getLocale())
            ->where('key', $productKey)
            ->first();

        if ($product === null) {
            throw new NotFoundHttpException;
        }

        return new ProductResource($product);
    }
}
