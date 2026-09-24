<?php

namespace VitisStudio\LaravelCloudDbDumper\Cloud;

use RuntimeException;

/**
 * A failed `cloud` invocation. The CLI reports failures as
 * `{"error": true, "message": "..."}` on STDERR when `--json` is passed, so the
 * message here is the CLI's own wording where one could be parsed.
 */
class CloudCliException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $invocation = '',
        public readonly string $rawOutput = '',
    ) {
        parent::__construct($message);
    }

    /**
     * The CLI found several saved API tokens and cannot pick an organization
     * on its own — it never prompts when STDIN is not a TTY, which is always
     * the case when we shell out.
     */
    public function requiresOrganization(): bool
    {
        return str_contains($this->getMessage(), 'Multiple API tokens found')
            || str_contains($this->getMessage(), 'Unable to resolve organization');
    }

    /**
     * Whether the CLI rejected our credentials.
     *
     * A rejected token surfaces as the API's own wording, not the CLI's: the
     * request never reaches the CLI's error handling. The two phrases the CLI
     * does emit for this are printed through channels that `--json -n`
     * suppresses, so matching them alone matched nothing.
     */
    public function requiresAuthentication(): bool
    {
        $message = $this->getMessage();

        return str_contains($message, 'Not authenticated')
            || stripos($message, 'Invalid API token') !== false
            || preg_match('/\bUnauthorized\b|\b401\b/i', $message) === 1;
    }
}
