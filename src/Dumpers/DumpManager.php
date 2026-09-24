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
 *
 * With storage disabled the dump goes to a private temporary directory
 * instead and is discarded once it has been restored, so production data
 * never comes to rest inside the project. Nothing is cached in that mode —
 * there is no file left to reuse.
 */
class DumpManager
{
    protected ?string $temporaryDirectory = null;

    /**
     * @param  array<string, string|null>  $binaryPaths  keyed by binary name (pg_dump, mysqldump)
     * @param  bool  $storeDumps  false keeps dumps out of the project and deletes them after use
     */
    public function __construct(
        protected readonly string $backupPath,
        protected readonly array $binaryPaths = [],
        protected readonly bool $storeDumps = true,
    ) {
        //
    }

    /**
     * Whether dumps are kept on disk after the command finishes.
     */
    public function storesDumps(): bool
    {
        return $this->storeDumps;
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

        return rtrim($this->directory(), '/').'/'.$filename;
    }

    /**
     * Where this run writes: the configured backup path, or a private
     * temporary directory when dumps are not being stored.
     */
    public function directory(): string
    {
        if ($this->storeDumps) {
            return $this->backupPath;
        }

        return $this->temporaryDirectory ??= rtrim(sys_get_temp_dir(), '/')
            .'/laravel-cloud-db-dumper-'.bin2hex(random_bytes(8));
    }

    /**
     * Delete a dump that was never meant to be kept. A stored dump is left
     * alone — discarding it is the user's call, not ours.
     */
    public function discard(string $path): void
    {
        if ($this->storeDumps) {
            return;
        }

        File::delete($path);

        if ($this->temporaryDirectory !== null && File::isDirectory($this->temporaryDirectory)) {
            File::deleteDirectory($this->temporaryDirectory);
        }
    }

    /**
     * Whether a cached dump already exists for this target today.
     */
    public function cachedCopyExists(DatabaseTarget $target): bool
    {
        return $this->storeDumps && File::exists($this->pathFor($target));
    }

    /**
     * Dump the target database to disk and return the file path.
     */
    public function dump(DatabaseTarget $target): string
    {
        if ($target->connection === null) {
            throw new InvalidArgumentException('Cannot dump a target without connection credentials.');
        }

        // 0700 so a dump of production data is not world-readable while it
        // sits in a shared temporary directory.
        File::ensureDirectoryExists($this->directory(), $this->storeDumps ? 0755 : 0700);

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
