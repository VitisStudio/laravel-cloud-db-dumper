# Changelog

All notable changes to `laravel-cloud-db-dumper` will be documented in this file.

## v0.3.0 - Push into another environment - 2026-10-13

`db:pull --to=<environment>` restores into another environment's Laravel Cloud database instead of your local one, which is how a new staging environment gets production's data:

```bash
php artisan db:pull acme-web production --to=staging --download --skip-data --no-seed

```
A push refuses a destination named `prod` or `production`, the source environment itself, and one that resolves to the source's database, and you type the destination's name before anything is dropped (`--force` without a terminal). `--skip-data` creates tables without their rows: cache, sessions, jobs and the like by default, or the tables you name.

**Behaviour change:** a cluster restored from a snapshot reports its source's id, so `db:pull` against a restored copy could silently read production. It now stops when Laravel Cloud returns a different cluster than the one it asked for. The screen is rebuilt on Laravel Prompts' task view, with per-table row counts during a restore. The package now requires `laravel/prompts ^0.3.24`, and ships a Laravel Boost skill telling agents how to run it without a terminal.

A restore used to be able to fail completely and still report success: `psql` exits 0 after a failed statement, and a plain `pg_dump` carries no `DROP` statements, so against a database that already held the tables every statement failed and the old data stayed. `db:pull` printed `Local database restored.` either way.

**Behaviour change:** `db:pull` now drops and recreates the `public` schema before loading a PostgreSQL dump, and the restore runs with `ON_ERROR_STOP=1 --single-transaction` so a bad dump fails and rolls back instead of half-landing. Ownership and privilege statements are stripped before `psql` reads the dump — Laravel Cloud runs on Neon, whose dumps assign ownership to roles no local machine has — and new dumps are taken with `--no-owner --no-privileges`. The restore is also confirmed once rather than twice, defaulting to Yes, with input buffered during the download drained first.

<!-- Release notes generated using configuration in .github/release.yml at main -->
### What's Changed

#### New features

* Push a database into another Cloud environment by @acepoblete in https://github.com/VitisStudio/laravel-cloud-db-dumper/pull/8

#### Bug fixes

* Restore the dump instead of reporting that it did by @acepoblete in https://github.com/VitisStudio/laravel-cloud-db-dumper/pull/7

**Full Changelog**: https://github.com/VitisStudio/laravel-cloud-db-dumper/compare/v0.2.1...v0.3.0

## v0.2.1 - Cloud CLI compatibility fixes - 2026-09-24

<!-- Release notes generated using configuration in .github/release.yml at main -->
### What's Changed

#### Bug fixes

* Make db:pull work end to end against Laravel Cloud by @acepoblete in https://github.com/VitisStudio/laravel-cloud-db-dumper/pull/6

**Full Changelog**: https://github.com/VitisStudio/laravel-cloud-db-dumper/compare/v0.2.0...v0.2.1

## v0.2.0 - Storage controls and CLI-style resolution - 2026-09-24

<!-- Release notes generated using configuration in .github/release.yml at main -->
### What's Changed

#### New features

* CLI-style target resolution for db:pull by @acepoblete in https://github.com/VitisStudio/laravel-cloud-db-dumper/pull/3
* Local dump storage: history, prune, and opt-out by @acepoblete in https://github.com/VitisStudio/laravel-cloud-db-dumper/pull/4

#### Maintenance

* Release notes config by @acepoblete in https://github.com/VitisStudio/laravel-cloud-db-dumper/pull/5

**Full Changelog**: https://github.com/VitisStudio/laravel-cloud-db-dumper/compare/v0.1.0...v0.2.0

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
