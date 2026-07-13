<?php

namespace EzEcommerce\Core\Jobs;

use EzEcommerce\Core\Contracts\Clock;
use EzEcommerce\Core\Events\OrderPaid;
use EzEcommerce\Core\Models\OutboxMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

class ProcessOutboxJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public int $outboxMessageId,
    ) {
    }

    public function handle(Clock $clock): void
    {
        $lease = (int) config('ez-ecommerce.outbox.lease_seconds', 60);
        $now = $clock->now();
        $token = Str::uuid()->toString();

        // Short claim transaction: lock the row, re-read, claim only if no other
        // worker owns an unexpired lease. The integration event is dispatched
        // OUTSIDE this transaction so a listener failure does not poison the claim.
        $claimed = DB::transaction(function () use ($lease, $now, $token) {
            $message = OutboxMessage::query()
                ->lockForUpdate()
                ->find($this->outboxMessageId);

            if ($message === null) {
                return null;
            }

            // processed and failed_terminal are terminal — never reclaim.
            if (in_array($message->status, ['processed', 'failed_terminal'], true)) {
                return null;
            }

            // Another worker owns an unexpired lease — yield.
            if ($message->status === 'processing'
                && $message->locked_until !== null
                && $message->locked_until > $now) {
                return null;
            }

            // Enforce backoff: a failed_retryable row whose available_at has not
            // elapsed must not be claimed early, even on direct dispatch by ID.
            if ($message->status === 'failed_retryable'
                && $message->available_at !== null
                && $message->available_at > $now) {
                return null;
            }

            $message->update([
                'status' => 'processing',
                'locked_at' => $now,
                'locked_until' => $now->modify("+{$lease} seconds"),
                'lock_token' => $token,
                'attempts' => $message->attempts + 1,
                'last_error' => null,
            ]);

            return $message->fresh();
        });

        if ($claimed === null) {
            return;
        }

        try {
            $this->dispatchEvent($claimed);

            // Complete: verify we still own the current lease before marking
            // processed. A worker whose lease expired must not regress a row
            // that another worker may have already processed.
            DB::transaction(function () use ($clock, $claimed, $token) {
                $message = OutboxMessage::query()->lockForUpdate()->find($claimed->id);
                if ($message === null) {
                    return;
                }
                // Only the current lease owner marks processed. If the token
                // changed, another worker owns the row now.
                if ($message->lock_token !== $token) {
                    return;
                }
                // processed is terminal — don't regress.
                if ($message->status === 'processed') {
                    return;
                }
                $message->update([
                    'status' => 'processed',
                    'processed_at' => $clock->now(),
                    'locked_at' => null,
                    'locked_until' => null,
                    'lock_token' => null,
                    'last_error' => null,
                ]);
            });
        } catch (\Throwable $e) {
            $this->markFailed($clock, $claimed->id, $token, $e->getMessage());

            throw $e;
        }
    }

    private function markFailed(Clock $clock, int $id, string $token, string $error): void
    {
        $maxAttempts = (int) config('ez-ecommerce.outbox.max_attempts', 5);
        $backoff = (int) config('ez-ecommerce.outbox.backoff_seconds', 30);

        DB::transaction(function () use ($clock, $id, $token, $error, $maxAttempts, $backoff) {
            $message = OutboxMessage::query()->lockForUpdate()->find($id);
            if ($message === null) {
                return;
            }

            // Only the current lease owner marks failed. If the token changed,
            // another worker owns the row now — our failure is stale.
            if ($message->lock_token !== $token) {
                return;
            }

            // processed is terminal — a stale worker must not regress it.
            if ($message->status === 'processed') {
                return;
            }

            $retryable = $message->attempts < $maxAttempts;

            $message->update([
                'status' => $retryable ? 'failed_retryable' : 'failed_terminal',
                'available_at' => $retryable ? $clock->now()->modify('+'.($backoff * (2 ** ($message->attempts - 1))).' seconds') : null,
                'locked_at' => null,
                'locked_until' => null,
                'lock_token' => null,
                'last_error' => $error,
            ]);
        });
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
