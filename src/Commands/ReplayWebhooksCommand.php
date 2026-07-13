<?php

namespace EzEcommerce\Commands;

use EzEcommerce\Payments\Actions\ReconcilePayment;
use EzEcommerce\Payments\Actions\ReconcileRefund;
use EzEcommerce\Payments\Data\WebhookRequestData;
use EzEcommerce\Payments\PaymentGatewayRegistry;
use EzEcommerce\Webhooks\Inbound\Models\ProcessedGatewayEvent;
use Illuminate\Console\Command;

class ReplayWebhooksCommand extends Command
{
    protected $signature = 'commerce:replay-webhooks
        {event-id? : Specific gateway event id to replay}
        {--unmatched : Replay all unmatched gateway events}';

    protected $description = 'Replay unmatched inbound webhook events whose correlation data arrived later';

    public function handle(
        ReconcilePayment $reconcilePayment,
        ReconcileRefund $reconcileRefund,
        PaymentGatewayRegistry $gateways,
    ): int {
        $eventId = $this->argument('event-id');

        if ($eventId !== null) {
            return $this->replayOne($eventId, $reconcilePayment, $reconcileRefund);
        }

        if ($this->option('unmatched')) {
            return $this->replayAll($reconcilePayment, $reconcileRefund);
        }

        $this->components->warn('Specify an event-id or --unmatched.');

        return self::INVALID;
    }

    private function replayOne(string $eventId, ReconcilePayment $reconcilePayment, ReconcileRefund $reconcileRefund): int
    {
        $record = ProcessedGatewayEvent::query()
            ->where('external_event_id', $eventId)
            ->first();

        if ($record === null) {
            $this->components->warn("No gateway event found with id [{$eventId}].");

            return self::INVALID;
        }

        if ($record->status === 'processed') {
            $this->components->info("Event [{$eventId}] is already processed.");

            return self::SUCCESS;
        }

        return $this->replayRecord($record, $reconcilePayment, $reconcileRefund);
    }

    private function replayAll(ReconcilePayment $reconcilePayment, ReconcileRefund $reconcileRefund): int
    {
        $records = ProcessedGatewayEvent::query()
            ->where('status', 'unmatched')
            ->orderBy('id')
            ->get();

        if ($records->isEmpty()) {
            $this->components->info('No unmatched gateway events to replay.');

            return self::SUCCESS;
        }

        foreach ($records as $record) {
            $this->replayRecord($record, $reconcilePayment, $reconcileRefund);
        }

        return self::SUCCESS;
    }

    private function replayRecord(ProcessedGatewayEvent $record, ReconcilePayment $reconcilePayment, ReconcileRefund $reconcileRefund): int
    {
        // Reset to a state that allows re-entry into the reconciler.
        $record->update(['status' => 'processing']);

        $payload = json_encode($record->payload, JSON_THROW_ON_ERROR);

        $request = new WebhookRequestData(
            gateway: $record->gateway,
            payload: $payload,
        );

        if ($reconcileRefund->isRefundEvent($record->gateway, $record->event_type)) {
            $reconcileRefund->execute($request);
        } else {
            $reconcilePayment->execute($request);
        }

        $this->components->info("Replayed event [{$record->external_event_id}] ({$record->event_type}).");

        return self::SUCCESS;
    }
}
