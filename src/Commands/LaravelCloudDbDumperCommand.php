<?php

namespace VitisStudio\LaravelCloudDbDumper\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;
use VitisStudio\LaravelCloudDbDumper\Cloud\CloudCli;
use VitisStudio\LaravelCloudDbDumper\Cloud\CloudCliException;
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
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

class LaravelCloudDbDumperCommand extends Command
{
    public $signature = 'db:pull
        {application? : The application ID or name}
        {environment? : The environment ID or name}
        {--organization= : Laravel Cloud organization to run as, by name (when several are authenticated)}
        {--fresh : Ignore saved preferences and re-select the database}
        {--prune : Delete the dumps stored locally, after showing what will go, and exit}
        {--force : Skip the prune confirmation, for non-interactive use}
        {--download : Always download a fresh dump, ignoring any already on disk}
        {--no-store : Do not keep the dump on disk; restore from a temporary file and delete it}
        {--no-restore : Dump only; do not restore into the local database}
        {--no-seed : Skip the post-restore seeder step}';

    public $description = 'Dump a Laravel Cloud database and restore it into your local database';

    public function handle(): int
    {
        try {
            return $this->option('prune') ? $this->prune() : $this->backup();
        } catch (CloudCliException $e) {
            // A failure from the CLI is worth a second look: an out-of-date
            // binary produces errors that say nothing about being out of date.
            $hint = $this->cloud()->outdatedHint();

            $this->components->error(trim($e->getMessage()."\n\n".$hint));

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Delete every stored dump, showing the user exactly what is about to go.
     *
     * This never talks to Laravel Cloud: it is a local file operation, and
     * making someone walk an application picker to clear a folder would be
     * absurd.
     */
    protected function prune(): int
    {
        // Storage is forced on here regardless of config: files written before
        // the policy changed still need a way out.
        $dumpManager = new DumpManager(
            (string) config('cloud-db-dumper.backup_path'),
            (array) config('cloud-db-dumper.binaries', []),
            storeDumps: true,
        );

        $dumps = $dumpManager->storedDumps();

        if ($dumps === []) {
            info('No stored dumps found in '.config('cloud-db-dumper.backup_path').'.');

            return self::SUCCESS;
        }

        $total = array_sum(array_column($dumps, 'size'));

        table(
            headers: ['Database', 'Driver', 'Taken', 'Size'],
            rows: array_map(fn (array $dump) => [
                $dump['database'],
                $dump['driver'],
                $dump['date'],
                $this->humanSize($dump['size']),
            ], $dumps),
        );

        warning(sprintf(
            'Deleting %d dump%s (%s) from %s. This cannot be undone.',
            count($dumps),
            count($dumps) === 1 ? '' : 's',
            $this->humanSize($total),
            $dumpManager->directory(),
        ));

        if (! $this->option('force') && ! confirm('Delete these dumps?', default: false)) {
            note('Nothing was deleted.');

            return self::SUCCESS;
        }

        $deleted = $dumpManager->delete(array_column($dumps, 'path'));

        info("Deleted {$deleted} dump".($deleted === 1 ? '' : 's').', freeing '.$this->humanSize($total).'.');

        return self::SUCCESS;
    }

    protected function cloud(): CloudCli
    {
        return new CloudCli((string) config('cloud-db-dumper.cloud_binary', 'cloud'));
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

        $cloud = $this->cloud();
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
        $existing = $this->option('download') ? [] : $dumpManager->existingDumps($target);

        if ($existing !== []) {
            $chosen = $this->chooseExistingDump($existing, $target);

            if ($chosen !== null) {
                info("Using local dump: {$chosen}");

                return $chosen;
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

    /**
     * Offer the dumps already on disk for this database, newest first, so a
     * known-good snapshot can be restored again without pulling anything.
     * Returns null when the user wants a fresh download.
     *
     * @param  array<int, array{path: string, date: string, size: int}>  $existing
     */
    protected function chooseExistingDump(array $existing, DatabaseTarget $target): ?string
    {
        $today = Carbon::now()->format('Y-m-d');

        $options = ['__fresh__' => 'Download a fresh dump'];

        foreach ($existing as $dump) {
            $label = $dump['date'].'  ('.$this->humanSize($dump['size']).')';

            if ($dump['date'] === $today) {
                $label .= '  — today';
            }

            $options[$dump['path']] = $label;
        }

        $newest = $existing[0];

        $chosen = select(
            label: count($existing) === 1
                ? "One local dump of {$target->schemaName} already exists. Use it?"
                : count($existing)." local dumps of {$target->schemaName} already exist. Use one?",
            options: $options,
            // Today's dump is the one a repeat run almost always wants; older
            // snapshots are a deliberate choice, so they are never the default.
            default: $newest['date'] === $today ? $newest['path'] : '__fresh__',
            scroll: 10,
        );

        return $chosen === '__fresh__' ? null : (string) $chosen;
    }

    protected function humanSize(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return $unit === 'B'
                    ? $bytes.' B'
                    : number_format($bytes, $bytes < 10 ? 1 : 0).' '.$unit;
            }

            $bytes /= 1024;
        }

        return $bytes.' B';
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
