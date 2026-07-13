<?php

namespace EzEcommerce\Commands;

use EzEcommerce\Core\Jobs\ProcessOutboxJob;
use EzEcommerce\Core\Models\OutboxMessage;
use Illuminate\Console\Command;

class ProcessOutboxCommand extends Command
{
    protected $signature = 'commerce:process-outbox
        {--limit=100 : Maximum number of outbox rows to enqueue}
        {--event= : Only process rows for this event name}
        {--once : Process a single row and exit}
        {--sync : Process rows inline instead of dispatching queued jobs (admin use only)}
        {--retry-terminal : Re-queue failed_terminal rows for another attempt}';

    protected $description = 'Drain pending, retryable, and stale outbox messages by dispatching their integration events (at-least-once delivery).';

    public function handle(): int
    {
        $limit = $this->option('once') ? 1 : (int) $this->option('limit');

        $query = OutboxMessage::query()->claimable();

        if ($this->option('retry-terminal')) {
            $query->orWhere('status', 'failed_terminal');
        }

        if ($this->option('event')) {
            $query->where('event', $this->option('event'));
        }

        // Reset failed_terminal rows selected for retry back to pending so the
        // claim logic can pick them up.
        if ($this->option('retry-terminal')) {
            OutboxMessage::query()
                ->where('status', 'failed_terminal')
                ->when($this->option('event'), fn ($q) => $q->where('event', $this->option('event')))
                ->limit($limit)
                ->update(['status' => 'pending', 'available_at' => null, 'last_error' => null]);
        }

        $messages = (clone $query)->limit($limit)->get();

        if ($messages->isEmpty()) {
            $this->info('No claimable outbox messages.');

            return self::SUCCESS;
        }

        $sync = (bool) $this->option('sync');

        foreach ($messages as $message) {
            if ($sync) {
                ProcessOutboxJob::dispatchSync($message->id);
            } else {
                ProcessOutboxJob::dispatch($message->id);
            }
        }

        $this->info(
            ($sync ? 'Processed' : 'Enqueued').' '.$messages->count().' outbox message(s).'
        );

        return self::SUCCESS;
    }
}
