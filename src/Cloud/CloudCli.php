<?php

namespace VitisStudio\LaravelCloudDbDumper\Cloud;

use Illuminate\Support\Facades\Process;

/**
 * Thin wrapper around the Laravel Cloud CLI binary (>= 0.5). Reuses the user's
 * existing auth in ~/.config/cloud/config.json — no token handling here beyond
 * forwarding one already-saved token through LARAVEL_CLOUD_TOKEN when the CLI
 * cannot pick an organization by itself. Every call shells out with
 * `--json -n --no-ansi` and decodes stdout.
 */
class CloudCli
{
    /**
     * Oldest cloud CLI this package works with.
     *
     * 0.5 introduced the per-organization API tokens we resolve, and 0.5.2 and
     * earlier ask the API for an include it no longer allows, so every
     * database-cluster:list fails with a 400 that says nothing about versions.
     */
    public const MINIMUM_VERSION = '0.5.3';

    public function __construct(
        protected readonly string $binary = 'cloud',
        protected readonly ?string $apiToken = null,
    ) {
        //
    }

    /**
     * Return a copy that runs every command as the given API token.
     */
    public function withApiToken(?string $apiToken): self
    {
        return new self($this->binary, $apiToken);
    }

    /**
     * Saved API tokens, one per organization.
     *
     * The token values are secrets: pass them to withApiToken(), never write
     * them to disk or to console output.
     *
     * @return array<int, array{token: string, source: string, organization: string}>
     */
    public function tokens(): array
    {
        /** @var array<int, array{token: string, source: string, organization: string}> */
        return $this->json(['auth:token', '--list']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function applications(): array
    {
        return $this->json(['application:list']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function environments(string $applicationId): array
    {
        return $this->json(['environment:list', $applicationId]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function clusters(): array
    {
        return $this->json(['database-cluster:list']);
    }

    /**
     * Fetch a cluster with sensitive connection credentials revealed.
     *
     * @return array<string, mixed>
     */
    public function clusterWithCredentials(string $clusterId): array
    {
        return $this->json(['database-cluster:get', $clusterId, '--show-sensitive']);
    }

    /**
     * The installed CLI's version, or null when it cannot be determined.
     */
    public function version(): ?string
    {
        $result = Process::env(['XDEBUG_MODE' => 'off'])->run([$this->binary, '--version']);

        if (! $result->successful()) {
            return null;
        }

        return self::parseVersion(self::withoutNoise($result->output()));
    }

    /**
     * Advice to add to a failure when the CLI is too old to be trusted, or
     * null when its version is fine or unknown.
     */
    public function outdatedHint(): ?string
    {
        $version = $this->version();

        if ($version === null || version_compare($version, self::MINIMUM_VERSION, '>=')) {
            return null;
        }

        return "The cloud CLI is v{$version}; this package needs v".self::MINIMUM_VERSION
            .' or newer. Older versions ask the Laravel Cloud API for an include it rejects, '
            .'which fails every database lookup. Update with `composer global update laravel/cloud-cli`.';
    }

    /**
     * Pull a version out of `cloud --version` output (pure).
     */
    public static function parseVersion(string $raw): ?string
    {
        return preg_match('/\bv?(\d+\.\d+\.\d+)\b/', $raw, $matches) === 1
            ? $matches[1]
            : null;
    }

    /**
     * Decode JSON that may be surrounded by noise another extension wrote to
     * the same stream (pure). Returns null when there is no JSON in there.
     *
     * @return array<int|string, mixed>|null
     */
    public static function decodeJson(string $raw): ?array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        // Drop the noise before looking for structure. Doing it the other way
        // round finds the "[" in "[Step Debug]" and parses from there.
        $clean = self::withoutNoise($raw);

        $decoded = json_decode($clean, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        // A payload usually arrives on one line, so try each in turn.
        foreach (preg_split('/\R/', $clean) ?: [] as $line) {
            $decoded = json_decode(trim($line), true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Last resort for pretty-printed output: the outermost structure, the
        // earliest opening delimiter being the outer one.
        $candidates = [];

        foreach ([['{', '}'], ['[', ']']] as [$open, $close]) {
            $first = strpos($clean, $open);
            $last = strrpos($clean, $close);

            if ($first !== false && $last !== false && $last > $first) {
                $candidates[$first] = substr($clean, $first, $last - $first + 1);
            }
        }

        ksort($candidates);

        foreach ($candidates as $candidate) {
            $decoded = json_decode($candidate, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Strip lines other extensions wrote into the stream, so an error message
     * shown to the user is the CLI's own (pure).
     */
    public static function withoutNoise(string $raw): string
    {
        $lines = array_filter(
            preg_split('/\R/', $raw) ?: [],
            fn (string $line) => trim($line) !== '' && ! str_starts_with(trim($line), 'Xdebug:'),
        );

        return trim(implode("\n", $lines));
    }

    /**
     * Run a cloud command with the read flags and decode its JSON output.
     *
     * @param  array<int, string>  $command
     * @return array<int|string, mixed>
     */
    protected function json(array $command): array
    {
        $invocation = $this->binary.' '.implode(' ', $command);

        // The cloud CLI is itself a PHP program, so it inherits this process's
        // Xdebug settings and writes step-debug warnings into the very streams
        // we parse. Turn that off for the child only.
        $environment = ['XDEBUG_MODE' => 'off'];

        if ($this->apiToken !== null) {
            $environment['LARAVEL_CLOUD_TOKEN'] = $this->apiToken;
        }

        $result = Process::env($environment)->run([
            $this->binary,
            ...$command,
            '--json',
            '-n',
            '--no-ansi',
        ]);

        $output = trim($result->output());
        $errorOutput = trim($result->errorOutput());

        $decoded = self::decodeJson($output) ?? self::decodeJson($errorOutput);

        // Failures arrive as {"error": true, "message": "..."} on STDERR; the
        // CLI's own message is far more useful than the raw stream.
        if (is_array($decoded) && ($decoded['error'] ?? false) === true) {
            throw new CloudCliException(
                (string) ($decoded['message'] ?? "Cloud CLI command failed: `{$invocation}`."),
                $invocation,
                $errorOutput ?: $output,
            );
        }

        if (! $result->successful()) {
            // Both streams, because a failing run may put its explanation on
            // either one, and whichever we ignore is the one that mattered.
            $reported = self::withoutNoise(trim($errorOutput."\n".$output));

            throw new CloudCliException(
                trim("Cloud CLI command failed: `{$invocation}`.\n".$reported),
                $invocation,
                $errorOutput ?: $output,
            );
        }

        if (! is_array($decoded)) {
            throw new CloudCliException(
                "Cloud CLI returned unexpected output for: `{$invocation}`.",
                $invocation,
                $output,
            );
        }

        return $decoded;
    }
}
