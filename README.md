# Laravel Cloud DB Dumper

[![Latest Version on Packagist](https://img.shields.io/packagist/v/vitisstudio/laravel-cloud-db-dumper.svg?style=flat-square)](https://packagist.org/packages/vitisstudio/laravel-cloud-db-dumper)
[![Tests](https://github.com/vitisstudio/laravel-cloud-db-dumper/actions/workflows/run-tests.yml/badge.svg?branch=main)](https://github.com/vitisstudio/laravel-cloud-db-dumper/actions/workflows/run-tests.yml)
[![Code Style](https://github.com/vitisstudio/laravel-cloud-db-dumper/actions/workflows/fix-php-code-style-issues.yml/badge.svg?branch=main)](https://github.com/vitisstudio/laravel-cloud-db-dumper/actions/workflows/fix-php-code-style-issues.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/vitisstudio/laravel-cloud-db-dumper.svg?style=flat-square)](https://packagist.org/packages/vitisstudio/laravel-cloud-db-dumper)

Pull a [Laravel Cloud](https://cloud.laravel.com) database down to your machine with one command.

`php artisan db:pull` resolves your Cloud target — organization, application, environment, database —
fetches the connection credentials for it, dumps it to a file, and optionally restores it into your local
database and runs a seeder to scrub what you just pulled down. It only asks about the parts it cannot
work out for itself.

```bash
php artisan db:pull
```

```
 Application: acme-web

 ┌ Environment ─────────────────────────────────────────────────┐
 │ › production                                                 │
 │   staging                                                    │
 └──────────────────────────────────────────────────────────────┘

 Dump written to: database/backups/forge_pgsql_2026-09-24.sql
 Local database restored.
```

## Install it as a dev dependency

```bash
composer require --dev vitisstudio/laravel-cloud-db-dumper
```

**This package belongs in `require-dev` and nowhere else.** It exists to move production data onto a
developer workstation: it reads your Laravel Cloud API tokens, fetches live database credentials, and
overwrites your local database. None of that should be reachable from a deployed application. Installing
it into `require` ships that capability to production for no benefit.

The service provider is auto-discovered, so there is nothing to register.

Publishing the config file is optional — the defaults work:

```bash
php artisan vendor:publish --tag="cloud-db-dumper-config"
```

## Requirements

| Requirement            | Version                                           |
| ---------------------- | ------------------------------------------------- |
| PHP                    | `^8.3`                                            |
| Laravel                | 11, 12 or 13                                      |
| Laravel Cloud CLI      | `>= 0.5`, authenticated                           |
| Database client tools  | `pg_dump` + `psql`, or `mysqldump` + `mysql`      |

The Cloud CLI does the authentication, so this package never asks you for a token:

```bash
composer global require laravel/cloud-cli
cloud auth
```

## Usage

```bash
php artisan db:pull
```

The command walks you through it:

1. **Pick a target.** Organization, application, environment, then database. Anything that can be
   worked out is not asked about — see [How the target is resolved](#how-the-target-is-resolved).
2. **Choose where dumps land.** Defaults to `database/backups`.
3. **Reuse or refetch.** Every dump already on disk for that database is offered, newest first, so you
   can restore an earlier snapshot instead of downloading anything.
4. **Restore locally.** Opt in, after an explicit warning naming the local database about to be
   overwritten. Active connections to it are terminated first so the restore is not blocked.
5. **Seed.** Optionally run one of your seeders against the restored data — the place to scrub
   emails, tokens and anything else that should not sit on a laptop.

Your choices are remembered, so the next run is a single confirmation.

### Arguments and options

Name the application and environment the way you would with any `cloud` command — an ID or a name,
either one:

```bash
php artisan db:pull acme-web staging
php artisan db:pull app-9f3c env-2a71
```

| Argument / option   | Effect                                                            |
| ------------------- | ----------------------------------------------------------------- |
| `application`       | Application ID or name; skips the application prompt              |
| `environment`       | Environment ID or name; skips the environment prompt              |
| `--organization=`   | Run against a named Cloud organization (see below)                |
| `--download`        | Always fetch a fresh dump, ignoring the ones already on disk      |
| `--prune`           | Delete the locally stored dumps and exit (see below)              |
| `--force`           | Skip the prune confirmation, for scripts                          |
| `--no-store`        | Never leave the dump on disk (see below)                          |
| `--fresh`           | Ignore saved preferences and pick the database again              |
| `--no-restore`      | Dump only; leave the local database untouched                     |
| `--no-seed`         | Skip the post-restore seeder step                                 |

```bash
php artisan db:pull staging --no-seed
```

Naming an application or environment overrides the saved target, so you never have to answer
"use the saved one?" with "no" first.

### How the target is resolved

`db:pull` resolves each part the way the Cloud CLI's own commands do, and only asks when something is
genuinely ambiguous:

| Part             | Resolution order                                                                          |
| ---------------- | ----------------------------------------------------------------------------------------- |
| **Organization** | `--organization` → `CLOUD_ORGANIZATION` → saved preference → `.cloud/config.json` → prompt |
| **Application**  | argument → `.cloud/config.json` → the app deployed from your git remote → sole → prompt    |
| **Environment**  | argument → `.cloud/config.json` → sole → prompt, defaulting to the app's default env       |
| **Database**     | sole → prompt, defaulting to the one the environment is wired to                           |

If you have already run `cloud repo:config` in the project, `db:pull` inherits those defaults and can
run without a single prompt. Anything resolved for you is echoed, so a quiet run still tells you what
it picked.

The environment is deliberately *not* inferred from your current git branch, unlike `cloud deploy`.
This command overwrites your local database, so which environment it reads from stays an explicit
choice.

## Multiple Cloud organizations

Cloud CLI 0.5 holds one API token per organization, and it only prompts you to choose between them when
it is attached to a terminal — which it never is when a package shells out to it. Left alone, it fails
with `Multiple API tokens found`.

This package resolves the organization itself: it asks you once, then forwards the matching token for
the rest of the run. The organization name is remembered with your other preferences. Only the name —
the token is never written to disk.

To skip that prompt entirely, name the organization up front:

```bash
php artisan db:pull --organization="Acme Inc"
```

```dotenv
CLOUD_ORGANIZATION="Acme Inc"
```

## Configuration

| Key             | Env                 | Default                            | Purpose                                            |
| --------------- | ------------------- | ---------------------------------- | -------------------------------------------------- |
| `cloud_binary`  | `CLOUD_BINARY`      | `cloud`                            | Path to the Cloud CLI, if it is not on your `PATH` |
| `organization`  | `CLOUD_ORGANIZATION`| `null`                             | Pin the Cloud organization                         |
| `backup_path`   | —                   | `database/backups`                 | Where dumps are written                            |
| `store_dumps`   | `CLOUD_DB_DUMPER_STORE_DUMPS` | `true`                   | Whether dumps are kept on disk at all              |
| `prefs_file`    | —                   | `.db-backup-prefs.json`            | Where the last target is remembered                |
| `binaries`      | see below           | `null` (discover on `PATH`)        | Absolute paths to the database client binaries     |

Point the package at binaries that live outside your `PATH` — a DBngin install, for instance:

```dotenv
PG_DUMP_PATH="/Users/Shared/DBngin/postgresql/17.2/bin/pg_dump"
PSQL_PATH="/Users/Shared/DBngin/postgresql/17.2/bin/psql"
MYSQLDUMP_PATH=
MYSQL_PATH=
```

## What gets written to your project

| Path                      | Contents                                                  |
| ------------------------- | --------------------------------------------------------- |
| `database/backups/*.sql`  | Dumps, named `{database}_{driver}_{Y-m-d}.sql`            |
| `.db-backup-prefs.json`   | The last target you picked, plus your default seeder      |

Both belong in your `.gitignore`:

```gitignore
/database/backups
.db-backup-prefs.json
```

Database credentials are fetched live from Laravel Cloud on every run and held in memory only. They are
never written to the preferences file, the dump filename, or console output.

### Restoring an earlier dump

Dumps accumulate under `backup_path`, one per database per day, and every one of them stays restorable.
On a repeat pull you are shown what is already there:

```
 ┌ 3 local dumps of acme_production already exist. Use one? ─────┐
 │   Download a fresh dump                                       │
 │ › 2026-09-24  (12.4 MB)  — today                              │
 │   2026-09-20  (12.1 MB)                                       │
 │   2026-06-25  (9.8 MB)                                        │
 └───────────────────────────────────────────────────────────────┘
```

Picking one skips the download entirely — no Cloud credentials are fetched — and restores that file.
Today's dump is preselected, since that is what a repeat run usually wants; an older snapshot is always
a deliberate choice. Only dumps of the same database and driver are listed.

Use `--download` to skip the question and always pull afresh:

```bash
php artisan db:pull --download
```

Dumps are never deleted behind your back. Clear them out with `--prune` when you want the space back.

### Deleting stored dumps

```bash
php artisan db:pull --prune
```

```
 ┌─────────────────┬────────┬────────────┬────────┐
 │ Database        │ Driver │ Taken      │ Size   │
 ├─────────────────┼────────┼────────────┼────────┤
 │ acme_production │ pgsql  │ 2026-09-24 │ 2.0 KB │
 │ acme_production │ pgsql  │ 2026-09-20 │ 4.0 KB │
 │ acme_staging    │ mysql  │ 2026-06-25 │ 1.0 KB │
 └─────────────────┴────────┴────────────┴────────┘

 Deleting 3 dumps (7.0 KB) from /app/database/backups. This cannot be undone.

 ┌ Delete these dumps? ────────────────────────────┐
 │ Yes / No                                        │
 └─────────────────────────────────────────────────┘
```

Every file is listed with its size and the day it was taken before anything happens, and the
confirmation defaults to **no**. Pruning never contacts Laravel Cloud — it is a local file operation,
so there is no application picker to walk first.

**Only dumps this package wrote are ever deleted.** Files are matched against the
`{database}_{driver}_{date}.sql` naming scheme, so anything else living in that folder is invisible to
prune and cannot be removed by it, even by accident.

For scripts, `--force` skips the confirmation:

```bash
php artisan db:pull --prune --force
```

Without `--force`, a non-interactive run declines and deletes nothing.

### Keeping nothing on disk

A dump is production data sitting on a laptop. Where a data handling policy does not allow that, turn
storage off and the dump never lands in your project:

```dotenv
CLOUD_DB_DUMPER_STORE_DUMPS=false
```

```bash
php artisan db:pull --no-store
```

The dump is written to a private temporary directory created at mode `0700`, restored into your local
database, and deleted before the command exits — including when the restore fails. Same-day caching is
off in this mode, because there is no longer a file to reuse, so every run downloads afresh.

Setting it in config covers the whole team; `--no-store` covers a single run. `--no-store` together with
`--no-restore` is refused, since that combination would download a dump and then delete it unused.

The preferences file is a separate thing and is still written. It records the application, environment,
cluster and database names you picked, and never any credentials — delete it, or point `prefs_file`
somewhere outside the repository, if even that is more than your policy allows.

## Contributing

```bash
composer test      # Pest
composer analyse   # PHPStan / Larastan, level 5
composer format    # Pint
```

Pull requests are welcome. Please keep the test suite and PHPStan green.

## Security

Please review [our security policy](SECURITY.md) for how to report a vulnerability. Do not open a public
issue for security problems.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for what has changed recently.

## Credits

- [Dan Poblete](https://github.com/acepoblete)
- [All Contributors](../../contributors)

Built on [spatie/db-dumper](https://github.com/spatie/db-dumper) and
[spatie/laravel-package-tools](https://github.com/spatie/laravel-package-tools).

## License

The MIT License (MIT). Please see [LICENSE.md](LICENSE.md) for more information.
