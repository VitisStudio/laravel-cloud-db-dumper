<?php

namespace VitisStudio\LaravelCloudDbDumper\Support;

use Illuminate\Support\Facades\Process;

/**
 * Reads the project's git origin so an application can be matched to the
 * repository it deploys from, the way the Cloud CLI resolves applications.
 */
class GitRepository
{
    public function __construct(
        protected readonly string $basePath,
    ) {
        //
    }

    /**
     * The "owner/repo" this project pushes to, or null when there is no origin.
     */
    public function fullName(): ?string
    {
        $result = Process::path($this->basePath)->run(['git', 'remote', 'get-url', 'origin']);

        if (! $result->successful()) {
            return null;
        }

        return self::parseFullName(trim($result->output()));
    }

    /**
     * The repository root, or null outside a repository.
     *
     * The cloud CLI derives its own .cloud/config.json path this way, from the
     * working directory of whoever invoked it — so anything hoping to read the
     * same file has to resolve it the same way rather than from the Laravel
     * application root, which is a different directory in a monorepo.
     */
    public function root(): ?string
    {
        $result = Process::path($this->basePath)->run(['git', 'rev-parse', '--show-toplevel']);

        if (! $result->successful()) {
            return null;
        }

        $root = trim($result->output());

        // git answers with forward slashes even on Windows, where everything
        // else hands back backslashes. Settle on one so callers can compare
        // and concatenate without caring which produced the string.
        return $root !== '' ? str_replace('\\', '/', $root) : null;
    }

    /**
     * Reduce a remote URL to "owner/repo" (pure).
     *
     * Handles the SSH and HTTPS forms git writes, with or without the .git
     * suffix: git@github.com:owner/repo.git, https://github.com/owner/repo,
     * ssh://git@github.com/owner/repo.git.
     */
    public static function parseFullName(string $remoteUrl): ?string
    {
        if ($remoteUrl === '') {
            return null;
        }

        $path = str_contains($remoteUrl, '://')
            ? (string) parse_url($remoteUrl, PHP_URL_PATH)
            : (string) preg_replace('/^.*:/', '', $remoteUrl);

        $path = trim($path, '/');

        if (str_ends_with($path, '.git')) {
            $path = substr($path, 0, -4);
        }

        $segments = array_values(array_filter(explode('/', $path)));

        if (count($segments) < 2) {
            return null;
        }

        return implode('/', array_slice($segments, -2));
    }
}
