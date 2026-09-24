<?php

namespace VitisStudio\LaravelCloudDbDumper\Support;

use Illuminate\Support\Facades\File;
use VitisStudio\LaravelCloudDbDumper\Cloud\DatabaseTarget;

/**
 * Reads and writes the gitignored .db-backup-prefs.json file, which remembers
 * the last selected database target and the client binaries chosen for this
 * machine, so repeat runs can skip both sets of questions.
 *
 * Credentials are never stored.
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
        $data = $this->read()['target'] ?? null;

        if (! is_array($data) || ! isset($data['clusterId'], $data['schemaName'])) {
            return null;
        }

        return DatabaseTarget::fromArray($data);
    }

    public function save(DatabaseTarget $target): void
    {
        $this->write(['target' => $target->toArray()] + $this->read());
    }

    /**
     * The client binaries and local server version remembered for this machine.
     *
     * @return array{driver: string, serverVersion: string|null, dump: string, restore: string}|null
     */
    public function clients(string $driver): ?array
    {
        $clients = $this->read()['clients'][$driver] ?? null;

        if (! is_array($clients) || ! isset($clients['dump'], $clients['restore'])) {
            return null;
        }

        /** @var array{driver: string, serverVersion: string|null, dump: string, restore: string} $clients */
        return $clients;
    }

    /**
     * @param  array{driver: string, serverVersion: string|null, dump: string, restore: string}  $clients
     */
    public function saveClients(string $driver, array $clients): void
    {
        $data = $this->read();
        $data['clients'][$driver] = $clients;

        $this->write($data);
    }

    /**
     * @return array<string, mixed>
     */
    protected function read(): array
    {
        if (! $this->exists()) {
            return [];
        }

        $data = json_decode((string) File::get($this->path), true);

        if (! is_array($data)) {
            return [];
        }

        // Files written before this key existed held the target at the top
        // level, so a flat payload is one of those.
        if (! isset($data['target']) && isset($data['clusterId'])) {
            return ['target' => $data];
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function write(array $data): void
    {
        File::ensureDirectoryExists(dirname($this->path));

        File::put(
            $this->path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
        );
    }
}
