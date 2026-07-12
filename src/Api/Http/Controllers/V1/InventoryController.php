<?php

namespace EzEcommerce\Api\Http\Controllers\V1;

use EzEcommerce\Api\Http\Resources\InventoryBalanceResource;
use EzEcommerce\Api\Http\Resources\InventoryMovementResource;
use EzEcommerce\Api\Http\Resources\WarehouseResource;
use EzEcommerce\Catalog\Models\ProductVariant;
use EzEcommerce\CommerceManager;
use EzEcommerce\Inventory\Actions\AdjustStock;
use EzEcommerce\Inventory\Actions\ReleaseInventoryReservation;
use EzEcommerce\Inventory\Actions\TransferStock;
use EzEcommerce\Inventory\Models\InventoryMovement;
use EzEcommerce\Inventory\Models\InventoryReservation;
use EzEcommerce\Inventory\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

final class InventoryController extends Controller
{
    public function __construct(
        private readonly CommerceManager $commerce,
        private readonly ReleaseInventoryReservation $releaseInventoryReservation,
        private readonly TransferStock $transferStock,
        private readonly AdjustStock $adjustStock,
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

    public function deactivateWarehouse(Warehouse $warehouse): WarehouseResource
    {
        $warehouse->update(['is_active' => false]);

        return new WarehouseResource($warehouse->fresh());
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

    public function adjustStock(Request $request, Warehouse $warehouse): InventoryBalanceResource
    {
        $validated = $request->validate([
            'variant_id' => ['required', 'string'],
            'delta' => ['required', 'integer', 'not_in:0'],
            'idempotency_key' => ['sometimes', 'nullable', 'string'],
        ]);

        $variant = ProductVariant::query()
            ->where('public_id', $validated['variant_id'])
            ->firstOrFail();

        $balance = $this->adjustStock->execute(
            $warehouse,
            $variant,
            $validated['delta'],
            $validated['idempotency_key']
                ?? $request->header('Idempotency-Key')
                ?? 'api-adjust-'.$variant->public_id,
        );

        $balance->load('warehouse');

        return new InventoryBalanceResource($balance);
    }

    public function transferStock(Request $request, Warehouse $warehouse): JsonResponse
    {
        $validated = $request->validate([
            'to_warehouse_id' => ['required', 'string'],
            'variant_id' => ['required', 'string'],
            'quantity' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['sometimes', 'nullable', 'string'],
        ]);

        $to = Warehouse::query()
            ->where('public_id', $validated['to_warehouse_id'])
            ->firstOrFail();

        $variant = ProductVariant::query()
            ->where('public_id', $validated['variant_id'])
            ->firstOrFail();

        $this->transferStock->execute(
            $warehouse,
            $to,
            $variant,
            $validated['quantity'],
            $validated['idempotency_key']
                ?? $request->header('Idempotency-Key')
                ?? 'api-transfer-'.$variant->public_id,
        );

        return response()->json(['status' => 'transferred'], 201);
    }

    public function movements(Warehouse $warehouse): AnonymousResourceCollection
    {
        $balanceIds = $warehouse->balances()->pluck('id');

        return InventoryMovementResource::collection(
            InventoryMovement::query()
                ->whereIn('balance_id', $balanceIds)
                ->latest()
                ->paginate(25),
        );
    }

    public function releaseReservation(InventoryReservation $reservation): JsonResponse
    {
        $this->releaseInventoryReservation->execute($reservation);

        return response()->json(['status' => 'released']);
    }
}
