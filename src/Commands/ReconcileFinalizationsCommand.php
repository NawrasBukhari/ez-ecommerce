<?php

namespace EzEcommerce\Commands;

use EzEcommerce\Payments\Actions\ReconcilePaymentFinalization;
use EzEcommerce\Payments\Models\Payment;
use Illuminate\Console\Command;
use RuntimeException;

class ReconcileFinalizationsCommand extends Command
{
    protected $signature = 'commerce:reconcile-finalizations
        {payment? : Payment ID requiring inventory/order finalization}
        {--list : List captured payments with failed finalization}
        {--complete : Retry inventory commit and order confirmation}';

    protected $description = 'List or complete captured payments whose orders still need finalization';

    public function handle(ReconcilePaymentFinalization $reconcilePaymentFinalization): int
    {
        if ($this->option('list') || $this->argument('payment') === null) {
            $payments = $reconcilePaymentFinalization->pendingPayments();

            if ($payments->isEmpty()) {
                $this->components->info('No captured payments require finalization.');

                return self::SUCCESS;
            }

            $this->table(
                ['payment_id', 'order_id', 'payment_status', 'order_status'],
                $payments->map(fn (Payment $payment) => [
                    $payment->id,
                    $payment->order_id,
                    $payment->status->value,
                    $payment->order?->status->value,
                ]),
            );

            return self::SUCCESS;
        }

        if (! $this->option('complete')) {
            $this->components->warn('Specify --complete to retry finalization.');

            return self::INVALID;
        }

        $payment = Payment::query()->with('order')->findOrFail((int) $this->argument('payment'));

        try {
            $reconcilePaymentFinalization->complete($payment);
        } catch (\Throwable $e) {
            throw new RuntimeException("Finalization failed for payment [{$payment->id}]: {$e->getMessage()}", 0, $e);
        }

        $this->components->info("Payment [{$payment->id}] finalization completed.");

        return self::SUCCESS;
    }
}
