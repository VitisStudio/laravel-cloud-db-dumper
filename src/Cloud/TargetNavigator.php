<?php

namespace VitisStudio\LaravelCloudDbDumper\Cloud;

use RuntimeException;
use VitisStudio\LaravelCloudDbDumper\Support\GitRepository;

use function Laravel\Prompts\note;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;

/**
 * Resolves a Laravel Cloud database target (organization → application →
 * environment → database), mirroring how the Cloud CLI's own resolvers behave:
 * an identifier may be an id or a name, `.cloud/config.json` supplies the
 * project defaults, a sole candidate is taken without asking, and anything
 * still ambiguous is prompted for.
 *
 * Resolution is kept separate from the prompts so it can be unit tested against
 * canned CLI output.
 */
class TargetNavigator
{
    protected CloudCli $cloud;

    protected OrganizationResolver $organizations;

    protected ?string $organization = null;

    protected bool $organizationResolved = false;

    public function __construct(
        CloudCli $cloud,
        ?OrganizationResolver $organizations = null,
        protected readonly ?LocalConfig $localConfig = null,
        protected readonly ?GitRepository $repository = null,
    ) {
        $this->cloud = $cloud;
        $this->organizations = $organizations ?? new OrganizationResolver($cloud);
    }

    /**
     * Preselect the organization, so a saved target does not re-prompt.
     */
    public function useOrganization(?string $organization): void
    {
        $this->organization = $organization !== '' ? $organization : null;
    }

    /**
     * The organization the last CLI call ran as, once one has been resolved.
     */
    public function organization(): ?string
    {
        return $this->organization;
    }

    /**
     * Resolve an application, environment and database, returning a target
     * WITHOUT credentials. Use attachCredentials() to fetch those.
     *
     * Both identifiers accept an id or a name, exactly as the cloud CLI does.
     */
    public function navigate(?string $application = null, ?string $environment = null): DatabaseTarget
    {
        $applications = $this->call(fn (CloudCli $cloud) => $cloud->applications(), 'Fetching applications...');

        if ($applications === []) {
            throw new RuntimeException('No Laravel Cloud applications found for this organization.');
        }

        $application = $this->resolveApplication($applications, $application);

        $environments = $this->environmentsFor($application);

        if ($environments === []) {
            throw new RuntimeException("No environments found for application \"{$application['name']}\".");
        }

        $environment = $this->resolveEnvironment($environments, $environment, $this->defaultEnvironmentId($environments));

        $schemaId = $this->schemaIdFor($environment);

        $clusters = $this->call(fn (CloudCli $cloud) => $cloud->clusters(), 'Fetching database clusters...');

        if ($clusters === []) {
            throw new RuntimeException('No database clusters found for this organization.');
        }

        // Match the environment to its cluster where we can, and simply ask
        // when we cannot, rather than dead-ending on a lookup failure.
        $cluster = self::findCluster($clusters, $schemaId) ?? $this->chooseCluster($clusters);

        $schema = $this->chooseSchema($cluster, $schemaId);

        return new DatabaseTarget(
            applicationId: (string) $application['id'],
            applicationName: (string) $application['name'],
            environmentId: (string) $environment['id'],
            environmentName: (string) $environment['name'],
            clusterId: (string) $cluster['id'],
            clusterName: (string) $cluster['name'],
            clusterType: (string) $cluster['type'],
            schemaName: (string) $schema['name'],
            organizationName: $this->organization,
        );
    }

    /**
     * Fetch live credentials for a target and return a copy carrying them.
     */
    public function attachCredentials(DatabaseTarget $target): DatabaseTarget
    {
        $this->useOrganization($this->organization ?? $target->organizationName);

        $cluster = $this->call(
            fn (CloudCli $cloud) => $cloud->clusterWithCredentials($target->clusterId),
            'Fetching database credentials...',
        );

        $connection = $cluster['connection'] ?? null;

        if (! is_array($connection) || ! isset($connection['hostname'], $connection['password'])) {
            throw new RuntimeException("Could not retrieve connection credentials for cluster \"{$target->clusterName}\".");
        }

        /** @var array{protocol: string, hostname: string, port: int|string, username: string, password: string} $connection */
        return $target
            ->withOrganization($this->organization ?? $target->organizationName)
            ->withConnection($connection);
    }

    /**
     * Find the cluster that owns the given schema id, or null (pure).
     *
     * @param  array<int, array<string, mixed>>  $clusters
     * @return array<string, mixed>|null
     */
    public static function findCluster(array $clusters, string $schemaId): ?array
    {
        if ($schemaId === '') {
            return null;
        }

        foreach ($clusters as $cluster) {
            foreach ($cluster['schemas'] ?? [] as $schema) {
                if ((string) ($schema['id'] ?? '') === $schemaId) {
                    return $cluster;
                }
            }
        }

        return null;
    }

    /**
     * The schema id an environment is wired to.
     *
     * A listing gives partial environments, so when the id is absent the
     * environment is re-fetched in full — the same thing the cloud CLI's own
     * resolver does before it needs the relationship data.
     *
     * @param  array<string, mixed>  $environment
     */
    protected function schemaIdFor(array $environment): string
    {
        $schemaId = (string) ($environment['databaseSchemaId'] ?? '');

        if ($schemaId !== '') {
            return $schemaId;
        }

        $full = $this->call(
            fn (CloudCli $cloud) => $cloud->environment((string) $environment['id']),
            'Fetching environment...',
        );

        return (string) ($full['databaseSchemaId'] ?? '');
    }

    /**
     * Match an item by exact id or exact name (pure).
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>|null
     */
    public static function findByIdentifier(array $items, string $identifier): ?array
    {
        foreach ($items as $item) {
            if ((string) ($item['id'] ?? '') === $identifier) {
                return $item;
            }
        }

        foreach ($items as $item) {
            if ((string) ($item['name'] ?? '') === $identifier) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Applications deployed from the given repository (pure).
     *
     * @param  array<int, array<string, mixed>>  $applications
     * @return array<int, array<string, mixed>>
     */
    public static function matchingRepository(array $applications, ?string $fullName): array
    {
        if ($fullName === null || $fullName === '') {
            return [];
        }

        return array_values(array_filter(
            $applications,
            fn (array $application) => strcasecmp((string) ($application['repositoryFullName'] ?? ''), $fullName) === 0,
        ));
    }

    /**
     * Application from the argument, then `.cloud/config.json`, then the git
     * remote, then a sole candidate, and only then a prompt.
     *
     * @param  array<int, array<string, mixed>>  $applications
     * @return array<string, mixed>
     */
    protected function resolveApplication(array $applications, ?string $identifier): array
    {
        $identifier ??= $this->localConfig?->applicationId();

        if ($identifier !== null && $identifier !== '') {
            $application = self::findByIdentifier($applications, $identifier);

            if ($application === null) {
                throw new RuntimeException(
                    "Unable to resolve application \"{$identifier}\". Run `cloud application:list` to see what is available."
                );
            }

            return $this->resolved('Application', $application);
        }

        $fromRepository = self::matchingRepository($applications, $this->repository?->fullName());

        if (count($fromRepository) === 1) {
            return $this->resolved('Application', $fromRepository[0]);
        }

        $candidates = $fromRepository !== [] ? $fromRepository : $applications;

        if (count($candidates) === 1) {
            return $this->resolved('Application', $candidates[0]);
        }

        return $this->choose('Application', $candidates);
    }

    /**
     * Environment from the argument, then `.cloud/config.json`, then a sole
     * candidate, and only then a prompt defaulting to the application's
     * default environment.
     *
     * @param  array<int, array<string, mixed>>  $environments
     * @return array<string, mixed>
     */
    protected function resolveEnvironment(array $environments, ?string $identifier, string $defaultEnvironmentId): array
    {
        $identifier ??= $this->localConfig?->environmentId();

        if ($identifier !== null && $identifier !== '') {
            $environment = self::findByIdentifier($environments, $identifier);

            if ($environment === null) {
                throw new RuntimeException(
                    "Unable to resolve environment \"{$identifier}\". Run `cloud environment:list` to see what is available."
                );
            }

            return $this->resolved('Environment', $environment);
        }

        if (count($environments) === 1) {
            return $this->resolved('Environment', $environments[0]);
        }

        return $this->choose('Environment', $environments, $defaultEnvironmentId);
    }

    /**
     * Which environment to preselect.
     *
     * `application:list` reports defaultEnvironmentId as null — it does not ask
     * the API to include that relationship — so it cannot be used. Prefer the
     * project's pinned environment, then one plainly named for production.
     *
     * @param  array<int, array<string, mixed>>  $environments
     */
    protected function defaultEnvironmentId(array $environments): string
    {
        $pinned = $this->localConfig?->environmentId();

        if ($pinned !== null && self::findByIdentifier($environments, $pinned) !== null) {
            return $pinned;
        }

        foreach ($environments as $environment) {
            if (strcasecmp((string) ($environment['name'] ?? ''), 'production') === 0) {
                return (string) $environment['id'];
            }
        }

        return '';
    }

    /**
     * Environments for an application.
     *
     * The ones `application:list` embeds look complete but are not: the API
     * returns a relationship only when a request asks to include it, and that
     * command asks for neither `database` nor `defaultEnvironment`. So the
     * embedded rows carry a null databaseSchemaId, which is the one field the
     * whole cluster lookup turns on. Asking for the listing directly costs one
     * call and saves the environment:get that guessing otherwise forces.
     *
     * @param  array<string, mixed>  $application
     * @return array<int, array<string, mixed>>
     */
    protected function environmentsFor(array $application): array
    {
        return $this->call(
            fn (CloudCli $cloud) => $cloud->environments((string) $application['id']),
            'Fetching environments...',
        );
    }

    /**
     * Run a CLI call, resolving the organization once if the CLI reports that
     * it cannot pick between several saved API tokens.
     *
     * @template TReturn
     *
     * @param  callable(CloudCli): TReturn  $call
     * @return TReturn
     */
    protected function call(callable $call, string $message = 'Talking to Laravel Cloud...'): mixed
    {
        try {
            return spin(fn () => $call($this->cloud), $message);
        } catch (CloudCliException $e) {
            if ($this->organizationResolved || ! $e->requiresOrganization()) {
                throw $e;
            }

            // Prompting happens outside the spinner, or the two fight over the line.
            ['organization' => $organization, 'token' => $token] = $this->organizations->resolve($this->organization);

            $this->cloud = $this->cloud->withApiToken($token);
            $this->organization = $organization;
            $this->organizationResolved = true;

            note("Organization: {$organization}");

            return spin(fn () => $call($this->cloud), $message);
        }
    }

    /**
     * Pick a cluster when the environment could not be matched to one, taking
     * a sole candidate without asking.
     *
     * @param  array<int, array<string, mixed>>  $clusters
     * @return array<string, mixed>
     */
    protected function chooseCluster(array $clusters): array
    {
        if (count($clusters) === 1) {
            return $this->resolved('Database cluster', $clusters[0]);
        }

        return $this->choose('Database cluster', $clusters);
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

        // A cluster listing does not always carry its databases, so ask for
        // them directly rather than concluding the cluster is empty.
        if (! is_array($schemas) || $schemas === []) {
            $schemas = $this->call(
                fn (CloudCli $cloud) => $cloud->databases((string) $cluster['id']),
                'Fetching databases...',
            );
        }

        if ($schemas === []) {
            throw new RuntimeException("Cluster \"{$cluster['name']}\" has no databases.");
        }

        if (count($schemas) === 1) {
            return $this->resolved('Database', $schemas[0]);
        }

        return $this->choose('Database', array_values($schemas), $environmentSchemaId);
    }

    /**
     * Prompt to choose an item from a list keyed by id, labelled by name.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    protected function choose(string $label, array $items, string $default = ''): array
    {
        $options = [];
        foreach ($items as $item) {
            $options[(string) $item['id']] = (string) $item['name'];
        }

        $selectedId = select(
            label: $label,
            options: $options,
            default: $default !== '' && isset($options[$default]) ? $default : null,
        );

        $selected = self::findByIdentifier($items, (string) $selectedId);

        if ($selected === null) {
            throw new RuntimeException("Selected {$label} could not be resolved.");
        }

        return $selected;
    }

    /**
     * Echo a resolution the user was not asked about, so the run still shows
     * what it picked.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function resolved(string $label, array $item): array
    {
        note("{$label}: ".(string) $item['name']);

        return $item;
    }
}
