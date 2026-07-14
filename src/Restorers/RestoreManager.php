<?php

namespace VitisStudio\LaravelCloudDbDumper\Restorers;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Restores a dump file into the LOCAL development database. Before restoring it
 * terminates other active connections to the target database so the restore is
 * not blocked. Both kill and restore are driver-aware (mysql / pgsql).
 *
 * The command-building methods are pure (no side effects) so they can be tested
 * without a live database or binaries.
 *
 * @phpstan-type LocalConfig array{driver: string, host: string, port: int|string, database: string, username: string, password: string}
 */
class RestoreManager
{
    /**
     * @param  LocalConfig  $localConfig  the resolved local connection config
     * @param  array<string, string|null>  $binaryPaths  keyed by binary name (psql, mysql)
     * @param  string|null  $connectionName  Laravel connection name used to issue KILL statements
     */
    public function __construct(
        protected readonly array $localConfig,
        protected readonly array $binaryPaths = [],
        protected readonly ?string $connectionName = null,
    ) {
        //
    }

    /**
     * Terminate other active connections to the local target database.
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
     * Restore the dump file into the local database.
     */
    public function restore(string $dumpFile): ProcessResult
    {
        if (! is_file($dumpFile)) {
            throw new RuntimeException("Dump file not found: {$dumpFile}");
        }

        [$command, $environment] = $this->driver() === 'mysql'
            ? $this->mySqlRestoreCommand($dumpFile)
            : $this->postgresRestoreCommand($dumpFile);

        $result = Process::env($environment)->run($command);

        if (! $result->successful()) {
            throw new RuntimeException(
                "Restore failed.\n".trim($result->errorOutput() ?: $result->output())
            );
        }

        return $result;
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
    public function postgresRestoreCommand(string $dumpFile): array
    {
        $binary = $this->binary('psql', 'psql');

        $command = sprintf(
            '%s --host=%s --port=%s --username=%s --dbname=%s --file=%s',
            $binary,
            escapeshellarg((string) $this->localConfig['host']),
            escapeshellarg((string) $this->localConfig['port']),
            escapeshellarg((string) $this->localConfig['username']),
            escapeshellarg((string) $this->localConfig['database']),
            escapeshellarg($dumpFile),
        );

        return [$command, ['PGPASSWORD' => (string) $this->localConfig['password']]];
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
