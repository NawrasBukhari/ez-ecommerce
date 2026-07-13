<?php

namespace EzEcommerce\Commands;

use EzEcommerce\Payments\Actions\ReconcileVoidAttempt;
use EzEcommerce\Payments\Models\PaymentAttempt;
use EzEcommerce\Payments\Support\PaymentAttemptRequest;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

class ReconcileVoidsCommand extends Command
{
    protected $signature = 'commerce:reconcile-voids
        {attempt? : Payment attempt ID to reconcile}
        {--list : List void attempts requiring reconciliation}
        {--mark-succeeded : Record a provider-confirmed void in the ledger}
        {--mark-failed : Mark an unknown void as failed}
        {--external-id= : Provider void transaction ID (required with --mark-succeeded)}';

    protected $description = 'List or reconcile unknown void attempts';

    public function handle(ReconcileVoidAttempt $reconcileVoidAttempt): int
    {
        if ($this->option('list') || $this->argument('attempt') === null) {
            $attempts = PaymentAttempt::query()
                ->where('operation', 'void')
                ->where('status', 'unknown')
                ->orderBy('id')
                ->get();

            if ($attempts->isEmpty()) {
                $this->components->info('No unknown void attempts.');

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

        if ($attempt->operation !== 'void' || $attempt->status !== 'unknown') {
            throw new RuntimeException('Attempt is not an unknown void.');
        }

        if ($this->option('mark-succeeded')) {
            $externalId = $this->option('external-id');
            if ($externalId === null || $externalId === '') {
                throw new InvalidArgumentException('--mark-succeeded requires --external-id.');
            }

            $reconcileVoidAttempt->confirmProviderSucceeded($attempt, (string) $externalId);
            $this->components->info("Void attempt [{$attempt->id}] recorded in the ledger.");

            return self::SUCCESS;
        }

        if ($this->option('mark-failed')) {
            $reconcileVoidAttempt->markFailed($attempt);
            $this->components->info("Void attempt [{$attempt->id}] marked failed.");

            return self::SUCCESS;
        }

        $this->components->warn('Specify --mark-succeeded or --mark-failed.');

        return self::INVALID;
    }
}