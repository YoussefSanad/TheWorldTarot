<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CustomerResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Who the frontend is talking to.
 *
 * Replaces the framework's scaffolding `/user` route, which returned the model
 * itself. API_CONTRACT.md section 9 already warned the partner that route was
 * not part of the contract and would change when member accounts shipped, so
 * this costs no coordination.
 *
 * It grows in step 4, which adds whether they hold the Viewing Room pass. That
 * is what lets the site choose between "enter the Viewing Room" and "buy the
 * pass" without guessing, while the decision itself stays on this side.
 */
class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return (new CustomerResource($request->user()))
            ->response()
            // Personal, and different for every caller. A shared cache holding
            // this serves one customer's name and address to the next.
            ->header('Cache-Control', 'no-store');
    }
}
