<?php

namespace EzEcommerce\Commands;

use EzEcommerce\Core\Enums\PaymentTransactionType;
use EzEcommerce\Payments\Models\PaymentTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DedupeTransactionsCommand extends Command
{
    protected $signature = 'commerce:dedupe-transactions
        {--dry-run : Report duplicates without deleting}
        {--outbox : Dedupe outbox messages by key instead}';

    protected $description = 'Remove duplicate payment transactions (or outbox keys) before the unique constraint is enforced';

    public function handle(): int
    {
        if ($this->option('outbox')) {
            return $this->dedupeOutbox();
        }

        return $this->dedupeTransactions();
    }

    private function dedupeTransactions(): int
    {
        $duplicates = PaymentTransaction::query()
            ->select('payment_id', 'type', 'external_id', DB::raw('MIN(id) as keep_id'), DB::raw('COUNT(*) as cnt'))
            ->whereNotNull('external_id')
            ->groupBy('payment_id', 'type', 'external_id')
            ->having('cnt', '>', 1)
            ->get();

        if ($duplicates->isEmpty()) {
            $this->components->info('No duplicate payment transactions found.');

            return self::SUCCESS;
        }

        $totalToDelete = 0;
        foreach ($duplicates as $dup) {
            $totalToDelete += (int) $dup->getAttribute('cnt') - 1;
            $dup->setAttribute('keep_id', (int) $dup->getAttribute('keep_id'));
        }

        $this->components->warn("Found {$duplicates->count()} duplicate groups ({$totalToDelete} extra rows).");

        if ($this->option('dry-run')) {
            $this->table(
                ['payment_id', 'type', 'external_id', 'duplicates', 'keep_id'],
                $duplicates->map(fn ($dup) => [
                    $dup->payment_id,
                    $dup->type instanceof PaymentTransactionType ? $dup->type->value : (string) $dup->type,
                    $dup->external_id,
                    $dup->cnt - 1,
                    $dup->keep_id,
                ]),
            );

            return self::SUCCESS;
        }

        foreach ($duplicates as $dup) {
            PaymentTransaction::query()
                ->where('payment_id', $dup->payment_id)
                ->where('type', $dup->type)
                ->where('external_id', $dup->external_id)
                ->where('id', '!=', $dup->keep_id)
                ->delete();
        }

        $this->components->info("Deleted {$totalToDelete} duplicate payment transactions.");

        return self::SUCCESS;
    }

    private function dedupeOutbox(): int
    {
        if (! DB::getSchemaBuilder()->hasColumn('commerce_outbox_messages', 'key')) {
            $this->components->info('Outbox key column does not exist yet; nothing to dedupe.');

            return self::SUCCESS;
        }

        $duplicates = DB::table('commerce_outbox_messages')
            ->select('key', DB::raw('MIN(id) as keep_id'), DB::raw('COUNT(*) as cnt'))
            ->whereNotNull('key')
            ->groupBy('key')
            ->having('cnt', '>', 1)
            ->get();

        if ($duplicates->isEmpty()) {
            $this->components->info('No duplicate outbox keys found.');

            return self::SUCCESS;
        }

        $totalToDelete = 0;
        foreach ($duplicates as $dup) {
            $totalToDelete += (int) $dup->cnt - 1;
        }

        $this->components->warn("Found {$duplicates->count()} duplicate outbox keys ({$totalToDelete} extra rows).");

        if ($this->option('dry-run')) {
            $this->table(['key', 'duplicates', 'keep_id'], $duplicates->map(fn ($dup) => [
                $dup->key,
                $dup->cnt - 1,
                $dup->keep_id,
            ]));

            return self::SUCCESS;
        }

        foreach ($duplicates as $dup) {
            DB::table('commerce_outbox_messages')
                ->where('key', $dup->key)
                ->where('id', '!=', $dup->keep_id)
                ->delete();
        }

        $this->components->info("Deleted {$totalToDelete} duplicate outbox messages.");

        return self::SUCCESS;
    }
}