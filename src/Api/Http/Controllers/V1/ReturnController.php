<?php

namespace EzEcommerce\Api\Http\Controllers\V1;

use EzEcommerce\Api\Http\Resources\ReturnItemResource;
use EzEcommerce\Api\Http\Resources\ReturnResource;
use EzEcommerce\Inventory\Models\Warehouse;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Returns\Actions\CreateReturnRequest;
use EzEcommerce\Returns\Actions\MarkReturnedItemAsDamaged;
use EzEcommerce\Returns\Actions\ReceiveReturn;
use EzEcommerce\Returns\Actions\RestockReturnedItem;
use EzEcommerce\Returns\Models\ReturnItem;
use EzEcommerce\Returns\Models\ReturnRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

final class ReturnController extends Controller
{
    public function __construct(
        private readonly CreateReturnRequest $createReturnRequest,
        private readonly ReceiveReturn $receiveReturn,
        private readonly RestockReturnedItem $restockReturnedItem,
        private readonly MarkReturnedItemAsDamaged $markReturnedItemAsDamaged,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        return ReturnResource::collection(
            ReturnRequest::query()->with(['order', 'customer', 'items'])->latest()->paginate(25),
        );
    }

    public function store(Request $request, Order $order): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.order_item_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.restock' => ['sometimes', 'boolean'],
        ]);

        $return = $this->createReturnRequest->execute(
            $order,
            $validated['lines'],
            $validated['reason'] ?? null,
        );

        $return->load(['order', 'customer', 'items']);

        return (new ReturnResource($return))
            ->response()
            ->setStatusCode(201);
    }

    public function show(ReturnRequest $return): ReturnResource
    {
        $return->load(['order', 'customer', 'items']);

        return new ReturnResource($return);
    }

    public function receive(ReturnRequest $return): ReturnResource
    {
        $return = $this->receiveReturn->execute($return);
        $return->load(['order', 'customer', 'items']);

        return new ReturnResource($return);
    }

    public function restockItem(Request $request, ReturnRequest $return, ReturnItem $returnItem): ReturnItemResource
    {
        $this->assertReturnItemBelongs($return, $returnItem);

        $validated = $request->validate([
            'warehouse_id' => ['sometimes', 'nullable', 'string'],
            'idempotency_key' => ['sometimes', 'nullable', 'string'],
        ]);

        $warehouse = $this->resolveWarehouse($validated['warehouse_id'] ?? null);

        $item = $this->restockReturnedItem->execute(
            $returnItem,
            $warehouse,
            $validated['idempotency_key']
                ?? $request->header('Idempotency-Key')
                ?? 'return-restock-'.$returnItem->id,
        );

        return new ReturnItemResource($item);
    }

    public function markItemDamaged(ReturnRequest $return, ReturnItem $returnItem): ReturnItemResource
    {
        $this->assertReturnItemBelongs($return, $returnItem);

        $item = $this->markReturnedItemAsDamaged->execute($returnItem);

        return new ReturnItemResource($item);
    }

    private function assertReturnItemBelongs(ReturnRequest $return, ReturnItem $returnItem): void
    {
        if ((int) $returnItem->return_id !== (int) $return->id) {
            abort(404);
        }
    }

    private function resolveWarehouse(?string $publicId): Warehouse
    {
        if (is_string($publicId) && $publicId !== '') {
            return Warehouse::query()->where('public_id', $publicId)->firstOrFail();
        }

        $defaultId = config('ez-ecommerce.inventory.default_warehouse_id');
        if ($defaultId !== null) {
            return Warehouse::query()->findOrFail($defaultId);
        }

        return Warehouse::query()->firstOrFail();
    }
}
