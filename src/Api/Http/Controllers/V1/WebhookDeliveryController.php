<?php

namespace EzEcommerce\Api\Http\Controllers\V1;

use EzEcommerce\Core\Enums\WebhookDeliveryStatus;
use EzEcommerce\Webhooks\Outbound\Jobs\DeliverWebhookJob;
use EzEcommerce\Webhooks\Outbound\Models\WebhookDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class WebhookDeliveryController extends Controller
{
    public function retry(WebhookDelivery $webhookDelivery): JsonResponse
    {
        if ($webhookDelivery->status === WebhookDeliveryStatus::Delivered) {
            abort(422, 'Delivered webhooks cannot be retried.');
        }

        $endpoint = $webhookDelivery->endpoint;
        if ($endpoint === null) {
            abort(422, 'Delivery has no endpoint.');
        }

        DeliverWebhookJob::dispatch(
            $endpoint->url,
            $webhookDelivery->event,
            $webhookDelivery->payload instanceof \ArrayObject
                ? $webhookDelivery->payload->getArrayCopy()
                : (array) $webhookDelivery->payload,
            $endpoint->id,
            $endpoint->secret,
        );

        $webhookDelivery->update([
            'status' => WebhookDeliveryStatus::Pending,
            'attempts' => $webhookDelivery->attempts + 1,
        ]);

        return response()->json(['status' => 'queued']);
    }
}
