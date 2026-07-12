<?php

namespace EzEcommerce\Api\Http\Controllers\V1;

use EzEcommerce\Api\Http\Resources\ProductResource;
use EzEcommerce\Api\Http\Resources\ProductVariantResource;
use EzEcommerce\Catalog\Actions\CreateProductWithVariant;
use EzEcommerce\Catalog\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

final class ProductController extends Controller
{
    public function __construct(
        private readonly CreateProductWithVariant $createProductWithVariant,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $products = Product::query()
            ->with('variants')
            ->orderBy('name')
            ->paginate();

        return ProductResource::collection($products);
    }

    public function show(Product $product): ProductResource
    {
        $product->load('variants');

        return new ProductResource($product);
    }

    public function variants(Product $product): AnonymousResourceCollection
    {
        return ProductVariantResource::collection($product->variants()->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255'],
            'type' => ['sometimes', 'string', 'max:50'],
            'description' => ['sometimes', 'nullable', 'string'],
            'vendor_id' => ['sometimes', 'nullable', 'string'],
            'stock' => ['sometimes', 'integer', 'min:0'],
            'variant' => ['required', 'array'],
            'variant.sku' => ['required', 'string', 'max:255'],
            'variant.name' => ['required', 'string', 'max:255'],
            'variant.price_minor' => ['required', 'integer', 'min:0'],
            'variant.currency' => ['sometimes', 'string', 'size:3'],
        ]);

        $product = $this->createProductWithVariant->execute($validated);

        return (new ProductResource($product))
            ->response()
            ->setStatusCode(201);
    }
}
