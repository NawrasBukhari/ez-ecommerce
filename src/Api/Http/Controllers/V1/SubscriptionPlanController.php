<?php

namespace EzEcommerce\Api\Http\Controllers\V1;

use EzEcommerce\Api\Http\Resources\SubscriptionPlanResource;
use EzEcommerce\Core\Enums\SubscriptionInterval;
use EzEcommerce\Subscriptions\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

final class SubscriptionPlanController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return SubscriptionPlanResource::collection(
            SubscriptionPlan::query()->orderBy('name')->paginate(25),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'interval' => ['required', Rule::enum(SubscriptionInterval::class)],
            'interval_count' => ['sometimes', 'integer', 'min:1'],
            'amount_minor' => ['required', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
        ]);

        $plan = SubscriptionPlan::query()->create([
            'name' => $validated['name'],
            'interval' => $validated['interval'],
            'interval_count' => $validated['interval_count'] ?? 1,
            'amount_minor' => $validated['amount_minor'],
            'currency' => strtoupper($validated['currency'] ?? config('ez-ecommerce.currency.default', 'AED')),
        ]);

        return (new SubscriptionPlanResource($plan))
            ->response()
            ->setStatusCode(201);
    }

    public function show(SubscriptionPlan $plan): SubscriptionPlanResource
    {
        return new SubscriptionPlanResource($plan);
    }
}
