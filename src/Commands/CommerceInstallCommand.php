<?php

namespace EzEcommerce\Commands;

use Illuminate\Console\Command;

class CommerceInstallCommand extends Command
{
    protected $signature = 'commerce:install';

    protected $description = 'Publish ez-ecommerce config and translations';

    public function handle(): int
    {
        $this->call('vendor:publish', [
            '--tag' => 'ez-ecommerce-config',
        ]);

        $this->call('vendor:publish', [
            '--tag' => 'ez-ecommerce-translations',
        ]);

        $this->components->info('ez-ecommerce installed.');

        return self::SUCCESS;
    }
}
