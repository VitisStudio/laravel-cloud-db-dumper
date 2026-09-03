# Security Policy

## Supported Versions

Security fixes are applied to the latest released minor version and to `main`.
Older versions are not patched retroactively.

| Version | Supported          |
| ------- | ------------------ |
| latest  | :white_check_mark: |
| older   | :x:                |

## Reporting a Vulnerability

**Do not open a public issue for security problems.**

Report vulnerabilities privately through either channel:

- GitHub Security Advisories — [report a vulnerability](https://github.com/VitisStudio/laravel-cloud-db-dumper/security/advisories/new) (preferred)
- Email — dan@vitis.studio

Please include:

- affected version and PHP / Laravel versions
- reproduction steps or a proof of concept
- the impact you believe the issue has

## Response Targets

| Stage                              | Target                 |
| ---------------------------------- | ---------------------- |
| Acknowledgement of your report     | within 3 business days |
| Initial assessment and severity    | within 7 days          |
| Fix released, or mitigation stated | within 30 days         |

We will keep you informed while the fix is prepared and will credit you in the
advisory unless you ask otherwise. Please give us the 30 day window before any
public disclosure.

## Scope

This package shells out to the Laravel Cloud CLI and to database dump and
restore binaries, and it handles live database credentials in memory.
Credentials are never written to the preferences file. Reports about credential
leakage, command injection through configured binary paths, or dumps written
outside the configured backup directory are firmly in scope.

Vulnerabilities in Laravel Cloud itself belong to
[Laravel](https://laravel.com/security), not to this package.
