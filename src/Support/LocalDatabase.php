<?php

namespace VitisStudio\LaravelCloudDbDumper\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Asks the database the application is actually configured to talk to which
 * version it is, using the app's own connection rather than a guess.
 *
 * The answer decides which client binaries can be used: a dump taken from a
 * newer server may not load into an older one, and the machine usually has
 * several versions installed at once.
 */
class LocalDatabase
{
    public function __construct(
        protected readonly ?string $connection = null,
    ) {
        //
    }

    /**
     * The local server's version, or null when it cannot be reached.
     *
     * Being unable to answer is not an error: the database may simply not be
     * running yet, and that should not stop a dump from being taken.
     */
    public function serverVersion(): ?string
    {
        try {
            $connection = DB::connection($this->connection);
            $row = $connection->selectOne('select version() as version');

            $raw = is_object($row) ? ($row->version ?? null) : (is_array($row) ? ($row['version'] ?? null) : null);

            return is_string($raw) ? self::parseServerVersion($raw) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Reduce a server banner to its version (pure).
     *
     * Postgres answers "PostgreSQL 18.1 (Homebrew) on aarch64-apple-darwin…",
     * MySQL answers "8.0.36" and MariaDB "10.11.6-MariaDB-log".
     */
    public static function parseServerVersion(string $raw): ?string
    {
        return preg_match('/(\d+\.\d+(?:\.\d+)?)/', $raw, $matches) === 1 ? $matches[1] : null;
    }
}
