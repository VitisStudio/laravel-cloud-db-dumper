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
     * Run a cloud command with the read flags and decode its JSON output.
     *
     * @param  array<int, string>  $command
     * @return array<int|string, mixed>
     */
    protected function json(array $command): array
    {
        $invocation = $this->binary.' '.implode(' ', $command);

        $environment = $this->apiToken !== null
            ? ['LARAVEL_CLOUD_TOKEN' => $this->apiToken]
            : [];

        $result = Process::env($environment)->run([
            $this->binary,
            ...$command,
            '--json',
            '-n',
            '--no-ansi',
        ]);

        $output = trim($result->output());
        $errorOutput = trim($result->errorOutput());

        $decoded = json_decode($output !== '' ? $output : $errorOutput, true);

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
            throw new CloudCliException(
                "Cloud CLI command failed: `{$invocation}`.\n".($errorOutput ?: $output),
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
