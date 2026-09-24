<?php

namespace VitisStudio\LaravelCloudDbDumper\Commands;

use Illuminate\Console\Command;
use RuntimeException;
use Throwable;
use VitisStudio\LaravelCloudDbDumper\Cloud\CloudCli;
use VitisStudio\LaravelCloudDbDumper\Cloud\DatabaseTarget;
use VitisStudio\LaravelCloudDbDumper\Cloud\LocalConfig;
use VitisStudio\LaravelCloudDbDumper\Cloud\TargetNavigator;
use VitisStudio\LaravelCloudDbDumper\Dumpers\DumpManager;
use VitisStudio\LaravelCloudDbDumper\Restorers\RestoreManager;
use VitisStudio\LaravelCloudDbDumper\Seeders\SeederDiscovery;
use VitisStudio\LaravelCloudDbDumper\Support\GitRepository;
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
        {application? : The application ID or name}
        {environment? : The environment ID or name}
        {--organization= : Laravel Cloud organization to run as, by name (when several are authenticated)}
        {--fresh : Ignore saved preferences and re-select the database}
        {--no-store : Do not keep the dump on disk; restore from a temporary file and delete it}
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
        // Checked before anything is fetched or asked, so a contradictory pair
        // of flags fails instantly rather than after the whole walk.
        $storeDumps = $this->shouldStoreDumps();

        if (! $storeDumps && $this->option('no-restore')) {
            throw new RuntimeException(
                'Nothing would come of this run: --no-restore keeps the dump out of your database and '
                .'dump storage is off, so the dump would be deleted unused. Drop one of the two.'
            );
        }

        $cloud = new CloudCli((string) config('cloud-db-dumper.cloud_binary', 'cloud'));
        $navigator = new TargetNavigator(
            cloud: $cloud,
            localConfig: new LocalConfig(base_path('.cloud/config.json')),
            repository: new GitRepository(base_path()),
        );
        $preferences = new Preferences((string) config('cloud-db-dumper.prefs_file'));

        $target = $this->resolveTarget($navigator, $preferences);

        $dumpManager = new DumpManager(
            $storeDumps ? $this->backupPath() : '',
            (array) config('cloud-db-dumper.binaries', []),
            $storeDumps,
        );

        $dumpFile = $this->produceDump($navigator, $dumpManager, $target);

        try {
            if (! $this->option('no-restore') && confirm('Restore this dump into your local database?', default: true)) {
                $this->restoreLocally($dumpFile);

                if (! $this->option('no-seed')) {
                    $target = $this->runSeeder($target);
                }
            }
        } finally {
            // A dump that was never meant to be kept goes away even when the
            // restore threw, so production data is not left behind.
            $dumpManager->discard($dumpFile);
        }

        if (! $storeDumps) {
            info('Dump discarded; nothing was left on disk.');
        }

        $target = $target->withOrganization($navigator->organization() ?? $target->organizationName);

        $preferences->save($target);
        info('Saved preferences to '.config('cloud-db-dumper.prefs_file'));

        return self::SUCCESS;
    }

    protected function resolveTarget(TargetNavigator $navigator, Preferences $preferences): DatabaseTarget
    {
        // An explicitly named application or environment is the user telling us
        // where to go, so the saved target does not get a say.
        $saved = $this->option('fresh') || $this->hasTargetArguments()
            ? null
            : $preferences->load();

        $navigator->useOrganization(
            $this->organization() ?? $saved?->organizationName,
        );

        if ($saved !== null) {
            $useSaved = confirm(
                "Use saved target: {$saved->applicationName} / {$saved->environmentName} / {$saved->schemaName}?",
                default: true,
            );

            if ($useSaved) {
                return $saved;
            }
        }

        return $navigator->navigate(
            $this->argument('application'),
            $this->argument('environment'),
        );
    }

    protected function hasTargetArguments(): bool
    {
        return $this->argument('application') !== null || $this->argument('environment') !== null;
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

        // The navigator spins around its own CLI calls.
        $target = $navigator->attachCredentials($target);

        $path = spin(
            fn () => $dumpManager->dump($target),
            "Dumping {$target->schemaName} ({$target->driver()})...",
        );

        info($dumpManager->storesDumps()
            ? "Dump written to: {$path}"
            : 'Dump written to a temporary file (not stored locally).');

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

    /**
     * Organization to run the Cloud CLI as, from the flag or config. Null lets
     * the CLI resolve it, and the navigator prompts if it cannot.
     */
    protected function organization(): ?string
    {
        $organization = (string) ($this->option('organization') ?? config('cloud-db-dumper.organization') ?? '');

        return $organization !== '' ? $organization : null;
    }

    /**
     * Whether dumps may be kept on disk. The flag wins, then config — so a
     * team with a data-handling policy can switch storage off for everyone
     * and not rely on each person remembering the flag.
     */
    protected function shouldStoreDumps(): bool
    {
        if ($this->option('no-store')) {
            return false;
        }

        return (bool) config('cloud-db-dumper.store_dumps', true);
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
