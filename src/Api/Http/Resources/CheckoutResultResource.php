<?php

namespace EzEcommerce\Api\Http\Resources;

use EzEcommerce\Checkout\CheckoutResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CheckoutResult */
final class CheckoutResultResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->status->value,
            'order' => new OrderResource($this->order->loadMissing('items', 'payments')),
            'payment' => new PaymentResource($this->payment),
            'requires_customer_action' => $this->requiresCustomerAction(),
            'payment_failure' => $this->paymentFailure ? [
                'code' => $this->paymentFailure->code,
                'message' => $this->paymentFailure->message,
            ] : null,
        ];
    }
}
