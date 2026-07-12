<?php

namespace EzEcommerce\Api\Http\Controllers\V1;

use EzEcommerce\Api\Http\Resources\CartResource;
use EzEcommerce\Api\Http\Resources\CustomerResource;
use EzEcommerce\B2B\Models\Company;
use EzEcommerce\CommerceManager;
use EzEcommerce\Customers\Models\Customer;
use EzEcommerce\Customers\Models\CustomerGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

final class CustomerController extends Controller
{
    public function __construct(
        private readonly CommerceManager $commerce,
    ) {
    }

    public function index(): AnonymousResourceCollection
    {
        return CustomerResource::collection(
            Customer::query()->with('company')->orderBy('email')->paginate(25),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'first_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'company_id' => ['sometimes', 'nullable', 'string'],
            'customer_group_id' => ['sometimes', 'nullable', 'string'],
        ]);

        $companyId = null;
        if (! empty($validated['company_id'])) {
            $companyId = Company::query()
                ->where('public_id', $validated['company_id'])
                ->value('id');
        }

        $customerGroupId = null;
        if (! empty($validated['customer_group_id'])) {
            $customerGroupId = CustomerGroup::query()
                ->where('public_id', $validated['customer_group_id'])
                ->value('id');
        }

        $customer = Customer::query()->create([
            'email' => $validated['email'],
            'first_name' => $validated['first_name'] ?? null,
            'last_name' => $validated['last_name'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'company_id' => $companyId,
            'customer_group_id' => $customerGroupId,
        ]);

        $customer->load('company', 'customerGroup');

        return (new CustomerResource($customer))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Customer $customer): CustomerResource
    {
        $customer->load(['company', 'addresses']);

        return new CustomerResource($customer);
    }

    public function storeCart(Request $request, Customer $customer): JsonResponse
    {
        $validated = $request->validate([
            'currency' => ['sometimes', 'string', 'size:3'],
        ]);

        $cart = $this->commerce->cart()->createCustomer(
            $customer,
            $validated['currency'] ?? config('ez-ecommerce.currency.default', 'AED'),
        );
        $cart->load('items.purchasable');

        return (new CartResource($cart))
            ->response()
            ->setStatusCode(201);
    }
}
