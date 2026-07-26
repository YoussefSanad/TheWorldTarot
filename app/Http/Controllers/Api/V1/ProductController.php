<?php

namespace App\Http\Controllers\Api\V1;

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
     * sitting in a list of readings, and is fetched by slug.
     */
    #[QueryParameter('locale', 'The language to read the copy in. Falls back to English.', required: false, type: 'string', example: 'es')]
    #[QueryParameter('currency', 'The currency to price in. Falls back to the visitor\'s country.', required: false, type: 'string', example: 'GBP')]
    public function index(): ProductCollection
    {
        $products = Product::published()
            ->ofType(ProductType::Reading)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return new ProductCollection($products);
    }

    /**
     * One product, found by its slug in the requested language.
     *
     * A slug only ever matches the language it belongs to. An English slug
     * requested in Spanish is a miss, because serving one record under two
     * languages' URLs is how neither of them ranks.
     *
     * Anything not published is a 404 rather than a 403. Whether an unreleased
     * reading exists is not public information.
     */
    #[QueryParameter('locale', 'The language the slug belongs to, and the language the copy is returned in.', required: false, type: 'string', example: 'es')]
    #[QueryParameter('currency', 'The currency to price in. Falls back to the visitor\'s country.', required: false, type: 'string', example: 'GBP')]
    public function show(string $slug): ProductResource
    {
        $product = Product::published()
            ->whereSlug($slug, app()->getLocale())
            ->first();

        if ($product === null) {
            throw new NotFoundHttpException;
        }

        return new ProductResource($product);
    }
}
