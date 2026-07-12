<?php

namespace EzEcommerce\Api\Http\Controllers\V1;

use EzEcommerce\Api\Http\Resources\SubscriptionResource;
use EzEcommerce\Customers\Models\Customer;
use EzEcommerce\Subscriptions\Actions\CreateSubscription;
use EzEcommerce\Subscriptions\Models\Subscription;
use EzEcommerce\Subscriptions\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

final class SubscriptionController extends Controller
{
    public function __construct(
        private readonly CreateSubscription $createSubscription,
    ) {
    }

    public function index(): AnonymousResourceCollection
    {
        return SubscriptionResource::collection(
            Subscription::query()->with('plan')->latest()->paginate(25),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'string'],
            'plan_id' => ['required', 'string'],
            'payment_method' => ['sometimes', 'string'],
        ]);

        $customer = Customer::query()
            ->where('public_id', $validated['customer_id'])
            ->firstOrFail();

        $plan = SubscriptionPlan::query()
            ->where('public_id', $validated['plan_id'])
            ->firstOrFail();

        $subscription = $this->createSubscription->execute(
            $customer,
            $plan,
            $validated['payment_method'] ?? 'manual',
        );

        $subscription->load('plan');

        return (new SubscriptionResource($subscription))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Subscription $subscription): SubscriptionResource
    {
        $subscription->load('plan', 'items');

        return new SubscriptionResource($subscription);
    }
}
