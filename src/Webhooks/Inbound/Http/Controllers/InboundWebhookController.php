<?php

namespace EzEcommerce\Webhooks\Inbound\Http\Controllers;

use EzEcommerce\Payments\Actions\ReconcilePayment;
use EzEcommerce\Payments\Data\WebhookRequestData;
use EzEcommerce\Payments\Drivers\PayPalPaymentGateway;
use EzEcommerce\Payments\Exceptions\PaymentDriverNotInstalled;
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
    ) {
    }

    public function __invoke(Request $request, string $gateway): JsonResponse
    {
        $this->assertGatewayAllowed($gateway);

        $payload = $request->getContent();
        $this->verifyInboundAccess($request, $gateway, $payload);

        $event = $this->reconcilePayment->execute(new WebhookRequestData(
            gateway: $gateway,
            payload: $payload,
            headers: $request->headers->all(),
        ));

        return response()->json([
            'received' => true,
            'event_type' => $event->eventType,
            'external_id' => $event->eventId,
        ]);
    }

    private function assertGatewayAllowed(string $gateway): void
    {
        if (in_array($gateway, ['fake', 'null', 'manual'], true)
            && ! app()->environment('local', 'testing')) {
            abort(404);
        }
    }

    private function verifyInboundAccess(Request $request, string $gateway, string $payload): void
    {
        if ($gateway === 'stripe') {
            $secret = config('ez-ecommerce.drivers.payment.stripe.webhook_secret');
            if ($secret === null || $secret === '') {
                abort(403, 'Stripe webhook secret is not configured.');
            }
            $this->verifyStripeSignature($request, $payload, $secret);

            return;
        }

        if ($gateway === 'paypal') {
            $webhookId = config('ez-ecommerce.drivers.payment.paypal.webhook_id');
            if (is_string($webhookId) && $webhookId !== '') {
                $this->verifyPayPalSignature($request, $payload);

                return;
            }
        }

        if (in_array($gateway, ['fake', 'null', 'manual'], true)) {
            return;
        }

        if (config('ez-ecommerce.inbound_webhooks.allow_unsigned', false)) {
            return;
        }

        $shared = config('ez-ecommerce.inbound_webhooks.shared_secret');
        if ($shared === null || $shared === '') {
            abort(403, 'Inbound webhook shared secret is not configured.');
        }

        $provided = $request->header('X-Commerce-Webhook-Secret');
        if (! is_string($provided) || ! hash_equals($shared, $provided)) {
            abort(401, 'Invalid inbound webhook secret.');
        }
    }

    private function verifyStripeSignature(Request $request, string $payload, string $secret): void
    {
        $signature = $request->header('Stripe-Signature');
        if (! is_string($signature) || $signature === '') {
            abort(400, 'Missing Stripe-Signature header.');
        }

        if (! class_exists(Webhook::class)) {
            throw PaymentOperationNotSupported::for('stripe', 'webhook verification without stripe/stripe-php');
        }

        try {
            Webhook::constructEvent($payload, $signature, $secret);
        } catch (\UnexpectedValueException|SignatureVerificationException) {
            abort(400, 'Invalid Stripe webhook signature.');
        }
    }

    private function verifyPayPalSignature(Request $request, string $payload): void
    {
        try {
            app(PayPalPaymentGateway::class)->verifyWebhookSignature($payload, $request);
        } catch (PaymentDriverNotInstalled) {
            abort(403, 'PayPal is not configured.');
        }
    }
}
