# Changelog

All notable changes to `laravel-cloud-db-dumper` will be documented in this file.

## v0.1.0 - 2026-09-03

Initial release.

### Added

- `db:pull` command that walks Laravel Cloud (application, environment, database), dumps the selected schema with `spatie/db-dumper`, and optionally restores it into the local database.
- Same-day dump caching, so a repeat run can reuse the existing file instead of re-downloading.
- A gitignored preferences file that remembers the last target; identifying fields only, never credentials.
- Post-restore seeder selection for sanitizing pulled production data.
- Support for Cloud CLI 0.5 multi-organization auth: the organization is resolved locally and the token forwarded through `LARAVEL_CLOUD_TOKEN`, with a `--organization` flag and `CLOUD_ORGANIZATION` config value to pin it.

### Security

- Every GitHub Actions step is pinned to a 40-character commit SHA.
- Added `SECURITY.md` with private disclosure channels and response targets.
