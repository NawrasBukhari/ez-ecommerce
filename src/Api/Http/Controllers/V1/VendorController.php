<?php

namespace EzEcommerce\Api\Http\Controllers\V1;

use EzEcommerce\Api\Http\Resources\VendorCommissionResource;
use EzEcommerce\Api\Http\Resources\VendorPayoutResource;
use EzEcommerce\Api\Http\Resources\VendorResource;
use EzEcommerce\Marketplace\Actions\PayVendorCommissions;
use EzEcommerce\Marketplace\Models\Vendor;
use EzEcommerce\Marketplace\Models\VendorCommission;
use EzEcommerce\Marketplace\Models\VendorPayout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class VendorController extends Controller
{
    public function __construct(
        private readonly PayVendorCommissions $payVendorCommissions,
    ) {
    }

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

    public function commissions(Request $request, Vendor $vendor): AnonymousResourceCollection
    {
        $query = VendorCommission::query()
            ->where('vendor_id', $vendor->id)
            ->with(['order', 'payout'])
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return VendorCommissionResource::collection($query->paginate(25));
    }

    public function payouts(Vendor $vendor): AnonymousResourceCollection
    {
        return VendorPayoutResource::collection(
            VendorPayout::query()
                ->where('vendor_id', $vendor->id)
                ->latest('paid_at')
                ->paginate(25),
        );
    }

    public function showPayout(Vendor $vendor, VendorPayout $payout): VendorPayoutResource
    {
        abort_if($payout->vendor_id !== $vendor->id, 404);

        $payout->load(['vendor', 'commissions.order']);

        return new VendorPayoutResource($payout);
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
