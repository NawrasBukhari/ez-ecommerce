<?php

namespace EzEcommerce\Api\Http\Controllers\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class ShippingController extends Controller
{
    public function index(): JsonResponse
    {
        $methods = config('ez-ecommerce.shipping.methods', ['flat', 'weight']);

        return response()->json(
            collect($methods)->map(fn (string $method) => [
                'code' => $method,
                'label' => match ($method) {
                    'weight' => 'Weight-based shipping',
                    default => 'Flat rate shipping',
                },
            ])->values(),
        );
    }
}
