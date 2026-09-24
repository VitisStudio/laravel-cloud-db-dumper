<?php

namespace VitisStudio\LaravelCloudDbDumper\Cloud;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
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

    /**
     * Line prefixes other extensions and tools write into the CLI's streams.
     */
    public const NOISE_PREFIXES = [
        'Xdebug:',
        'PHP Deprecated:',
        'PHP Warning:',
        'PHP Notice:',
        'Deprecated:',
        'Warning:',
        'Notice:',
        'Do not run Composer as root',
    ];

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
        // --show-sensitive or the CLI returns the token masked to "*****" plus
        // its last four characters, which authenticates as nothing.
        /** @var array<int, array{token: string, source: string, organization: string}> $tokens */
        $tokens = $this->json(['auth:token', '--list', '--show-sensitive']);

        foreach ($tokens as $token) {
            if (self::looksMasked($token['token'])) {
                throw new CloudCliException(
                    'The cloud CLI returned masked API tokens, so there is nothing to authenticate with. '
                    .'Check that `cloud auth:token --list --json --show-sensitive` prints full tokens, '
                    .'and update the CLI if it does not.'
                );
            }
        }

        return $tokens;
    }

    /**
     * Whether a token came back masked rather than usable (pure).
     */
    public static function looksMasked(string $token): bool
    {
        return $token === '' || str_contains($token, '*');
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
     * A single environment, fetched in full.
     *
     * A listing carries partial environments — notably without the `database`
     * relationship that databaseSchemaId is derived from — so anything needing
     * that field has to ask for the environment directly.
     *
     * @return array<string, mixed>
     */
    public function environment(string $environmentId): array
    {
        return $this->json(['environment:get', $environmentId]);
    }

    /**
     * Databases (schemas) in a cluster.
     *
     * @return array<int, array<string, mixed>>
     */
    public function databases(string $clusterId): array
    {
        return $this->json(['database:list', $clusterId]);
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
     * Render an argv list as a command someone could actually paste (pure).
     *
     * The token is named, never printed: the point is that the reader can see
     * the run was authenticated differently from a bare shell, which is why
     * pasting the bare command may behave differently.
     *
     * @param  array<int, string>  $argv
     */
    public static function describe(array $argv, bool $forwardsToken = false): string
    {
        $quoted = array_map(
            fn (string $argument) => preg_match('/^[A-Za-z0-9_@%+=:,.\/-]+$/', $argument) === 1
                ? $argument
                : "'".str_replace("'", "'\\''", $argument)."'",
            $argv,
        );

        return ($forwardsToken ? 'LARAVEL_CLOUD_TOKEN=<resolved token> ' : '').implode(' ', $quoted);
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
     * Fail before doing any work if the installed CLI is known to be too old.
     *
     * An unreadable version is not treated as a failure: a wrapper script or a
     * custom build should not be blocked on the strength of a guess.
     */
    public function ensureSupportedVersion(): void
    {
        $version = $this->version();

        if ($version === null || version_compare($version, self::MINIMUM_VERSION, '>=')) {
            return;
        }

        throw new CloudCliException(
            "The cloud CLI is v{$version}; this package needs v".self::MINIMUM_VERSION
            .' or newer. Older versions ask the Laravel Cloud API for an include it rejects, so'
            ." every database lookup fails with a bare 400.\n\n"
            .'Update it with: composer global update laravel/cloud-cli'
        );
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
            array_map('rtrim', preg_split('/\R/', $raw) ?: []),
            function (string $line) {
                $line = trim($line);

                if ($line === '') {
                    return false;
                }

                foreach (self::NOISE_PREFIXES as $prefix) {
                    if (str_starts_with($line, $prefix)) {
                        return false;
                    }
                }

                return true;
            },
        );

        return trim(implode("\n", $lines));
    }

    /**
     * Pull the message out of a Symfony exception block (pure).
     *
     * An HTTP failure never reaches the CLI's own JSON error handler, so it is
     * rendered as a bordered block on stdout. The useful sentence is the frame
     * after the first "In <file> line N:", hard-wrapped across several lines.
     */
    public static function symfonyErrorMessage(string $raw): ?string
    {
        $lines = array_map('trim', preg_split('/\R/', self::withoutNoise($raw)) ?: []);

        $start = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/^In [^\s]+ line \d+:$/', $line) === 1) {
                $start = $i + 1;

                break;
            }
        }

        if ($start === null) {
            return null;
        }

        $message = [];
        for ($i = $start; $i < count($lines); $i++) {
            if (preg_match('/^In [^\s]+ line \d+:$/', $lines[$i]) === 1) {
                break;
            }

            if ($lines[$i] !== '') {
                $message[] = $lines[$i];
            }
        }

        $joined = trim(preg_replace('/\s+/', ' ', implode(' ', $message)) ?? '');

        return $joined !== '' ? $joined : null;
    }

    /**
     * Run a cloud command with the read flags and decode its JSON output.
     *
     * @param  array<int, string>  $command
     * @return array<int|string, mixed>
     */
    protected function json(array $command): array
    {
        $argv = [$this->binary, ...$command, '--json', '-n', '--no-ansi'];
        $invocation = self::describe($argv, $this->apiToken !== null);

        $environment = [
            // The cloud CLI is itself a PHP program, so it inherits this
            // process's Xdebug settings and writes step-debug warnings into the
            // very streams we parse. Turn that off for the child only.
            'XDEBUG_MODE' => 'off',
            // Always set, never merely added when we have one: Symfony merges
            // our variables over the inherited environment, so an ambient
            // LARAVEL_CLOUD_TOKEN left over from CI or a shell profile would
            // otherwise authenticate every call and silently override the
            // organization we just resolved. False unsets it for the child.
            'LARAVEL_CLOUD_TOKEN' => $this->apiToken ?? false,
            // -n is not enough on its own: the CLI decides whether it may
            // prompt by looking at stdin and at the CI variables.
            'CI' => '1',
        ];

        try {
            $result = Process::env($environment)
                ->timeout(180)
                ->input('')
                ->run($argv);
        } catch (ProcessTimedOutException) {
            throw new CloudCliException(
                "The cloud CLI stopped responding while running `{$invocation}`.\n\n"
                .'It most likely tried to re-authenticate in a browser because every saved token was '
                .'rejected. Run `cloud auth` to sign in again, then retry.',
                $invocation,
            );
        }

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
            // An HTTP failure never reaches the CLI's JSON error handler and
            // arrives as a bordered Symfony block on stdout instead.
            $streams = trim($errorOutput."\n".$output);
            $reported = self::symfonyErrorMessage($streams) ?? self::withoutNoise($streams);

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
