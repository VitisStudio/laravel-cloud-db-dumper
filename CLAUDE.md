# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`vitisstudio/laravel-cloud-db-dumper` — a Laravel package (not an app) scaffolded
from the [spatie/package-skeleton-laravel](https://github.com/spatie/package-skeleton-laravel)
template. **Status: bootstrap skeleton.** All non-scaffolding code is still
placeholder — the intended feature (dumping a database on Laravel Cloud) is not
yet implemented.

- Namespace: `VitisStudio\LaravelCloudDbDumper\` → `src/`
- PHP `^8.3`, Laravel `^11 || ^12 || ^13` (via `illuminate/contracts`)
- Built on `spatie/laravel-package-tools` (`PackageServiceProvider`)

## Layout

- [src/LaravelCloudDbDumperServiceProvider.php](src/LaravelCloudDbDumperServiceProvider.php) — registers config, views, migration, command via `configurePackage()`
- [src/LaravelCloudDbDumper.php](src/LaravelCloudDbDumper.php) — main class, **empty body** (placeholder)
- [src/Commands/LaravelCloudDbDumperCommand.php](src/Commands/LaravelCloudDbDumperCommand.php) — artisan command `laravel-cloud-db-dumper`, placeholder (`handle()` just prints "All done")
- [src/Facades/LaravelCloudDbDumper.php](src/Facades/LaravelCloudDbDumper.php) — facade fronting the main class
- [config/cloud-db-dumper.php](config/cloud-db-dumper.php) — config, **empty array**
- [database/migrations/create_cloud_db_dumper_table.php.stub](database/migrations/create_cloud_db_dumper_table.php.stub) — migration stub
- `tests/` — Pest 4 suite (`ExampleTest`, `ArchTest`), Testbench `TestCase`

## Commands

```bash
composer test           # run Pest suite (vendor/bin/pest)
composer test-coverage  # Pest with coverage
composer analyse        # PHPStan / Larastan, level 5 (src, config, database)
composer format         # Laravel Pint formatter
```

`composer install` triggers `prepare` → `testbench package:discover`.

## Conventions

- Tests: **Pest 4** (not raw PHPUnit). Suite bootstraps through `Orchestra\Testbench`. Pest root config in [tests/Pest.php](tests/Pest.php), base case in [tests/TestCase.php](tests/TestCase.php).
- `ArchTest` forbids `dd`, `dump`, `ray` in shipped code — do not leave debug calls.
- Run `composer format` (Pint) before committing; PHPStan must stay green at level 5.
- Package autoloads via the `extra.laravel` block in `composer.json` (provider + `LaravelCloudDbDumper` alias) — no manual registration needed.

## Known skeleton issues (fix when implementing)

- **Migration name mismatch**: provider registers `hasMigration('create_laravel_cloud_db_dumper_table')` but the stub is [create_cloud_db_dumper_table.php.stub](database/migrations/create_cloud_db_dumper_table.php.stub). Names must match for publishing to work.
- README is still the template default (placeholder description, wrong Spatie badge URLs).
- `LaravelCloudDbDumper` class, config, and command are all stubs — replace with real dump logic.
- `phpstan-baseline.neon` is empty (no suppressed errors yet).

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

</laravel-boost-guidelines>
