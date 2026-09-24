<?php

namespace VitisStudio\LaravelCloudDbDumper\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;
use VitisStudio\LaravelCloudDbDumper\Cloud\CloudCli;
use VitisStudio\LaravelCloudDbDumper\Cloud\DatabaseTarget;
use VitisStudio\LaravelCloudDbDumper\Cloud\LocalConfig;
use VitisStudio\LaravelCloudDbDumper\Cloud\TargetNavigator;
use VitisStudio\LaravelCloudDbDumper\Dumpers\DumpManager;
use VitisStudio\LaravelCloudDbDumper\Restorers\RestoreManager;
use VitisStudio\LaravelCloudDbDumper\Seeders\SeederDiscovery;
use VitisStudio\LaravelCloudDbDumper\Support\DatabaseClients;
use VitisStudio\LaravelCloudDbDumper\Support\GitRepository;
use VitisStudio\LaravelCloudDbDumper\Support\LocalDatabase;
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
    /**
     * Client binary paths resolved for this run, keyed by binary name.
     *
     * @var array<string, string|null>
     */
    protected array $binaries = [];

    public $signature = 'db:pull
        {application? : The application ID or name}
        {environment? : The environment ID or name}
        {--organization= : Laravel Cloud organization to run as, by name or slug (when several are authenticated)}
        {--fresh : Ignore saved preferences and re-select the database}
        {--prune : Delete the dumps stored locally, after showing what will go, and exit}
        {--force : Skip the prune confirmation, for non-interactive use}
        {--download : Always download a fresh dump, ignoring any already on disk}
        {--clients : Re-choose the database client binaries for this machine}
        {--no-store : Do not keep the dump on disk; restore from a temporary file and delete it}
        {--no-restore : Dump only; do not restore into the local database}
        {--no-seed : Skip the post-restore seeder step}';

    public $description = 'Dump a Laravel Cloud database and restore it into your local database';

    public function handle(): int
    {
        try {
            return $this->option('prune') ? $this->prune() : $this->backup();
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
        return new CloudCli($this->cloudBinary());
    }

    /**
     * The configured binary, else the project's own copy of the CLI, else
     * whatever is on the PATH.
     *
     * Laravel recommends installing the Cloud CLI as a project dev dependency,
     * so a project-local binary is the likelier one to exist.
     */
    protected function cloudBinary(): string
    {
        $configured = (string) (config('cloud-db-dumper.cloud_binary') ?? '');

        if ($configured !== '') {
            return $configured;
        }

        $local = base_path('vendor/bin/cloud');

        return is_executable($local) ? $local : 'cloud';
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

        // Before any prompting or network work, so an unusable CLI is named
        // immediately rather than after the user has picked their way down to
        // the database.
        $cloud->ensureSupportedVersion();

        $repository = new GitRepository(getcwd() ?: base_path());

        $navigator = new TargetNavigator(
            cloud: $cloud,
            // The CLI resolves this file from the git root of the invoking
            // process, not from the Laravel application root. In a monorepo
            // those differ, and reading the wrong one silently ignores the
            // project's pinned application and environment.
            localConfig: new LocalConfig(($repository->root() ?? base_path()).'/.cloud/config.json'),
            repository: $repository,
        );
        $preferences = new Preferences((string) config('cloud-db-dumper.prefs_file'));

        $target = $this->resolveTarget($navigator, $preferences);

        $binaries = $this->resolveClients($preferences, $target->driver());

        $dumpManager = new DumpManager(
            $storeDumps ? $this->backupPath() : '',
            $binaries,
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

    /**
     * Which client binaries to run, asked once and then remembered.
     *
     * The version matters: a client older than the server it reads is refused
     * outright, and a dump taken from a newer server may not load into an older
     * one. Machines commonly have several versions installed with only one on
     * the PATH, so the first run establishes the choice and later runs speak up
     * only when the local database has moved underneath it.
     *
     * @return array<string, string|null>
     */
    protected function resolveClients(Preferences $preferences, string $driver): array
    {
        $configured = (array) config('cloud-db-dumper.binaries', []);
        $dumpBinary = $driver === 'mysql' ? 'mysqldump' : 'pg_dump';
        $restoreBinary = $driver === 'mysql' ? 'mysql' : 'psql';

        // An explicit path in config is the author's decision; do not override
        // it, and do not nag about it.
        $explicit = DatabaseClients::merge($configured, []);

        if (isset($explicit[$dumpBinary], $explicit[$restoreBinary])) {
            return $this->binaries = $explicit;
        }

        $serverVersion = (new LocalDatabase((string) config('database.default')))->serverVersion();
        $saved = $preferences->clients($driver);

        if ($saved !== null && ! $this->option('clients')) {
            $reason = $this->staleClientReason($saved, $serverVersion);

            if ($reason === null) {
                return $this->binaries = DatabaseClients::merge($configured, [
                    $dumpBinary => $saved['dump'],
                    $restoreBinary => $saved['restore'],
                ]);
            }

            warning($reason);
        }

        $clients = new DatabaseClients;
        $dumps = $clients->discover($dumpBinary);
        $restores = $clients->discover($restoreBinary);

        if ($dumps === []) {
            note("No {$dumpBinary} found on this machine; falling back to whatever the PATH resolves.");

            return $this->binaries = $explicit;
        }

        $chosen = [
            'driver' => $driver,
            'serverVersion' => $serverVersion,
            'dump' => $this->chooseClient($dumpBinary, $dumps, $serverVersion, 'reads the Cloud database'),
            'restore' => $restores === []
                ? $restoreBinary
                : $this->chooseClient($restoreBinary, $restores, $serverVersion, 'writes to your local database'),
        ];

        $preferences->saveClients($driver, $chosen);

        return $this->binaries = DatabaseClients::merge($configured, [
            $dumpBinary => $chosen['dump'],
            $restoreBinary => $chosen['restore'],
        ]);
    }

    /**
     * Why a remembered choice can no longer be trusted, or null when it can.
     *
     * @param  array{driver: string, serverVersion: string|null, dump: string, restore: string}  $saved
     */
    protected function staleClientReason(array $saved, ?string $serverVersion): ?string
    {
        foreach ([$saved['dump'], $saved['restore']] as $path) {
            if (str_contains($path, '/') && ! is_executable($path)) {
                return "The database client remembered for this project is gone: {$path}.";
            }
        }

        $was = $saved['serverVersion'];

        if ($serverVersion === null || $was === null || $was === $serverVersion) {
            return null;
        }

        return "Your local database is now {$serverVersion}, and these client binaries were chosen for {$was}.";
    }

    /**
     * @param  array<int, array{path: string, version: string}>  $clients
     */
    protected function chooseClient(string $binary, array $clients, ?string $serverVersion, string $role): string
    {
        $best = DatabaseClients::bestFor($clients, $serverVersion);

        if (count($clients) === 1) {
            note("Using {$binary} ".$clients[0]['version']." ({$clients[0]['path']})");

            return $clients[0]['path'];
        }

        $options = [];
        foreach ($clients as $client) {
            $label = $binary.' '.$client['version'].'  —  '.$client['path'];

            if ($serverVersion !== null && DatabaseClients::major($client['version']) === DatabaseClients::major($serverVersion)) {
                $label .= '  (matches your local database)';
            }

            $options['client:'.$client['path']] = $label;
        }

        $local = $serverVersion !== null ? " Your local database is {$serverVersion}." : '';

        $selected = select(
            label: "Which {$binary} should be used? It {$role}.{$local}",
            options: $options,
            default: $best !== null ? 'client:'.$best['path'] : null,
            scroll: 10,
        );

        return substr((string) $selected, strlen('client:'));
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
            $this->binaries,
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
