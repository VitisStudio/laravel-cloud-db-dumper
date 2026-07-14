<?php

namespace VitisStudio\LaravelCloudDbDumper\Cloud;

/**
 * A selected Laravel Cloud database, plus the live connection credentials
 * needed to dump it. Only the identifying fields are persisted to the
 * preferences file — credentials are always re-fetched and never written
 * to disk.
 *
 * @phpstan-type Connection array{protocol: string, hostname: string, port: int|string, username: string, password: string}
 */
class DatabaseTarget
{
    /**
     * @param  Connection|null  $connection
     */
    public function __construct(
        public readonly string $applicationId,
        public readonly string $applicationName,
        public readonly string $environmentId,
        public readonly string $environmentName,
        public readonly string $clusterId,
        public readonly string $clusterName,
        public readonly string $clusterType,
        public readonly string $schemaName,
        public readonly ?string $defaultSeeder = null,
        public readonly ?array $connection = null,
    ) {
        //
    }

    /**
     * Laravel database driver implied by the Cloud cluster type.
     */
    public function driver(): string
    {
        return str_contains(strtolower($this->clusterType), 'mysql') ? 'mysql' : 'pgsql';
    }

    /**
     * Return a copy of this target with live connection credentials attached.
     *
     * @param  Connection  $connection
     */
    public function withConnection(array $connection): self
    {
        return new self(
            $this->applicationId,
            $this->applicationName,
            $this->environmentId,
            $this->environmentName,
            $this->clusterId,
            $this->clusterName,
            $this->clusterType,
            $this->schemaName,
            $this->defaultSeeder,
            $connection,
        );
    }

    /**
     * Return a copy of this target with a different default seeder.
     */
    public function withDefaultSeeder(?string $seeder): self
    {
        return new self(
            $this->applicationId,
            $this->applicationName,
            $this->environmentId,
            $this->environmentName,
            $this->clusterId,
            $this->clusterName,
            $this->clusterType,
            $this->schemaName,
            $seeder,
            $this->connection,
        );
    }

    /**
     * Identifying fields only — safe to persist (no credentials).
     *
     * @return array{applicationId: string, applicationName: string, environmentId: string, environmentName: string, clusterId: string, clusterName: string, clusterType: string, schemaName: string, defaultSeeder: string|null}
     */
    public function toArray(): array
    {
        return [
            'applicationId' => $this->applicationId,
            'applicationName' => $this->applicationName,
            'environmentId' => $this->environmentId,
            'environmentName' => $this->environmentName,
            'clusterId' => $this->clusterId,
            'clusterName' => $this->clusterName,
            'clusterType' => $this->clusterType,
            'schemaName' => $this->schemaName,
            'defaultSeeder' => $this->defaultSeeder,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['applicationId'],
            (string) $data['applicationName'],
            (string) $data['environmentId'],
            (string) $data['environmentName'],
            (string) $data['clusterId'],
            (string) $data['clusterName'],
            (string) $data['clusterType'],
            (string) $data['schemaName'],
            isset($data['defaultSeeder']) ? (string) $data['defaultSeeder'] : null,
        );
    }
}
