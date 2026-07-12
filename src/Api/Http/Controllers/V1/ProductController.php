<?php

namespace EzEcommerce\Api\Http\Controllers\V1;

use EzEcommerce\Api\Http\Resources\ProductResource;
use EzEcommerce\Api\Http\Resources\ProductVariantResource;
use EzEcommerce\Catalog\Actions\CreateProductWithVariant;
use EzEcommerce\Catalog\Models\Category;
use EzEcommerce\Catalog\Models\Product;
use EzEcommerce\Marketplace\Models\Vendor;
use EzEcommerce\Stores\Contracts\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

final class ProductController extends Controller
{
    public function __construct(
        private readonly CreateProductWithVariant $createProductWithVariant,
        private readonly StoreContext $storeContext,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Product::query()->with('variants');

        if ($storeId = $this->storeContext->id()) {
            $query->where('store_id', $storeId);
        }

        if ($request->filled('vendor')) {
            $vendorId = Vendor::query()
                ->where('public_id', $request->string('vendor'))
                ->orWhere('slug', $request->string('vendor'))
                ->value('id');
            if ($vendorId !== null) {
                $query->where('vendor_id', $vendorId);
            }
        }

        if ($request->filled('category')) {
            $category = Category::query()
                ->where('public_id', $request->string('category'))
                ->orWhere('slug', $request->string('category'))
                ->first();
            if ($category !== null) {
                $query->whereHas('categories', fn ($q) => $q->where('commerce_categories.id', $category->id));
            }
        }

        return ProductResource::collection(
            $query->orderBy('name')->paginate(),
        );
    }

    public function show(Product $product): ProductResource
    {
        if ($storeId = $this->storeContext->id()) {
            abort_if($product->store_id !== null && $product->store_id !== $storeId, 404);
        }

        $product->load('variants');

        return new ProductResource($product);
    }

    public function variants(Product $product): AnonymousResourceCollection
    {
        if ($storeId = $this->storeContext->id()) {
            abort_if($product->store_id !== null && $product->store_id !== $storeId, 404);
        }

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
            'category_ids' => ['sometimes', 'array'],
            'category_ids.*' => ['string'],
            'variant' => ['required', 'array'],
            'variant.sku' => ['required', 'string', 'max:255'],
            'variant.name' => ['required', 'string', 'max:255'],
            'variant.price_minor' => ['required', 'integer', 'min:0'],
            'variant.sale_price_minor' => ['sometimes', 'integer', 'min:0'],
            'variant.currency' => ['sometimes', 'string', 'size:3'],
        ]);

        $product = $this->createProductWithVariant->execute($validated);

        return (new ProductResource($product))
            ->response()
            ->setStatusCode(201);
    }
}
