<?php

namespace VitisStudio\LaravelCloudDbDumper\Cloud;

use RuntimeException;

/**
 * The checks a push must pass before anything on Laravel Cloud is dropped.
 *
 * A push empties the destination before it restores, so naming the wrong
 * database is not a failed run but lost data. Every check here is pure, and
 * each one fails closed.
 */
class PushGuard
{
    /**
     * Environment names refused as a destination outright.
     */
    public const PRODUCTION_NAMES = ['prod', 'production'];

    /**
     * Checks that need only the resolved targets, run before any prompt.
     */
    public static function assertDestination(DatabaseTarget $source, DatabaseTarget $destination): void
    {
        if ($destination->driver() !== 'pgsql') {
            throw new RuntimeException('Pushing is supported for Postgres clusters only.');
        }

        if ($source->driver() !== $destination->driver()) {
            throw new RuntimeException(
                "Cannot push a {$source->driver()} dump into a {$destination->driver()} database."
            );
        }

        if ($source->environmentId === $destination->environmentId) {
            throw new RuntimeException('The source and destination are the same environment.');
        }

        if (in_array(strtolower($destination->environmentName), self::PRODUCTION_NAMES, true)) {
            throw new RuntimeException(
                "Refusing to push into \"{$destination->environmentName}\": it is named as production."
            );
        }
    }

    /**
     * Checks that need live credentials, run after both have been fetched.
     *
     * Two environments can be wired to one database, and a restored cluster
     * can resolve to its source, so the hostnames are the last word on whether
     * the destination is somewhere else.
     */
    public static function assertDistinctConnections(DatabaseTarget $source, DatabaseTarget $destination): void
    {
        if ($source->connection === null || $destination->connection === null) {
            throw new RuntimeException('Both databases need credentials before a push can be checked.');
        }

        $sameHost = strcasecmp(
            (string) $source->connection['hostname'],
            (string) $destination->connection['hostname'],
        ) === 0;

        if ($sameHost && $source->schemaName === $destination->schemaName) {
            throw new RuntimeException(
                "The destination resolves to the source database ({$destination->connection['hostname']}). Nothing was changed."
            );
        }
    }
}
