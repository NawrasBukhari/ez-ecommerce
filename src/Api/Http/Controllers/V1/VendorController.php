<?php

namespace EzEcommerce\Api\Http\Controllers\V1;

use EzEcommerce\Api\Http\Resources\VendorPayoutResource;
use EzEcommerce\Api\Http\Resources\VendorResource;
use EzEcommerce\Marketplace\Actions\PayVendorCommissions;
use EzEcommerce\Marketplace\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class VendorController extends Controller
{
    public function __construct(
        private readonly PayVendorCommissions $payVendorCommissions,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        return VendorResource::collection(
            Vendor::query()->orderBy('name')->paginate(25),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255'],
            'commission_rate' => ['sometimes', 'numeric', 'min:0', 'max:1'],
        ]);

        $validated['slug'] ??= Str::slug($validated['name']);
        $validated['commission_rate'] ??= 0.1;

        $vendor = Vendor::query()->create($validated);

        return (new VendorResource($vendor))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Vendor $vendor): VendorResource
    {
        return new VendorResource($vendor);
    }

    public function payout(Request $request, Vendor $vendor): VendorPayoutResource
    {
        $validated = $request->validate([
            'commission_ids' => ['sometimes', 'array'],
            'commission_ids.*' => ['integer'],
        ]);

        $result = $this->payVendorCommissions->execute(
            $vendor,
            $validated['commission_ids'] ?? null,
        );

        $result['payout']->load('vendor');

        return new VendorPayoutResource($result['payout']);
    }
}
