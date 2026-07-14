<?php

namespace VitisStudio\LaravelCloudDbDumper\Commands;

use Illuminate\Console\Command;
use Throwable;
use VitisStudio\LaravelCloudDbDumper\Cloud\CloudCli;
use VitisStudio\LaravelCloudDbDumper\Cloud\DatabaseTarget;
use VitisStudio\LaravelCloudDbDumper\Cloud\TargetNavigator;
use VitisStudio\LaravelCloudDbDumper\Dumpers\DumpManager;
use VitisStudio\LaravelCloudDbDumper\Restorers\RestoreManager;
use VitisStudio\LaravelCloudDbDumper\Seeders\SeederDiscovery;
use VitisStudio\LaravelCloudDbDumper\Support\Preferences;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

class LaravelCloudDbDumperCommand extends Command
{
    public $signature = 'db:pull
        {--fresh : Ignore saved preferences and re-select the database}
        {--no-restore : Dump only; do not restore into the local database}
        {--no-seed : Skip the post-restore seeder step}';

    public $description = 'Dump a Laravel Cloud database and restore it into your local database';

    public function handle(): int
    {
        try {
            return $this->backup();
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }

    protected function backup(): int
    {
        $cloud = new CloudCli((string) config('cloud-db-dumper.cloud_binary', 'cloud'));
        $navigator = new TargetNavigator($cloud);
        $preferences = new Preferences((string) config('cloud-db-dumper.prefs_file'));

        $target = $this->resolveTarget($navigator, $preferences);

        $dumpManager = new DumpManager(
            $this->backupPath(),
            (array) config('cloud-db-dumper.binaries', []),
        );

        $dumpFile = $this->produceDump($navigator, $dumpManager, $target);

        if (! $this->option('no-restore') && confirm('Restore this dump into your local database?', default: true)) {
            $this->restoreLocally($dumpFile);

            if (! $this->option('no-seed')) {
                $target = $this->runSeeder($target);
            }
        }

        $preferences->save($target);
        info('Saved preferences to '.config('cloud-db-dumper.prefs_file'));

        return self::SUCCESS;
    }

    protected function resolveTarget(TargetNavigator $navigator, Preferences $preferences): DatabaseTarget
    {
        $saved = $this->option('fresh') ? null : $preferences->load();

        if ($saved !== null) {
            $useSaved = confirm(
                "Use saved target: {$saved->applicationName} / {$saved->environmentName} / {$saved->schemaName}?",
                default: true,
            );

            if ($useSaved) {
                return $saved;
            }
        }

        return spin(
            fn () => $navigator->navigate(),
            'Loading Laravel Cloud applications...',
        );
    }

    protected function produceDump(TargetNavigator $navigator, DumpManager $dumpManager, DatabaseTarget $target): string
    {
        if ($dumpManager->cachedCopyExists($target)) {
            $reuse = select(
                label: 'A dump from today already exists. Use the cached copy?',
                options: [
                    'cached' => 'Use cached copy (saves bandwidth)',
                    'fresh' => 'Download a fresh dump',
                ],
                default: 'cached',
            );

            if ($reuse === 'cached') {
                $path = $dumpManager->pathFor($target);
                info("Using cached dump: {$path}");

                return $path;
            }
        }

        $target = spin(
            fn () => $navigator->attachCredentials($target),
            'Fetching database credentials...',
        );

        $path = spin(
            fn () => $dumpManager->dump($target),
            "Dumping {$target->schemaName} ({$target->driver()})...",
        );

        info("Dump written to: {$path}");

        return $path;
    }

    protected function restoreLocally(string $dumpFile): void
    {
        $connectionName = (string) config('database.default');
        $localConfig = (array) config("database.connections.{$connectionName}");

        warning("This will overwrite your local \"{$localConfig['database']}\" database.");

        if (! confirm('Continue with the restore?', default: false)) {
            note('Restore skipped.');

            return;
        }

        $restorer = new RestoreManager(
            $localConfig,
            (array) config('cloud-db-dumper.binaries', []),
            $connectionName,
        );

        spin(function () use ($restorer, $dumpFile): void {
            $restorer->killActiveConnections();
            $restorer->restore($dumpFile);
        }, 'Killing active connections and restoring...');

        info('Local database restored.');
    }

    protected function runSeeder(DatabaseTarget $target): DatabaseTarget
    {
        $seeders = (new SeederDiscovery(base_path('database/seeders')))->all();

        if ($seeders === []) {
            note('No seeders found in database/seeders — skipping.');

            return $target;
        }

        $options = ['__none__' => 'None (skip seeding)'];
        foreach ($seeders as $seeder) {
            $options[$seeder] = $seeder;
        }

        $default = $target->defaultSeeder !== null && isset($options[$target->defaultSeeder])
            ? $target->defaultSeeder
            : '__none__';

        $chosen = select(
            label: 'Run a seeder to sanitize the restored data?',
            options: $options,
            default: $default,
        );

        if ($chosen === '__none__') {
            return $target->withDefaultSeeder(null);
        }

        $this->call('db:seed', ['--class' => $chosen, '--force' => true]);
        info("Ran seeder: {$chosen}");

        return $target->withDefaultSeeder((string) $chosen);
    }

    protected function backupPath(): string
    {
        $configured = (string) config('cloud-db-dumper.backup_path');

        if (! confirm("Save dumps to the default location ({$configured})?", default: true)) {
            return text(
                label: 'Where should dumps be saved?',
                default: $configured,
                required: true,
            );
        }

        return $configured;
    }
}
