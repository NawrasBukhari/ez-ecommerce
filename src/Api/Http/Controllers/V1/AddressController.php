<?php

namespace EzEcommerce\Api\Http\Controllers\V1;

use EzEcommerce\Api\Http\Resources\AddressResource;
use EzEcommerce\Customers\Models\Address;
use EzEcommerce\Customers\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

final class AddressController extends Controller
{
    public function index(Customer $customer): AnonymousResourceCollection
    {
        return AddressResource::collection(
            $customer->addresses()->orderBy('id')->get(),
        );
    }

    public function store(Request $request, Customer $customer): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['sometimes', 'nullable', 'string', 'max:50'],
            'line1' => ['required', 'string', 'max:255'],
            'line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'state' => ['sometimes', 'nullable', 'string', 'max:255'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'country' => ['required', 'string', 'size:2'],
        ]);

        $address = $customer->addresses()->create([
            'type' => $validated['type'] ?? 'shipping',
            'line1' => $validated['line1'],
            'line2' => $validated['line2'] ?? null,
            'city' => $validated['city'],
            'state' => $validated['state'] ?? null,
            'postal_code' => $validated['postal_code'] ?? null,
            'country_code' => strtoupper($validated['country']),
        ]);

        return (new AddressResource($address))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Customer $customer, Address $address): AddressResource
    {
        $this->assertAddressBelongsToCustomer($customer, $address);

        return new AddressResource($address);
    }

    private function assertAddressBelongsToCustomer(Customer $customer, Address $address): void
    {
        if ((int) $address->customer_id !== (int) $customer->id) {
            abort(404);
        }
    }
}
