<?php

namespace EzEcommerce\Api\Http\Controllers\V1;

use EzEcommerce\Api\Http\Resources\ProcessedGatewayEventResource;
use EzEcommerce\Webhooks\Inbound\Models\ProcessedGatewayEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

final class ProcessedGatewayEventController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = ProcessedGatewayEvent::query()->latest('processed_at');

        if ($request->filled('gateway')) {
            $query->where('gateway', $request->string('gateway'));
        }

        if ($request->filled('external_event_id')) {
            $query->where('external_event_id', $request->string('external_event_id'));
        }

        return ProcessedGatewayEventResource::collection($query->paginate(25));
    }
}
