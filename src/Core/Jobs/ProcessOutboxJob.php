<?php

namespace EzEcommerce\Core\Jobs;

use EzEcommerce\Core\Events\OrderPaid;
use EzEcommerce\Core\Models\OutboxMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

class ProcessOutboxJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public int $outboxMessageId,
    ) {
    }

    public function handle(): void
    {
        $row = DB::transaction(function () {
            $message = OutboxMessage::query()
                ->lockForUpdate()
                ->find($this->outboxMessageId);

            if ($message === null) {
                return null;
            }

            if ($message->status === 'processed') {
                return null;
            }

            $message->update(['status' => 'processing']);

            return $message;
        });

        if ($row === null) {
            return;
        }

        try {
            $this->dispatchEvent($row);

            $row->update([
                'status' => 'processed',
                'processed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $row->update(['status' => 'failed']);

            throw $e;
        }
    }

    private function dispatchEvent(OutboxMessage $message): void
    {
        $payload = $message->payload instanceof \ArrayObject
            ? $message->payload->getArrayCopy()
            : (array) $message->payload;

        match ($message->event) {
            'order.paid' => Event::dispatch(new OrderPaid(
                $payload['order_id'] ?? 0,
                $payload['order_public_id'] ?? '',
                $payload['payment_id'] ?? 0,
            )),
            default => null,
        };
    }
}
