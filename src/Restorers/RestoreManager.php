<?php

namespace VitisStudio\LaravelCloudDbDumper\Restorers;

use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Restores a dump file into a database: the local development one for a pull,
 * or a Laravel Cloud one for a push. Before restoring it terminates other
 * active connections to the target database so the restore is not blocked.
 * Both kill and restore are driver-aware (mysql / pgsql).
 *
 * The command-building methods are pure (no side effects) so they can be tested
 * without a live database or binaries.
 *
 * @phpstan-type LocalConfig array{driver: string, host: string, port: int|string, database: string, username: string, password: string, sslmode?: string}
 */
class RestoreManager
{
    /**
     * @param  LocalConfig  $localConfig  the resolved local connection config
     * @param  array<string, string|null>  $binaryPaths  keyed by binary name (psql, mysql)
     * @param  string|null  $connectionName  Laravel connection name used to issue KILL statements
     * @param  array<int, string>  $skipDataTables  tables created empty: bare names, or schema-qualified
     */
    public function __construct(
        protected readonly array $localConfig,
        protected readonly array $binaryPaths = [],
        protected readonly ?string $connectionName = null,
        protected readonly array $skipDataTables = [],
    ) {
        //
    }

    /**
     * Terminate other active connections to the target database.
     */
    public function killActiveConnections(): int
    {
        $database = $this->localConfig['database'];
        $connection = DB::connection($this->connectionName);

        if ($this->driver() === 'mysql') {
            $rows = $connection->select(
                'SELECT ID as id FROM INFORMATION_SCHEMA.PROCESSLIST WHERE DB = ? AND ID <> CONNECTION_ID()',
                [$database],
            );

            foreach ($rows as $row) {
                $connection->statement('KILL CONNECTION '.(int) $row->id);
            }

            return count($rows);
        }

        $rows = $connection->select(
            'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()',
            [$database],
        );

        return count($rows);
    }

    /**
     * Empty the target database so a dump that only creates can land in it.
     *
     * pg_dump's plain output carries no DROP statements, so against a database
     * that already holds the tables every statement fails — and psql exits 0
     * all the same, leaving a restore that reported success and changed nothing.
     */
    public function resetSchema(): int
    {
        // mysqldump writes its own DROP TABLE IF EXISTS, so the tables it is
        // about to create are already gone by the time it creates them.
        if ($this->driver() === 'mysql') {
            return 0;
        }

        $connection = DB::connection($this->connectionName);

        $connection->statement('DROP SCHEMA IF EXISTS public CASCADE');
        $connection->statement('CREATE SCHEMA public');

        return 1;
    }

    /**
     * Write a copy of the dump with every ownership and privilege statement gone.
     *
     * A cloud dump assigns ownership and default privileges to roles that only
     * the provider has — Laravel Cloud runs on Neon, so cloud_admin and
     * neon_superuser turn up — and no local machine can be made to look like
     * that without inventing roles nobody asked for. Dumps taken from here on
     * are made with --no-owner --no-privileges; the ones already on disk are
     * not, so the restore strips them either way.
     *
     * @return string the path to the stripped copy, to be deleted by the caller
     */
    public function withoutOwnership(string $dumpFile): string
    {
        return $this->filtered($dumpFile, stripOwnership: true, skipData: false);
    }

    /**
     * Write a copy of the dump with the skipped tables' rows left out.
     *
     * The table definitions, indexes and sequences all still land, so the
     * application finds every table it expects, just empty. Done here rather
     * than at dump time because a day's dump is reused by name, and a pull
     * without those rows would later be restored by one that wanted them.
     *
     * @return string the path to the filtered copy, to be deleted by the caller
     */
    public function withoutSkippedData(string $dumpFile): string
    {
        return $this->filtered($dumpFile, stripOwnership: false, skipData: true);
    }

    /**
     * The one streaming pass both filters share, so a large dump is read once.
     */
    protected function filtered(string $dumpFile, bool $stripOwnership, bool $skipData): string
    {
        $source = fopen($dumpFile, 'r');

        if ($source === false) {
            throw new RuntimeException("Dump file could not be read: {$dumpFile}");
        }

        $target = (string) tempnam(sys_get_temp_dir(), 'restore-');
        $output = fopen($target, 'w');

        if ($output === false) {
            fclose($source);

            throw new RuntimeException("Could not open a temporary file to prepare the restore: {$target}");
        }

        $inCopyData = false;
        $skippingCopy = false;
        $dropping = false;

        try {
            while (($line = fgets($source)) !== false) {
                // COPY data is raw text that can spell anything, including a
                // line that reads exactly like a GRANT, so the rows between the
                // COPY and its terminator are passed through untouched.
                if ($inCopyData) {
                    $inCopyData = rtrim($line, "\r\n") !== '\\.';

                    if (! $skippingCopy) {
                        fwrite($output, $line);
                    }

                    continue;
                }

                if ($dropping) {
                    $dropping = ! $this->endsStatement($line);

                    continue;
                }

                if (preg_match('/^COPY\s+(\S+)\s.*\sFROM\s+stdin;\s*$/', $line, $copy) === 1) {
                    $inCopyData = true;
                    $skippingCopy = $skipData && $this->skipsDataFor($copy[1]);

                    if (! $skippingCopy) {
                        fwrite($output, $line);
                    }

                    continue;
                }

                // mysqldump writes each extended INSERT on a single line.
                if ($skipData && preg_match('/^INSERT\s+INTO\s+(`[^`]+`)\s/', $line, $insert) === 1
                    && $this->skipsDataFor($insert[1])) {
                    continue;
                }

                if ($stripOwnership && $this->isOwnershipStatement($line)) {
                    $dropping = ! $this->endsStatement($line);

                    continue;
                }

                fwrite($output, $line);
            }
        } finally {
            fclose($source);
            fclose($output);
        }

        return $target;
    }

    /**
     * Whether a table named in a dump is one whose rows are left out (pure).
     *
     * A bare name in the skip list matches that table in any schema; a
     * qualified one matches only there.
     */
    public function skipsDataFor(string $dumpedName): bool
    {
        $parts = array_map(
            fn (string $part) => strtolower(trim($part, '"`')),
            explode('.', $dumpedName),
        );

        $table = (string) end($parts);
        $qualified = implode('.', $parts);

        foreach ($this->skipDataTables as $skipped) {
            $skipped = strtolower(trim($skipped));

            if ($skipped === $table || $skipped === $qualified) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a line opens a statement that hands something to a role (pure).
     */
    protected function isOwnershipStatement(string $line): bool
    {
        return preg_match('/^ALTER\s+[A-Z][A-Z ]*\s+\S.*\sOWNER\s+TO\s/', $line) === 1
            || preg_match('/^ALTER\s+DEFAULT\s+PRIVILEGES\b/', $line) === 1
            || preg_match('/^(GRANT|REVOKE)\b/', $line) === 1;
    }

    protected function endsStatement(string $line): bool
    {
        return preg_match('/;\s*$/', $line) === 1;
    }

    /**
     * Restore the dump file into the target database.
     *
     * @param  (Closure(string): void)|null  $onOutput  receives each line psql prints, as it prints it
     */
    public function restore(string $dumpFile, ?Closure $onOutput = null): ProcessResult
    {
        if (! is_file($dumpFile)) {
            throw new RuntimeException("Dump file not found: {$dumpFile}");
        }

        $prepared = match (true) {
            $this->driver() === 'mysql' && $this->skipDataTables === [] => $dumpFile,
            $this->driver() === 'mysql' => $this->withoutSkippedData($dumpFile),
            default => $this->filtered($dumpFile, stripOwnership: true, skipData: $this->skipDataTables !== []),
        };

        try {
            [$command, $environment] = $this->driver() === 'mysql'
                ? $this->mySqlRestoreCommand($prepared)
                : $this->postgresRestoreCommand($prepared, echoQueries: $onOutput !== null);

            $pending = '';

            $result = Process::env($environment)->forever()->run($command, $onOutput === null ? null : function (string $type, string $output) use ($onOutput, &$pending): void {
                if ($type !== 'out') {
                    return;
                }

                $lines = explode("\n", $pending.$output);
                $pending = (string) array_pop($lines);

                foreach ($lines as $line) {
                    $onOutput($line);
                }
            });

            if ($onOutput !== null && $pending !== '') {
                $onOutput($pending);
            }

            if (! $result->successful()) {
                throw new RuntimeException(
                    "Restore failed.\n".trim($result->errorOutput() ?: $result->output())
                );
            }

            return $result;
        } finally {
            if ($prepared !== $dumpFile) {
                @unlink($prepared);
            }
        }
    }

    /**
     * Build the shell command + env for a MySQL restore (pure).
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public function mySqlRestoreCommand(string $dumpFile): array
    {
        $binary = $this->binary('mysql', 'mysql');

        $command = sprintf(
            '%s --host=%s --port=%s --user=%s %s < %s',
            $binary,
            escapeshellarg((string) $this->localConfig['host']),
            escapeshellarg((string) $this->localConfig['port']),
            escapeshellarg((string) $this->localConfig['username']),
            escapeshellarg((string) $this->localConfig['database']),
            escapeshellarg($dumpFile),
        );

        return [$command, ['MYSQL_PWD' => (string) $this->localConfig['password']]];
    }

    /**
     * Build the shell command + env for a PostgreSQL restore (pure).
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public function postgresRestoreCommand(string $dumpFile, bool $echoQueries = false): array
    {
        $binary = $this->binary('psql', 'psql');

        // ON_ERROR_STOP because psql's default is to report each failed
        // statement and still exit 0, and a whole dump can fail that way
        // without the restore ever being called a failure. Echoing is only
        // for someone watching: a bare "COPY 512" does not say which table.
        $command = sprintf(
            '%s --host=%s --port=%s --username=%s --dbname=%s --set=ON_ERROR_STOP=1 --single-transaction%s --file=%s',
            $binary,
            escapeshellarg((string) $this->localConfig['host']),
            escapeshellarg((string) $this->localConfig['port']),
            escapeshellarg((string) $this->localConfig['username']),
            escapeshellarg((string) $this->localConfig['database']),
            $echoQueries ? ' --echo-queries' : '',
            escapeshellarg($dumpFile),
        );

        $environment = ['PGPASSWORD' => (string) $this->localConfig['password']];

        // Cloud databases refuse a connection without TLS; a local one has no
        // sslmode set and keeps libpq's default.
        if (($this->localConfig['sslmode'] ?? '') !== '') {
            $environment['PGSSLMODE'] = (string) $this->localConfig['sslmode'];
        }

        return [$command, $environment];
    }

    protected function driver(): string
    {
        return $this->localConfig['driver'] === 'mysql' ? 'mysql' : 'pgsql';
    }

    /**
     * Resolve a binary path from config, falling back to the bare name on PATH.
     */
    protected function binary(string $key, string $default): string
    {
        $configured = $this->binaryPaths[$key] ?? null;

        return ($configured === null || $configured === '') ? $default : $configured;
    }
}
