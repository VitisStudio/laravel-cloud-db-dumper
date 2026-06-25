<?php

namespace VitisStudio\LaravelCloudDbDumper\Commands;

use Illuminate\Console\Command;

class LaravelCloudDbDumperCommand extends Command
{
    public $signature = 'laravel-cloud-db-dumper';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
