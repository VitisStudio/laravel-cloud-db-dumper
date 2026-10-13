<?php

namespace VitisStudio\LaravelCloudDbDumper\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Prompts\Elements\KeyValueList;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\Support\Logger;
use RuntimeException;
use Throwable;
use VitisStudio\LaravelCloudDbDumper\Cloud\CloudCli;
use VitisStudio\LaravelCloudDbDumper\Cloud\DatabaseTarget;
use VitisStudio\LaravelCloudDbDumper\Cloud\LocalConfig;
use VitisStudio\LaravelCloudDbDumper\Cloud\PushGuard;
use VitisStudio\LaravelCloudDbDumper\Cloud\TargetNavigator;
use VitisStudio\LaravelCloudDbDumper\Dumpers\DumpManager;
use VitisStudio\LaravelCloudDbDumper\Restorers\RestoreManager;
use VitisStudio\LaravelCloudDbDumper\Seeders\SeederDiscovery;
use VitisStudio\LaravelCloudDbDumper\Support\DatabaseClients;
use VitisStudio\LaravelCloudDbDumper\Support\GitRepository;
use VitisStudio\LaravelCloudDbDumper\Support\LocalDatabase;
use VitisStudio\LaravelCloudDbDumper\Support\Preferences;

use function Laravel\Prompts\callout;
use function Laravel\Prompts\clear;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\notify;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\select;
use function Laravel\Prompts\table;
use function Laravel\Prompts\task;
use function Laravel\Prompts\text;
use function Laravel\Prompts\title;
use function Laravel\Prompts\warning;

class LaravelCloudDbDumperCommand extends Command
{
    /**
     * Client binary paths resolved for this run, keyed by binary name.
     *
     * @var array<string, string|null>
     */
    protected array $binaries = [];

    /**
     * The runtime connection a push writes through.
     */
    protected const PUSH_CONNECTION = 'cloud-db-dumper-push';

    public $signature = 'db:pull
        {application? : The application ID or name}
        {environment? : The environment ID or name}
        {--organization= : Laravel Cloud organization to run as, by name or slug (when several are authenticated)}
        {--fresh : Ignore saved preferences and re-select the database}
        {--prune : Delete the dumps stored locally, after showing what will go, and exit}
        {--to= : Push the dump into the Cloud database of this environment instead of your local one}
        {--force : Skip the prune and push confirmations, for non-interactive use}
        {--download : Always download a fresh dump, ignoring any already on disk}
        {--clients : Re-choose the database client binaries for this machine}
        {--no-store : Do not keep the dump on disk; restore from a temporary file and delete it}
        {--no-restore : Dump only; do not restore into the local database}
        {--no-seed : Skip the post-restore seeder step}
        {--skip-data=* : Restore these tables with no rows; alone, the configured cache, session and queue tables}';

    public $description = 'Dump a Laravel Cloud database and restore it into your local database, or push it into another environment';

    public function handle(): int
    {
        $this->takeOverTerminal();

        try {
            return $this->option('prune') ? $this->prune() : $this->backup();
        } catch (Throwable $e) {
            callout('db:pull stopped', $e->getMessage(), 'error');

            return self::FAILURE;
        }
    }

    /**
     * Whether a person can answer a question. Prompts answers with defaults
     * when input is not a terminal even without -n, so both are checked.
     */
    protected function canAsk(): bool
    {
        return $this->input->isInteractive() && defined('STDIN') && stream_isatty(STDIN);
    }

    /**
     * Start from an empty screen, so what follows reads as one run rather
     * than more lines under whatever the terminal already held.
     */
    protected function takeOverTerminal(): void
    {
        // A real terminal, not whether colour was detected: colour can be off
        // in a terminal that clears perfectly well, and a pipe or log file
        // should never receive the escape codes.
        if (! $this->canAsk() || ! defined('STDOUT') || ! stream_isatty(STDOUT)) {
            return;
        }

        // Someone is at a terminal answering prompts, so the output is theirs.
        // Tools that rewrite output for AI agents (laravel/pao) key off the
        // environment, which an editor's terminal can inherit, and a prompt
        // stripped of its colour no longer shows which answer is selected.
        $this->output = new OutputStyle($this->input, $this->output->getOutput());
        $this->output->setDecorated(true);
        Prompt::setOutput($this->output);

        clear();
        title('db:pull');
        intro($this->option('prune') ? ' db:pull · prune ' : ' db:pull ');
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
            outro('No stored dumps in '.config('cloud-db-dumper.backup_path').'.');

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
            outro('Nothing was deleted.');

            return self::SUCCESS;
        }

        $deleted = $dumpManager->delete(array_column($dumps, 'path'));

        outro("Deleted {$deleted} dump".($deleted === 1 ? '' : 's').', freeing '.$this->humanSize($total).'.');

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

        if ($this->pushing() && $this->option('no-restore')) {
            throw new RuntimeException('--to restores into another environment, so it cannot be combined with --no-restore.');
        }

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

        // Resolved before the dump, so a destination that cannot be pushed
        // into is refused before production has been read.
        $destination = $this->pushing() ? $this->resolveDestination($navigator, $target) : null;

        $binaries = $this->resolveClients($preferences, $target->driver());

        $this->summarize($navigator, $target, $destination);

        $dumpManager = new DumpManager(
            $storeDumps ? $this->backupPath() : '',
            $binaries,
            $storeDumps,
        );

        $dumpFile = $this->produceDump($navigator, $dumpManager, $target);

        try {
            if ($destination !== null) {
                $this->push($navigator, $target, $destination, $dumpFile);
            } elseif (! $this->option('no-restore') && $this->confirmRestore()) {
                $this->restoreLocally($dumpFile);

                if (! $this->option('no-seed')) {
                    $target = $this->runSeeder($target);
                }

                $this->finish("Restored {$target->environmentName} into your local \"{$this->localDatabaseName()}\" database.");
            } elseif (! $this->option('no-restore')) {
                outro('Restore skipped. Your local database is untouched.');
            } else {
                outro('Dump taken. Nothing was restored.');
            }
        } finally {
            // A dump that was never meant to be kept goes away even when the
            // restore threw, so production data is not left behind.
            $dumpManager->discard($dumpFile);
        }

        // The saved target is what a bare db:pull offers next time, and a
        // push should not change what that is.
        if ($destination !== null) {
            return self::SUCCESS;
        }

        $target = $target->withOrganization($navigator->organization() ?? $target->organizationName);

        $preferences->save($target);

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
                info("Using the dump on disk: {$chosen}");

                return $chosen;
            }
        }

        // The navigator spins around its own CLI calls.
        $target = $navigator->attachCredentials($target);

        return task(
            "Dumping {$target->environmentName}",
            function (Logger $log) use ($dumpManager, $target): string {
                /** @var array{hostname: string} $connection */
                $connection = $target->connection;

                $log->line("Reading {$target->schemaName} from {$connection['hostname']}");

                $path = $dumpManager->dump($target);

                $log->success('Dumped '.$this->humanSize((int) filesize($path)).($dumpManager->storesDumps()
                    ? " to {$path}"
                    : ' to a temporary file, deleted once this run ends'));

                return $path;
            },
            keepSummary: true,
        );
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
            warning("No {$dumpBinary} found on this machine; falling back to whatever the PATH resolves.");

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

    /**
     * Ask once, after naming the database that goes away, whether to restore.
     *
     * One prompt, not two: a second confirmation defaulting to No swallowed the
     * answer to the first and skipped the restore for anyone who just hits Enter.
     */
    protected function confirmRestore(): bool
    {
        $this->drainInput();

        warning('This will overwrite your local "'.$this->localDatabaseName().'" database.');

        return confirm('Restore this dump into your local database?', default: true);
    }

    /**
     * Throw away anything typed before the question was asked.
     *
     * Prompts reads whatever the terminal is already holding, so a key pressed
     * during the dump answers the next question with its default and submits it
     * before it has rendered — the answer the user then gives goes nowhere.
     */
    protected function drainInput(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || ! defined('STDIN') || ! stream_isatty(STDIN)) {
            return;
        }

        // The line discipline holds a keystroke until Enter, so canonical mode
        // has to go before the buffer can be read, and the mode it is restored
        // to has to be the one Prompts will later capture as the original.
        $mode = trim((string) shell_exec('stty -g 2>/dev/null'));

        exec('stty -icanon -echo 2>/dev/null');

        stream_set_blocking(STDIN, false);

        while (($chunk = fread(STDIN, 1024)) !== false && $chunk !== '') {
            // Discarded.
        }

        stream_set_blocking(STDIN, true);

        if ($mode !== '') {
            exec('stty '.escapeshellarg($mode).' 2>/dev/null');
        }
    }

    protected function localDatabaseName(): string
    {
        $connectionName = (string) config('database.default');

        return (string) config("database.connections.{$connectionName}.database");
    }

    protected function restoreLocally(string $dumpFile): void
    {
        $connectionName = (string) config('database.default');
        $localConfig = (array) config("database.connections.{$connectionName}");

        $restorer = new RestoreManager(
            $localConfig,
            $this->binaries,
            $connectionName,
            $this->skipDataTables(),
        );

        $this->restoreWith($restorer, $dumpFile, "Restoring into your local \"{$this->localDatabaseName()}\" database");
    }

    /**
     * Say what was resolved, once and in one place, before anything is read
     * or written: where the data comes from, where it goes, and with what.
     */
    protected function summarize(TargetNavigator $navigator, DatabaseTarget $source, ?DatabaseTarget $destination): void
    {
        $plan = [];

        $organization = $navigator->organization() ?? $source->organizationName;

        if ($organization !== null) {
            $plan['Organization'] = $organization;
        }

        $plan['From'] = $this->describeTarget($source);

        $plan['Into'] = match (true) {
            $destination !== null => $this->describeTarget($destination).' on Laravel Cloud',
            (bool) $this->option('no-restore') => 'nowhere, dump only',
            default => 'your local "'.$this->localDatabaseName().'" database',
        };

        $skipped = $this->option('no-restore') ? [] : $this->skipDataTables();

        if ($skipped !== []) {
            $plan['Empty tables'] = implode(', ', $skipped);
        }

        foreach ($this->binaries as $binary => $path) {
            if ($path !== null && $path !== '' && in_array($binary, ['pg_dump', 'psql', 'mysqldump', 'mysql'], true)) {
                $plan[$binary] = $path;
            }
        }

        callout($destination !== null ? 'Push' : 'Pull', [new KeyValueList($plan)]);
    }

    protected function describeTarget(DatabaseTarget $target): string
    {
        return "{$target->applicationName} › {$target->environmentName} ({$target->clusterName} / {$target->schemaName})";
    }

    /**
     * Tables to restore without rows: those named on the flag, and the
     * configured list when the flag is given with no value.
     *
     * @return array<int, string>
     */
    protected function skipDataTables(): array
    {
        $given = (array) $this->option('skip-data');

        if ($given === []) {
            return [];
        }

        $tables = [];

        foreach ($given as $value) {
            $tables = array_merge($tables, $value === null || $value === ''
                ? (array) config('cloud-db-dumper.skip_data_tables', [])
                : explode(',', (string) $value));
        }

        return array_values(array_unique(array_filter(array_map('trim', $tables), fn ($table) => $table !== '')));
    }

    /**
     * Empty the target and load the dump, narrating it table by table.
     *
     * psql echoes each COPY statement before reporting its row count, so the
     * table name is held until the count that belongs to it arrives.
     */
    protected function restoreWith(RestoreManager $restorer, string $dumpFile, string $label): void
    {
        task($label, function (Logger $log) use ($restorer, $dumpFile): void {
            $closed = $restorer->killActiveConnections();
            $log->success($closed === 1 ? 'Closed 1 open connection' : "Closed {$closed} open connections");

            $restorer->resetSchema();
            $log->success('Emptied the database');

            $table = null;
            $tables = 0;
            $rows = 0;

            $restorer->restore($dumpFile, function (string $line) use ($log, &$table, &$tables, &$rows): void {
                if (preg_match('/^COPY\s+(\S+)\s.*FROM\s+stdin/i', $line, $copy) === 1) {
                    $table = $copy[1];

                    return;
                }

                if ($table !== null && preg_match('/^COPY\s+(\d+)$/', trim($line), $count) === 1) {
                    $tables++;
                    $rows += (int) $count[1];
                    $log->line(sprintf('%s  %s rows', $table, number_format((int) $count[1])));
                    $table = null;
                }
            });

            $log->success(sprintf('Loaded %s rows across %d tables', number_format($rows), $tables));
        }, keepSummary: true);
    }

    /**
     * Close the run: on screen, and as a desktop notification for whoever
     * went to do something else while it ran.
     */
    protected function finish(string $message): void
    {
        outro($message);

        if ($this->canAsk()) {
            notify('db:pull', $message);
        }
    }

    protected function pushing(): bool
    {
        return (string) ($this->option('to') ?? '') !== '';
    }

    protected function resolveDestination(TargetNavigator $navigator, DatabaseTarget $source): DatabaseTarget
    {
        $destination = $navigator->navigate($source->applicationId, (string) $this->option('to'));

        PushGuard::assertDestination($source, $destination);

        return $destination;
    }

    /**
     * Replace the destination's database with the dump.
     *
     * Ownership never reaches the destination: the dump is taken with
     * --no-owner --no-privileges and the restore strips any that an older dump
     * still carries, so every object lands owned by the destination's own user
     * instead of naming roles that only exist on the source.
     */
    protected function push(TargetNavigator $navigator, DatabaseTarget $source, DatabaseTarget $destination, string $dumpFile): void
    {
        $source = $navigator->attachCredentials($source);
        $destination = $navigator->attachCredentials($destination);

        PushGuard::assertDistinctConnections($source, $destination);

        if (! $this->confirmPush($source, $destination)) {
            outro('Push skipped. Nothing on Laravel Cloud was changed.');

            return;
        }

        $this->registerPushConnection($destination);

        try {
            $restorer = new RestoreManager(
                (array) config('database.connections.'.self::PUSH_CONNECTION),
                $this->binaries,
                self::PUSH_CONNECTION,
                $this->skipDataTables(),
            );

            $this->restoreWith($restorer, $dumpFile, "Restoring into {$destination->environmentName}");

            if (! $this->option('no-seed')) {
                $this->runSeeder($destination, self::PUSH_CONNECTION);
            }

            $this->finish("Pushed {$source->environmentName} into {$destination->environmentName}.");
        } finally {
            DB::purge(self::PUSH_CONNECTION);
        }
    }

    /**
     * Make the destination type its own name, since Enter alone should never
     * be enough to empty a Cloud database.
     */
    protected function confirmPush(DatabaseTarget $source, DatabaseTarget $destination): bool
    {
        callout('This replaces a Laravel Cloud database', sprintf(
            'Everything in %s (%s / %s) is dropped and replaced with %s. There is no undo.',
            $destination->environmentName,
            $destination->clusterName,
            $destination->schemaName,
            $source->environmentName,
        ), 'warning');

        if (! $this->canAsk()) {
            if (! $this->option('force')) {
                throw new RuntimeException('Pushing without a prompt needs --force.');
            }

            return true;
        }

        $this->drainInput();

        return text(
            label: "Type \"{$destination->environmentName}\" to push",
            required: true,
        ) === $destination->environmentName;
    }

    protected function registerPushConnection(DatabaseTarget $destination): void
    {
        /** @var array{hostname: string, port: int|string, username: string, password: string} $connection */
        $connection = $destination->connection;

        config(['database.connections.'.self::PUSH_CONNECTION => [
            'driver' => 'pgsql',
            'host' => $connection['hostname'],
            'port' => $connection['port'],
            'database' => $destination->schemaName,
            'username' => $connection['username'],
            'password' => $connection['password'],
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'require',
        ]]);

        DB::purge(self::PUSH_CONNECTION);
    }

    protected function runSeeder(DatabaseTarget $target, ?string $connection = null): DatabaseTarget
    {
        $seeders = (new SeederDiscovery(base_path('database/seeders')))->all();

        if ($seeders === []) {
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

        task("Running {$chosen}", function (Logger $log) use ($chosen, $connection): void {
            $exitCode = $this->callSilently('db:seed', array_filter([
                '--class' => $chosen,
                '--database' => $connection,
                '--force' => true,
            ], fn ($value) => $value !== null));

            if ($exitCode !== self::SUCCESS) {
                throw new RuntimeException("{$chosen} exited with code {$exitCode}.");
            }

            $log->success("Ran {$chosen}");
        }, keepSummary: true);

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
