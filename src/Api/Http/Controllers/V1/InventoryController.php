<?php

namespace EzEcommerce\Api\Http\Controllers\V1;

use EzEcommerce\Api\Http\Resources\InventoryBalanceResource;
use EzEcommerce\Api\Http\Resources\WarehouseResource;
use EzEcommerce\Catalog\Models\ProductVariant;
use EzEcommerce\CommerceManager;
use EzEcommerce\Inventory\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

final class InventoryController extends Controller
{
    public function __construct(
        private readonly CommerceManager $commerce,
    ) {}

    public function indexWarehouses(): AnonymousResourceCollection
    {
        return WarehouseResource::collection(
            Warehouse::query()->orderBy('name')->paginate(25),
        );
    }

    public function storeWarehouse(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:64'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $warehouse = Warehouse::query()->create([
            'name' => $validated['name'],
            'code' => $validated['code'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return (new WarehouseResource($warehouse))
            ->response()
            ->setStatusCode(201);
    }

    public function showWarehouse(Warehouse $warehouse): WarehouseResource
    {
        return new WarehouseResource($warehouse);
    }

    public function receiveStock(Request $request, Warehouse $warehouse): InventoryBalanceResource
    {
        $validated = $request->validate([
            'variant_id' => ['required', 'string'],
            'quantity' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['sometimes', 'nullable', 'string'],
        ]);

        $variant = ProductVariant::query()
            ->where('public_id', $validated['variant_id'])
            ->firstOrFail();

        $balance = $this->commerce->inventory()->receiveStock(
            $warehouse,
            $variant,
            $validated['quantity'],
            $validated['idempotency_key']
                ?? $request->header('Idempotency-Key')
                ?? 'api-receive-'.$variant->public_id,
        );

        $balance->load('warehouse');

        return new InventoryBalanceResource($balance);
    }
}
