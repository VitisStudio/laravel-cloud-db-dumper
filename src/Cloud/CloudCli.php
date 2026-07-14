<?php

namespace VitisStudio\LaravelCloudDbDumper\Cloud;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Thin wrapper around the Laravel Cloud CLI binary. Reuses the user's existing
 * auth in ~/.config/cloud/config.json — no token handling here. Every call
 * shells out with `--json -n` and decodes stdout.
 */
class CloudCli
{
    public function __construct(
        protected readonly string $binary = 'cloud',
    ) {
        //
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
        $result = Process::run([
            $this->binary,
            ...$command,
            '--json',
            '-n',
        ]);

        if (! $result->successful()) {
            $invocation = $this->binary.' '.implode(' ', $command);

            throw new RuntimeException(
                "Cloud CLI command failed: `{$invocation}`.\n".trim($result->errorOutput() ?: $result->output())
            );
        }

        $decoded = json_decode(trim($result->output()), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Cloud CLI returned unexpected output for: '.implode(' ', $command));
        }

        return $decoded;
    }
}
