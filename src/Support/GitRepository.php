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
