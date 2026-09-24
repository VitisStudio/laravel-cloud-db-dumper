<?php

namespace VitisStudio\LaravelCloudDbDumper\Cloud;

use Illuminate\Support\Facades\File;

/**
 * Reads the repository-local .cloud/config.json that `cloud repo:config`
 * writes. The Cloud CLI resolves its own defaults from this file, so honouring
 * it here keeps `db:pull` consistent with every other cloud command run in the
 * same project.
 *
 * Nothing is ever written back — that stays the CLI's job.
 */
class LocalConfig
{
    public function __construct(
        protected readonly string $path,
    ) {
        //
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if (! File::exists($this->path)) {
            return [];
        }

        $decoded = json_decode((string) File::get($this->path), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function get(string $key): ?string
    {
        $value = $this->all()[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function applicationId(): ?string
    {
        return $this->get('application_id');
    }

    public function environmentId(): ?string
    {
        return $this->get('environment_id');
    }

    public function organizationId(): ?string
    {
        return $this->get('organization_id');
    }
}
