<?php

namespace EzEcommerce\Commands;

use EzEcommerce\Payments\Models\PaymentAttempt;
use EzEcommerce\Payments\Support\PaymentAttemptRequest;
use EzEcommerce\Refunds\Actions\ReconcileRefundAttempt;
use EzEcommerce\Refunds\Models\Refund;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

class ReconcileRefundsCommand extends Command
{
    protected $signature = 'commerce:reconcile-refunds
        {attempt? : Payment attempt ID to reconcile}
        {--list : List refund attempts requiring reconciliation}
        {--retry : Retry an unknown refund using the same idempotency key}
        {--mark-succeeded : Record a provider-confirmed refund in the ledger}
        {--mark-failed : Mark an unknown refund as failed}
        {--external-id= : Provider refund transaction ID (required with --mark-succeeded)}';

    protected $description = 'List or reconcile unknown refund attempts';

    public function handle(ReconcileRefundAttempt $reconcileRefundAttempt): int
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
                ['id', 'payment_id', 'amount_minor', 'currency', 'idempotency_key', 'error_code', 'updated_at'],
                $attempts->map(fn (PaymentAttempt $attempt) => [
                    $attempt->id,
                    $attempt->payment_id,
                    PaymentAttemptRequest::metadata($attempt)['requested_amount_minor'] ?? null,
                    PaymentAttemptRequest::metadata($attempt)['currency'] ?? null,
                    $attempt->idempotency_key,
                    $attempt->error_code,
                    $attempt->updated_at,
                ]),
            );

            return self::SUCCESS;
        }

        $attempt = PaymentAttempt::query()->with('payment')->findOrFail((int) $this->argument('attempt'));

        if ($attempt->operation !== 'refund' || $attempt->status !== 'unknown') {
            throw new RuntimeException('Attempt is not an unknown refund.');
        }

        if ($this->option('mark-succeeded')) {
            $externalId = $this->option('external-id');
            if ($externalId === null || $externalId === '') {
                throw new InvalidArgumentException('--mark-succeeded requires --external-id.');
            }

            $reconcileRefundAttempt->confirmProviderSucceeded($attempt, (string) $externalId);
            $this->components->info("Refund attempt [{$attempt->id}] recorded in the ledger.");

            return self::SUCCESS;
        }

        if ($this->option('mark-failed')) {
            $refundId = $this->refundIdFromAttempt($attempt);

            if ($refundId !== null) {
                Refund::query()->whereKey($refundId)->update(['status' => 'failed']);
            }

            $attempt->update(['status' => 'failed']);
            $this->components->info("Refund attempt [{$attempt->id}] marked failed.");

            return self::SUCCESS;
        }

        if ($this->option('retry')) {
            $reconcileRefundAttempt->retry($attempt);
            $this->components->info("Refund attempt [{$attempt->id}] retried.");

            return self::SUCCESS;
        }

        $this->components->warn('Specify --retry, --mark-succeeded, or --mark-failed.');

        return self::INVALID;
    }

    private function refundIdFromAttempt(PaymentAttempt $attempt): ?int
    {
        $refundId = PaymentAttemptRequest::metadata($attempt)['refund_id'] ?? null;

        return $refundId === null ? null : (int) $refundId;
    }
}
