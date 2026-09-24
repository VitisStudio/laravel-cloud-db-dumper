<?php

namespace VitisStudio\LaravelCloudDbDumper\Dumpers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;
use Spatie\DbDumper\Databases\MySql;
use Spatie\DbDumper\Databases\PostgreSql;
use Spatie\DbDumper\DbDumper;
use Spatie\DbDumper\Exceptions\DumpFailed;
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

        return self::normalisePath(rtrim($this->directory(), '/\\')).'/'.$filename;
    }

    /**
     * Where this run writes: the configured backup path, or a private
     * temporary directory when dumps are not being stored.
     */
    public function directory(): string
    {
        if ($this->storeDumps) {
            return self::normalisePath($this->backupPath);
        }

        return $this->temporaryDirectory ??= self::normalisePath(rtrim(sys_get_temp_dir(), '/\\'))
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
     * Every stored dump of this target, newest first.
     *
     * A dump filename carries the database, the driver and the day it was
     * taken, so the folder itself is the history — no index to keep in sync.
     *
     * @return array<int, array{path: string, date: string, size: int}>
     */
    public function existingDumps(DatabaseTarget $target): array
    {
        return $this->storedDumps($target);
    }

    /**
     * Every stored dump, newest first, optionally narrowed to one target.
     *
     * Only files this package wrote are ever reported: anything else sharing
     * the directory is invisible here, and so cannot be deleted by prune.
     *
     * @return array<int, array{path: string, database: string, driver: string, date: string, size: int}>
     */
    public function storedDumps(?DatabaseTarget $target = null): array
    {
        if (! $this->storeDumps || ! File::isDirectory($this->directory())) {
            return [];
        }

        $dumps = [];

        foreach (File::files($this->directory()) as $file) {
            $parsed = self::parseFilename($file->getFilename());

            if ($parsed === null) {
                continue;
            }

            if ($target !== null
                && ($parsed['database'] !== $target->schemaName || $parsed['driver'] !== $target->driver())) {
                continue;
            }

            // A dump that failed leaves an empty file behind. Offering it as a
            // restorable copy would wipe the local database with nothing.
            if ($file->getSize() === 0) {
                continue;
            }

            $dumps[] = [
                'path' => self::normalisePath($file->getPathname()),
                'database' => $parsed['database'],
                'driver' => $parsed['driver'],
                'date' => $parsed['date'],
                'size' => $file->getSize(),
            ];
        }

        usort($dumps, fn (array $a, array $b) => strcmp($b['date'], $a['date']));

        return $dumps;
    }

    /**
     * Delete stored dumps, returning how many went.
     *
     * Each path is re-checked against the dump naming scheme before it is
     * unlinked, so a caller cannot talk this into deleting something else.
     *
     * @param  array<int, string>  $paths
     */
    public function delete(array $paths): int
    {
        $deleted = 0;

        foreach ($paths as $path) {
            if (self::parseFilename(basename($path)) === null) {
                continue;
            }

            if (File::delete($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Use one separator everywhere, so a path from a directory listing and one
     * built by pathFor() are the same string on Windows too (pure).
     */
    public static function normalisePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    /**
     * Pull the database, driver and date back out of a dump filename (pure).
     *
     * Read from the right, because a database name may itself contain the
     * underscores this joins on.
     *
     * @return array{database: string, driver: string, date: string}|null
     */
    public static function parseFilename(string $filename): ?array
    {
        if (! str_ends_with($filename, '.sql')) {
            return null;
        }

        $segments = explode('_', substr($filename, 0, -4));

        if (count($segments) < 3) {
            return null;
        }

        $date = array_pop($segments);
        $driver = array_pop($segments);

        if (! in_array($driver, ['mysql', 'pgsql'], true)) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        return [
            'database' => implode('_', $segments),
            'driver' => $driver,
            'date' => $date,
        ];
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

        try {
            $this->dumperFor($target)->dumpToFile($path);
        } catch (DumpFailed $e) {
            throw new RuntimeException(self::explainDumpFailure($e->getMessage(), $target->driver()), 0, $e);
        }

        return $path;
    }

    /**
     * Add the missing half of a dump failure the caller can act on (pure).
     *
     * pg_dump and mysqldump refuse to read a server newer than themselves, and
     * say so in terms of protocol versions without mentioning that the fix is a
     * different binary.
     */
    public static function explainDumpFailure(string $message, string $driver): string
    {
        if (stripos($message, 'server version') === false) {
            return $message;
        }

        $binary = $driver === 'mysql' ? 'mysqldump' : 'pg_dump';
        $setting = $driver === 'mysql' ? 'MYSQLDUMP_PATH' : 'PG_DUMP_PATH';

        return $message."\n\n"
            ."The {$binary} on your PATH is older than the server. Point {$setting} at a newer one — "
            .'Postgres.app and DBngin keep every installed version under their own directory — or install '
            .'a matching client.';
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
