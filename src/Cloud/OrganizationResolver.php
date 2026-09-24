<?php

namespace VitisStudio\LaravelCloudDbDumper\Cloud;

use RuntimeException;

use function Laravel\Prompts\select;

/**
 * Picks which saved Laravel Cloud API token — and therefore which organization
 * — a command runs as.
 *
 * Since CLI 0.5 a user can have one token per organization. The CLI only
 * prompts for the organization when STDIN is a TTY, which it never is when we
 * shell out, so it aborts with "Multiple API tokens found" instead. We resolve
 * the choice here and forward the token through LARAVEL_CLOUD_TOKEN.
 */
class OrganizationResolver
{
    public function __construct(
        protected readonly CloudCli $cloud,
    ) {
        //
    }

    /**
     * Resolve the organization to run as, prompting only when the preferred
     * one is unknown or unavailable.
     *
     * @return array{organization: string, token: string}
     */
    public function resolve(?string $preferred = null): array
    {
        $tokens = $this->cloud->tokens();

        if ($tokens === []) {
            throw new RuntimeException('No Laravel Cloud API tokens found. Run `cloud auth` to authenticate.');
        }

        if ($preferred !== null && $preferred !== '') {
            $match = self::match($tokens, $preferred);

            if ($match !== null) {
                return $match;
            }

            // Naming an organization is an assertion, the way it is to the CLI:
            // falling through to the sole token, or to a prompt, would run
            // against an organization the user did not ask for.
            $available = implode(', ', array_map(
                fn (array $token) => (string) $token['organization'],
                $tokens,
            ));

            throw new RuntimeException(
                "Unable to resolve organization [{$preferred}]. Available organizations: {$available}."
            );
        }

        if (count($tokens) === 1) {
            return $this->entry($tokens[0]);
        }

        // Keys are prefixed because PHP turns "0", "1", "2" back into integers,
        // which makes this a list — and Laravel Prompts hands back the value
        // rather than the key for a list, so every choice looked like the first.
        $options = [];
        foreach ($tokens as $index => $token) {
            $options['token-'.$index] = (string) $token['organization'];
        }

        $selected = (string) select(label: 'Organization', options: $options, scroll: 10);

        $index = (int) substr($selected, strlen('token-'));

        if (! isset($tokens[$index])) {
            throw new RuntimeException('Selected organization could not be resolved.');
        }

        return $this->entry($tokens[$index]);
    }

    /**
     * Find the token belonging to a named organization (pure).
     *
     * @param  array<int, array<string, mixed>>  $tokens
     * @return array{organization: string, token: string}|null
     */
    public static function match(array $tokens, string $organization): ?array
    {
        foreach ($tokens as $token) {
            $name = (string) ($token['organization'] ?? '');

            // The listing carries only the display name, so a slug is matched
            // by deriving one. An organization id cannot be matched at all —
            // no command in any CLI version reports it alongside the token.
            if (strcasecmp($name, $organization) === 0 || self::slug($name) === self::slug($organization)) {
                return [
                    'organization' => (string) $token['organization'],
                    'token' => (string) $token['token'],
                ];
            }
        }

        return null;
    }

    /**
     * Reduce an organization name to a comparable slug (pure).
     */
    protected static function slug(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($value)) ?? '', '-');
    }

    /**
     * @param  array<string, mixed>  $token
     * @return array{organization: string, token: string}
     */
    protected function entry(array $token): array
    {
        return [
            'organization' => (string) ($token['organization'] ?? ''),
            'token' => (string) ($token['token'] ?? ''),
        ];
    }
}
