<?php

namespace VitisStudio\LaravelCloudDbDumper\Dumpers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Spatie\DbDumper\Databases\MySql;
use Spatie\DbDumper\Databases\PostgreSql;
use Spatie\DbDumper\DbDumper;
use VitisStudio\LaravelCloudDbDumper\Cloud\DatabaseTarget;

/**
 * Builds a spatie/db-dumper instance from a target's live credentials and
 * writes the dump to a per-day timestamped file. A same-day file can be
 * reused as a cached copy to avoid re-downloading the same data.
 */
class DumpManager
{
    /**
     * @param  array<string, string|null>  $binaryPaths  keyed by binary name (pg_dump, mysqldump)
     */
    public function __construct(
        protected readonly string $backupPath,
        protected readonly array $binaryPaths = [],
    ) {
        //
    }

    /**
     * Absolute path of the dump file for this target today (may not yet exist).
     */
    public function pathFor(DatabaseTarget $target): string
    {
        $filename = sprintf(
            '%s_%s_%s.sql',
            $target->schemaName,
            $target->driver(),
            Carbon::now()->format('Y-m-d'),
        );

        return rtrim($this->backupPath, '/').'/'.$filename;
    }

    /**
     * Whether a cached dump already exists for this target today.
     */
    public function cachedCopyExists(DatabaseTarget $target): bool
    {
        return File::exists($this->pathFor($target));
    }

    /**
     * Dump the target database to disk and return the file path.
     */
    public function dump(DatabaseTarget $target): string
    {
        if ($target->connection === null) {
            throw new InvalidArgumentException('Cannot dump a target without connection credentials.');
        }

        File::ensureDirectoryExists($this->backupPath);

        $path = $this->pathFor($target);

        $this->dumperFor($target)->dumpToFile($path);

        return $path;
    }

    protected function dumperFor(DatabaseTarget $target): DbDumper
    {
        /** @var array{hostname: string, port: int|string, username: string, password: string} $connection */
        $connection = $target->connection;

        $dumper = $target->driver() === 'mysql' ? MySql::create() : PostgreSql::create();

        $dumper
            ->setDbName($target->schemaName)
            ->setHost($connection['hostname'])
            ->setPort((int) $connection['port'])
            ->setUserName($connection['username'])
            ->setPassword($connection['password']);

        $binaryDirectory = $this->binaryDirectoryFor($target->driver());

        if ($binaryDirectory !== null) {
            $dumper->setDumpBinaryPath($binaryDirectory);
        }

        return $dumper;
    }

    /**
     * spatie/db-dumper expects the *directory* containing the binary (it
     * appends "pg_dump"/"mysqldump"). Derive it from the configured full path.
     */
    protected function binaryDirectoryFor(string $driver): ?string
    {
        $binary = $driver === 'mysql' ? 'mysqldump' : 'pg_dump';
        $configured = $this->binaryPaths[$binary] ?? null;

        if ($configured === null || $configured === '') {
            return null;
        }

        return rtrim(dirname($configured), '/').'/';
    }
}
