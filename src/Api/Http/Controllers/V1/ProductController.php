<?php

namespace EzEcommerce\Api\Http\Controllers\V1;

use EzEcommerce\Api\Http\Resources\ProductResource;
use EzEcommerce\Api\Http\Resources\ProductVariantResource;
use EzEcommerce\Catalog\Models\Product;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

final class ProductController extends Controller
{
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
}
