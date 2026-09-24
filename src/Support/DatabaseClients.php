<?php

namespace VitisStudio\LaravelCloudDbDumper\Support;

use Illuminate\Support\Facades\Process;

/**
 * Finds the database client binaries installed on this machine and reports
 * their versions.
 *
 * A dump is refused outright when the client is older than the server it reads,
 * and a dump taken from a newer server can fail to load into an older one, so
 * which binary runs is not an implementation detail — it decides whether the
 * command works at all. Most machines have several installed and only one of
 * them on the PATH.
 */
class DatabaseClients
{
    /**
     * Where versioned installations keep their binaries, beyond the PATH.
     */
    public const SEARCH_GLOBS = [
        '/Users/Shared/DBngin/postgresql/*/bin',
        '/Applications/Postgres.app/Contents/Versions/*/bin',
        '/opt/homebrew/opt/postgresql@*/bin',
        '/opt/homebrew/opt/libpq/bin',
        '/usr/local/opt/postgresql@*/bin',
        '/usr/local/opt/libpq/bin',
        '/Users/Shared/DBngin/mysql/*/bin',
        '/opt/homebrew/opt/mysql-client*/bin',
        '/opt/homebrew/opt/mysql@*/bin',
        '/usr/local/opt/mysql-client*/bin',
    ];

    /**
     * @param  array<int, string>  $globs
     */
    public function __construct(
        protected readonly array $globs = self::SEARCH_GLOBS,
    ) {
        //
    }

    /**
     * Every copy of a binary this machine has, newest version first.
     *
     * @return array<int, array{path: string, version: string}>
     */
    public function discover(string $binary): array
    {
        $found = [];

        foreach ($this->candidatePaths($binary) as $path) {
            $version = self::parseVersion($this->versionOutput($path));

            if ($version !== null && ! isset($found[$path])) {
                $found[$path] = ['path' => $path, 'version' => $version];
            }
        }

        $clients = array_values($found);

        usort($clients, fn (array $a, array $b) => version_compare($b['version'], $a['version']));

        return $clients;
    }

    /**
     * The best client for a server, or null when nothing can read it.
     *
     * Same major version is ideal. Failing that the oldest client that is still
     * newer than the server, because a client may always read an older server
     * but never a newer one.
     *
     * @param  array<int, array{path: string, version: string}>  $clients
     * @return array{path: string, version: string}|null
     */
    public static function bestFor(array $clients, ?string $serverVersion): ?array
    {
        if ($clients === []) {
            return null;
        }

        if ($serverVersion === null) {
            return $clients[0];
        }

        $server = self::major($serverVersion);

        foreach ($clients as $client) {
            if (self::major($client['version']) === $server) {
                return $client;
            }
        }

        $newer = array_values(array_filter(
            $clients,
            fn (array $client) => self::major($client['version']) > $server,
        ));

        return $newer === [] ? null : $newer[count($newer) - 1];
    }

    /**
     * Combine chosen binary paths with the ones set in config (pure).
     *
     * Config declares every binary key whether or not it is set, so its null
     * entries have to be dropped rather than merged — a plain union against it
     * keeps those nulls and silently discards the paths chosen at runtime.
     *
     * @param  array<string, mixed>  $configured
     * @param  array<string, string>  $chosen
     * @return array<string, string>
     */
    public static function merge(array $configured, array $chosen): array
    {
        $usable = array_filter($configured, fn ($path) => is_string($path) && $path !== '');

        /** @var array<string, string> */
        return $chosen + $usable;
    }

    /**
     * Pull a version out of a client's --version banner (pure).
     *
     * pg_dump prints "pg_dump (PostgreSQL) 18.1", mysqldump prints
     * "mysqldump  Ver 8.0.36 for macos14 on arm64".
     */
    public static function parseVersion(string $raw): ?string
    {
        return preg_match('/(\d+\.\d+(?:\.\d+)?)/', $raw, $matches) === 1 ? $matches[1] : null;
    }

    /**
     * The major version, which is the only part that decides compatibility.
     */
    public static function major(string $version): int
    {
        return (int) explode('.', $version)[0];
    }

    /**
     * @return array<int, string>
     */
    protected function candidatePaths(string $binary): array
    {
        $paths = [];

        $onPath = trim(Process::run(['which', $binary])->output());

        if ($onPath !== '' && is_executable($onPath)) {
            $paths[] = $onPath;
        }

        foreach ($this->globs as $glob) {
            foreach (glob($glob) ?: [] as $directory) {
                $candidate = rtrim($directory, '/').'/'.$binary;

                if (is_executable($candidate)) {
                    $paths[] = $candidate;
                }
            }
        }

        return array_values(array_unique($paths));
    }

    protected function versionOutput(string $path): string
    {
        $result = Process::env(['XDEBUG_MODE' => 'off'])->run([$path, '--version']);

        return $result->successful() ? $result->output() : '';
    }
}
