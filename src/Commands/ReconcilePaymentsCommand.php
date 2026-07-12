<?php

namespace EzEcommerce\Commands;

use EzEcommerce\Payments\Actions\CapturePayment;
use EzEcommerce\Payments\Models\PaymentAttempt;
use Illuminate\Console\Command;
use RuntimeException;

class ReconcilePaymentsCommand extends Command
{
    protected $signature = 'commerce:reconcile-payments
        {attempt? : Payment attempt ID to reconcile}
        {--list : List capture attempts requiring reconciliation}
        {--retry : Retry an unknown capture using the same idempotency key}
        {--mark-succeeded : Mark an unknown capture as succeeded without contacting the gateway}
        {--mark-failed : Mark an unknown capture as failed}';

    protected $description = 'List or reconcile unknown payment capture attempts';

    public function handle(CapturePayment $capturePayment): int
    {
        if ($this->option('list') || $this->argument('attempt') === null) {
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

        if ($attempt->operation !== 'capture' || $attempt->status !== 'unknown') {
            throw new RuntimeException('Attempt is not an unknown capture.');
        }

        if ($this->option('mark-succeeded')) {
            $attempt->update(['status' => 'succeeded', 'error_code' => null, 'error_message' => null]);
            $this->components->info("Capture attempt [{$attempt->id}] marked succeeded.");

            return self::SUCCESS;
        }

        if ($this->option('mark-failed')) {
            $attempt->update(['status' => 'failed']);
            $this->components->info("Capture attempt [{$attempt->id}] marked failed.");

            return self::SUCCESS;
        }

        if ($this->option('retry')) {
            $capturePayment->execute($attempt->payment, $attempt);
            $this->components->info("Capture attempt [{$attempt->id}] retried.");

            return self::SUCCESS;
        }

        $this->components->warn('Specify --retry, --mark-succeeded, or --mark-failed.');

        return self::INVALID;
    }
}
