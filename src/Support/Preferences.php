<?php

namespace VitisStudio\LaravelCloudDbDumper\Support;

use Illuminate\Support\Facades\File;
use VitisStudio\LaravelCloudDbDumper\Cloud\DatabaseTarget;

/**
 * Reads and writes the gitignored .db-backup-prefs.json file, which remembers
 * the last selected database target so repeat runs can skip the Cloud
 * navigation prompts. Credentials are never stored.
 */
class Preferences
{
    public function __construct(
        protected readonly string $path,
    ) {
        //
    }

    public function exists(): bool
    {
        return File::exists($this->path);
    }

    /**
     * Load the saved target, or null when no (valid) preferences file exists.
     */
    public function load(): ?DatabaseTarget
    {
        if (! $this->exists()) {
            return null;
        }

        $data = json_decode((string) File::get($this->path), true);

        if (! is_array($data) || ! isset($data['clusterId'], $data['schemaName'])) {
            return null;
        }

        return DatabaseTarget::fromArray($data);
    }

    public function save(DatabaseTarget $target): void
    {
        File::ensureDirectoryExists(dirname($this->path));

        File::put(
            $this->path,
            json_encode($target->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
        );
    }
}
