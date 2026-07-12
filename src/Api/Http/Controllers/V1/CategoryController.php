<?php

namespace EzEcommerce\Api\Http\Controllers\V1;

use EzEcommerce\Api\Http\Resources\CategoryResource;
use EzEcommerce\Api\Http\Resources\ProductResource;
use EzEcommerce\Catalog\Models\Category;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

final class CategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return CategoryResource::collection(
            Category::query()->with('parent')->orderBy('name')->paginate(25),
        );
    }

    public function show(Category $category): CategoryResource
    {
        $category->load('parent');

        return new CategoryResource($category);
    }

    public function products(Category $category): AnonymousResourceCollection
    {
        return ProductResource::collection(
            $category->products()->with('variants')->orderBy('name')->paginate(25),
        );
    }
}
