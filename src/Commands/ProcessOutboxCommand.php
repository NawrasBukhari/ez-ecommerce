<?php

namespace EzEcommerce\Commands;

use EzEcommerce\Core\Jobs\ProcessOutboxJob;
use EzEcommerce\Core\Models\OutboxMessage;
use Illuminate\Console\Command;

class ProcessOutboxCommand extends Command
{
    protected $signature = 'commerce:process-outbox {--limit=100 : Maximum number of outbox rows to process} {--once : Process a single row and exit}';

    protected $description = 'Drain pending outbox messages by dispatching their integration events';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        if ($this->option('once')) {
            $limit = 1;
        }

        $messages = OutboxMessage::query()
            ->pending()
            ->limit($limit)
            ->get();

        if ($messages->isEmpty()) {
            $this->info('No pending outbox messages.');

            return self::SUCCESS;
        }

        foreach ($messages as $message) {
            ProcessOutboxJob::dispatchSync($message->id);
        }

        $this->info("Processed {$messages->count()} outbox message(s).");

        return self::SUCCESS;
    }
}
