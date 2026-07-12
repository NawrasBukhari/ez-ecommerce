<?php

namespace EzEcommerce\Api\Http\Controllers\V1;

use EzEcommerce\Api\Http\Resources\StoreResource;
use EzEcommerce\Stores\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class StoreController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return StoreResource::collection(
            Store::query()->orderBy('name')->paginate(25),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255'],
            'currency' => ['sometimes', 'string', 'size:3'],
        ]);

        $validated['slug'] ??= Str::slug($validated['name']);
        $validated['currency'] ??= config('ez-ecommerce.currency.default', 'AED');

        $store = Store::query()->create($validated);

        return (new StoreResource($store))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Store $store): StoreResource
    {
        return new StoreResource($store);
    }
}
