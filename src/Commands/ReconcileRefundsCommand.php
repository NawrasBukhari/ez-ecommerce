<?php

namespace EzEcommerce\Commands;

use EzEcommerce\Payments\Models\PaymentAttempt;
use EzEcommerce\Refunds\Actions\RefundPayment;
use EzEcommerce\Refunds\Models\Refund;
use Illuminate\Console\Command;
use RuntimeException;

class ReconcileRefundsCommand extends Command
{
    protected $signature = 'commerce:reconcile-refunds
        {attempt? : Payment attempt ID to reconcile}
        {--list : List refund attempts requiring reconciliation}
        {--retry : Retry an unknown refund using the same idempotency key}
        {--mark-succeeded : Mark an unknown refund as succeeded without contacting the gateway}
        {--mark-failed : Mark an unknown refund as failed}';

    protected $description = 'List or reconcile unknown refund attempts';

    public function handle(RefundPayment $refundPayment): int
    {
        if ($this->option('list') || $this->argument('attempt') === null) {
            $attempts = PaymentAttempt::query()
                ->where('operation', 'refund')
                ->where('status', 'unknown')
                ->orderBy('id')
                ->get();

            if ($attempts->isEmpty()) {
                $this->components->info('No unknown refund attempts.');

                return self::SUCCESS;
            }

            $this->table(
                ['id', 'payment_id', 'idempotency_key', 'error_code', 'updated_at'],
                $attempts->map(fn (PaymentAttempt $attempt) => [
                    $attempt->id,
                    $attempt->payment_id,
                    $attempt->idempotency_key,
                    $attempt->error_code,
                    $attempt->updated_at,
                ]),
            );

            return self::SUCCESS;
        }

        $attempt = PaymentAttempt::query()->findOrFail((int) $this->argument('attempt'));

        if ($attempt->operation !== 'refund' || $attempt->status !== 'unknown') {
            throw new RuntimeException('Attempt is not an unknown refund.');
        }

        if ($this->option('mark-succeeded')) {
            $attempt->update(['status' => 'succeeded', 'error_code' => null, 'error_message' => null]);
            $this->components->info("Refund attempt [{$attempt->id}] marked succeeded.");

            return self::SUCCESS;
        }

        if ($this->option('mark-failed')) {
            $refundId = $attempt->request_metadata instanceof \ArrayObject
                ? $attempt->request_metadata['refund_id'] ?? null
                : ($attempt->request_metadata['refund_id'] ?? null);

            if ($refundId !== null) {
                Refund::query()->whereKey((int) $refundId)->update(['status' => 'failed']);
            }

            $attempt->update(['status' => 'failed']);
            $this->components->info("Refund attempt [{$attempt->id}] marked failed.");

            return self::SUCCESS;
        }

        if ($this->option('retry')) {
            $refundPayment->retryUnknown($attempt);
            $this->components->info("Refund attempt [{$attempt->id}] retried.");

            return self::SUCCESS;
        }

        $this->components->warn('Specify --retry, --mark-succeeded, or --mark-failed.');

        return self::INVALID;
    }
}
