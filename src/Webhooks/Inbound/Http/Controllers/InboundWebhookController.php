<?php

namespace EzEcommerce\Webhooks\Inbound\Http\Controllers;

use EzEcommerce\Payments\Actions\ReconcilePayment;
use EzEcommerce\Payments\Data\WebhookRequestData;
use EzEcommerce\Payments\Exceptions\PaymentOperationNotSupported;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

final class InboundWebhookController extends Controller
{
    public function __construct(
        private readonly ReconcilePayment $reconcilePayment,
    ) {}

    public function __invoke(Request $request, string $gateway): JsonResponse
    {
        $payload = $request->getContent();

        if ($gateway === 'stripe') {
            $this->verifyStripeSignature($request, $payload);
        }

        $event = $this->reconcilePayment->execute(new WebhookRequestData(
            gateway: $gateway,
            payload: $payload,
            headers: $request->headers->all(),
        ));

        return response()->json([
            'received' => true,
            'event_type' => $event->eventType,
            'external_id' => $event->externalId,
        ]);
    }

    private function verifyStripeSignature(Request $request, string $payload): void
    {
        $secret = config('ez-ecommerce.drivers.payment.stripe.webhook_secret');
        if ($secret === null || $secret === '') {
            return;
        }

        $signature = $request->header('Stripe-Signature');
        if (! is_string($signature) || $signature === '') {
            abort(400, 'Missing Stripe-Signature header.');
        }

        if (! class_exists(Webhook::class)) {
            throw PaymentOperationNotSupported::for('stripe', 'webhook verification without stripe/stripe-php');
        }

        try {
            Webhook::constructEvent($payload, $signature, $secret);
        } catch (\UnexpectedValueException|SignatureVerificationException $e) {
            abort(400, 'Invalid Stripe webhook signature.');
        }
    }
}
