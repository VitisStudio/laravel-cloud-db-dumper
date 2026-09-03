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

    public function requiresAuthentication(): bool
    {
        return str_contains($this->getMessage(), 'Not authenticated')
            || str_contains($this->getMessage(), 'no longer valid')
            || str_contains($this->getMessage(), 'was rejected');
    }
}
