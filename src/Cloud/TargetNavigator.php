<?php

namespace VitisStudio\LaravelCloudDbDumper\Cloud;

use RuntimeException;

use function Laravel\Prompts\select;

/**
 * Walks the Laravel Cloud hierarchy interactively (application → environment →
 * schema) and resolves the owning cluster, returning a fully-built
 * DatabaseTarget. The cluster-resolution logic is separated from the prompts so
 * it can be unit tested against canned CLI output.
 */
class TargetNavigator
{
    public function __construct(
        protected readonly CloudCli $cloud,
    ) {
        //
    }

    /**
     * Interactively select an application, environment and database, returning
     * a target WITHOUT credentials. Use attachCredentials() to fetch those.
     */
    public function navigate(): DatabaseTarget
    {
        $applications = $this->cloud->applications();

        if ($applications === []) {
            throw new RuntimeException('No Laravel Cloud applications found for this organization.');
        }

        $application = $this->choose('Select an application', $applications);

        $environments = $this->cloud->environments((string) $application['id']);

        if ($environments === []) {
            throw new RuntimeException("No environments found for application \"{$application['name']}\".");
        }

        $environment = $this->choose('Select an environment', $environments);

        $clusters = $this->cloud->clusters();
        $cluster = $this->resolveCluster($clusters, (string) ($environment['databaseSchemaId'] ?? ''));

        $schema = $this->chooseSchema($cluster, (string) ($environment['databaseSchemaId'] ?? ''));

        return new DatabaseTarget(
            applicationId: (string) $application['id'],
            applicationName: (string) $application['name'],
            environmentId: (string) $environment['id'],
            environmentName: (string) $environment['name'],
            clusterId: (string) $cluster['id'],
            clusterName: (string) $cluster['name'],
            clusterType: (string) $cluster['type'],
            schemaName: (string) $schema['name'],
        );
    }

    /**
     * Fetch live credentials for a target and return a copy carrying them.
     */
    public function attachCredentials(DatabaseTarget $target): DatabaseTarget
    {
        $cluster = $this->cloud->clusterWithCredentials($target->clusterId);

        $connection = $cluster['connection'] ?? null;

        if (! is_array($connection) || ! isset($connection['hostname'], $connection['password'])) {
            throw new RuntimeException("Could not retrieve connection credentials for cluster \"{$target->clusterName}\".");
        }

        /** @var array{protocol: string, hostname: string, port: int|string, username: string, password: string} $connection */
        return $target->withConnection($connection);
    }

    /**
     * Find the cluster that owns the given schema id (pure).
     *
     * @param  array<int, array<string, mixed>>  $clusters
     * @return array<string, mixed>
     */
    public function resolveCluster(array $clusters, string $schemaId): array
    {
        foreach ($clusters as $cluster) {
            foreach ($cluster['schemas'] ?? [] as $schema) {
                if ((string) ($schema['id'] ?? '') === $schemaId && $schemaId !== '') {
                    return $cluster;
                }
            }
        }

        throw new RuntimeException('Could not find a database cluster for the selected environment.');
    }

    /**
     * Select a schema within a cluster, defaulting to the one the environment
     * is wired to.
     *
     * @param  array<string, mixed>  $cluster
     * @return array<string, mixed>
     */
    protected function chooseSchema(array $cluster, string $environmentSchemaId): array
    {
        $schemas = $cluster['schemas'] ?? [];

        if ($schemas === []) {
            throw new RuntimeException("Cluster \"{$cluster['name']}\" has no databases.");
        }

        if (count($schemas) === 1) {
            return $schemas[0];
        }

        $options = [];
        foreach ($schemas as $schema) {
            $options[(string) $schema['id']] = (string) $schema['name'];
        }

        $default = $environmentSchemaId !== '' && isset($options[$environmentSchemaId])
            ? $environmentSchemaId
            : null;

        $selectedId = select(
            label: 'Select a database',
            options: $options,
            default: $default,
        );

        foreach ($schemas as $schema) {
            if ((string) $schema['id'] === (string) $selectedId) {
                return $schema;
            }
        }

        throw new RuntimeException('Selected database could not be resolved.');
    }

    /**
     * Prompt to choose an item from a list keyed by id, labelled by name.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    protected function choose(string $label, array $items): array
    {
        $options = [];
        foreach ($items as $item) {
            $options[(string) $item['id']] = (string) $item['name'];
        }

        $selectedId = select(label: $label, options: $options);

        foreach ($items as $item) {
            if ((string) $item['id'] === (string) $selectedId) {
                return $item;
            }
        }

        throw new RuntimeException('Selection could not be resolved.');
    }
}
