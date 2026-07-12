<?php

namespace EzEcommerce\Commands;

use EzEcommerce\Payments\Actions\ReconcileCaptureAttempt;
use EzEcommerce\Payments\Models\PaymentAttempt;
use EzEcommerce\Payments\Support\PaymentAttemptRequest;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

class ReconcilePaymentsCommand extends Command
{
    protected $signature = 'commerce:reconcile-payments
        {attempt? : Payment attempt ID to reconcile}
        {--list : List capture attempts requiring reconciliation}
        {--list-stale : List stale pending capture attempts}
        {--retry : Retry an unknown capture using the same idempotency key}
        {--mark-succeeded : Record a provider-confirmed capture in the ledger}
        {--mark-failed : Mark an unknown capture as failed}
        {--amount= : Captured amount in minor units (required with --mark-succeeded)}
        {--currency= : Capture currency (required with --mark-succeeded)}
        {--external-id= : Provider capture transaction ID (required with --mark-succeeded)}';

    protected $description = 'List or reconcile unknown payment capture attempts';

    public function handle(ReconcileCaptureAttempt $reconcileCaptureAttempt): int
    {
        if ($this->option('list') || $this->option('list-stale') || $this->argument('attempt') === null) {
            if ($this->option('list-stale')) {
                $attempts = PaymentAttempt::query()
                    ->where('operation', 'capture')
                    ->where('status', 'pending')
                    ->where('updated_at', '<', now()->subMinutes(15))
                    ->orderBy('id')
                    ->get();

                if ($attempts->isEmpty()) {
                    $this->components->info('No stale pending capture attempts.');

                    return self::SUCCESS;
                }

                $this->table(
                    ['id', 'payment_id', 'idempotency_key', 'updated_at'],
                    $attempts->map(fn (PaymentAttempt $attempt) => [
                        $attempt->id,
                        $attempt->payment_id,
                        $attempt->idempotency_key,
                        $attempt->updated_at,
                    ]),
                );

                return self::SUCCESS;
            }

            $attempts = PaymentAttempt::query()
                ->where('operation', 'capture')
                ->where('status', 'unknown')
                ->orderBy('id')
                ->get();

            if ($attempts->isEmpty()) {
                $this->components->info('No unknown capture attempts.');

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

        if ($attempt->operation !== 'capture' || $attempt->status !== 'unknown') {
            throw new RuntimeException('Attempt is not an unknown capture.');
        }

        if ($this->option('mark-succeeded')) {
            [$amountMinor, $currency, $externalId] = $this->verifiedProviderCapture();

            $reconcileCaptureAttempt->confirmProviderSucceeded($attempt, $amountMinor, $currency, $externalId);
            $this->components->info("Capture attempt [{$attempt->id}] recorded in the ledger.");

            return self::SUCCESS;
        }

        if ($this->option('mark-failed')) {
            $attempt->update(['status' => 'failed']);
            $this->components->info("Capture attempt [{$attempt->id}] marked failed.");

            return self::SUCCESS;
        }

        if ($this->option('retry')) {
            $reconcileCaptureAttempt->retry($attempt);
            $this->components->info("Capture attempt [{$attempt->id}] retried.");

            return self::SUCCESS;
        }

        $this->components->warn('Specify --retry, --mark-succeeded, or --mark-failed.');

        return self::INVALID;
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function verifiedProviderCapture(): array
    {
        $amount = $this->option('amount');
        $currency = $this->option('currency');
        $externalId = $this->option('external-id');

        if ($amount === null || $currency === null || $externalId === null || $externalId === '') {
            throw new InvalidArgumentException('--mark-succeeded requires --amount, --currency, and --external-id.');
        }

        return [(int) $amount, (string) $currency, (string) $externalId];
    }
}
