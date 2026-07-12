<?php

namespace EzEcommerce\Api\Http\Controllers\V1;

use EzEcommerce\Api\Http\Resources\CustomerGroupResource;
use EzEcommerce\Customers\Models\CustomerGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class CustomerGroupController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return CustomerGroupResource::collection(
            CustomerGroup::query()->orderBy('name')->paginate(25),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $group = CustomerGroup::query()->create([
            'name' => $validated['name'],
            'code' => $validated['code'] ?? Str::slug($validated['name']),
        ]);

        return (new CustomerGroupResource($group))
            ->response()
            ->setStatusCode(201);
    }

    public function show(CustomerGroup $customerGroup): CustomerGroupResource
    {
        return new CustomerGroupResource($customerGroup);
    }
}
