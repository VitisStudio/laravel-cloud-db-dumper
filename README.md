# Laravel Cloud DB Dumper

[![Latest Version on Packagist](https://img.shields.io/packagist/v/vitisstudio/laravel-cloud-db-dumper.svg?style=flat-square)](https://packagist.org/packages/vitisstudio/laravel-cloud-db-dumper)
[![Tests](https://github.com/vitisstudio/laravel-cloud-db-dumper/actions/workflows/run-tests.yml/badge.svg?branch=main)](https://github.com/vitisstudio/laravel-cloud-db-dumper/actions/workflows/run-tests.yml)
[![Code Style](https://github.com/vitisstudio/laravel-cloud-db-dumper/actions/workflows/fix-php-code-style-issues.yml/badge.svg?branch=main)](https://github.com/vitisstudio/laravel-cloud-db-dumper/actions/workflows/fix-php-code-style-issues.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/vitisstudio/laravel-cloud-db-dumper.svg?style=flat-square)](https://packagist.org/packages/vitisstudio/laravel-cloud-db-dumper)

Pull a [Laravel Cloud](https://cloud.laravel.com) database down to your machine with one command, or
copy it into another Cloud environment.

`php artisan db:pull` resolves your Cloud target — organization, application, environment, database —
fetches the connection credentials for it, dumps it to a file, and optionally restores it into your local
database and runs a seeder to scrub what you just pulled down. With `--to` it restores into another
environment's Cloud database instead, which is how you fill a new staging environment from production.
It only asks about the parts it cannot work out for itself.

Dumps are kept, so a later run can restore an earlier snapshot without downloading anything, and
`--prune` clears them out again. Where policy forbids production data at rest, `--no-store` keeps the
dump out of your project entirely.

```bash
php artisan db:pull
```

```
  db:pull

 ┌ Pull ─────────────────────────────────────────────────────────────────┐
 │ From  acme-web › production (acme-db / main)                          │
 │ Into  your local "acme" database                                      │
 └───────────────────────────────────────────────────────────────────────┘

 ✔ Dumping production
   ✔ Dumped 12.4 MB to database/backups/main_pgsql_2026-10-13.sql

 ✔ Restoring into your local "acme" database
   ✔ Closed 1 open connection
   ✔ Emptied the database
   ✔ Loaded 48,210 rows across 31 tables

  Restored production into your local "acme" database.
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
| Laravel Cloud CLI      | `>= 0.5.3`, authenticated                         |
| Database client tools  | `pg_dump` + `psql`, or `mysqldump` + `mysql`      |

The Cloud CLI does the authentication, so this package never asks you for a token:

```bash
composer global require laravel/cloud-cli
cloud auth
```

`db:pull` checks the CLI version before it does anything else and stops if it is older than 0.5.3.
Those versions ask the Laravel Cloud API for an include it no longer allows, so every database lookup
fails with a bare `400 Bad Request` that says nothing about versions. The check runs up front rather
than letting you pick your way down to a database first, and it only blocks on a version it could
actually read — a wrapper script or custom build is left alone.

## Usage

```bash
php artisan db:pull
```

The command walks you through it:

1. **Pick a target.** Organization, application, environment, then database. Anything that can be
   worked out is not asked about — see [How the target is resolved](#how-the-target-is-resolved).
2. **Choose where dumps land.** Defaults to `database/backups`, and is skipped when dump storage is
   off.
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
| `--organization=`   | Run against a named Cloud organization, by name or slug ([details](#multiple-cloud-organizations)) |
| `--fresh`           | Ignore saved preferences and pick the database again              |
| `--download`        | Always fetch a fresh dump, ignoring the ones already on disk ([details](#restoring-an-earlier-dump)) |
| `--clients`         | Re-choose the dump and restore binaries ([details](#database-client-versions)) |
| `--no-store`        | Never leave the dump on disk ([details](#keeping-nothing-on-disk)) |
| `--no-restore`      | Dump only; leave the local database untouched                     |
| `--no-seed`         | Skip the post-restore seeder step                                 |
| `--to=`             | Restore into this environment's Cloud database instead of your local one ([details](#pushing-into-another-environment)) |
| `--skip-data[=]`    | Create these tables but load none of their rows; with no value, the configured cache, session and queue tables ([details](#restoring-tables-empty)) |
| `--prune`           | Delete the stored dumps and exit ([details](#deleting-stored-dumps)) |
| `--force`           | Skip the prune and push confirmations, for scripts                |

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
run without a single prompt. Everything it resolved is shown on one card before anything is read or
written, so a quiet run still tells you what it picked.

A cluster restored from a snapshot reports its source's id rather than one of its own, so asking Laravel
Cloud for that id can hand back the source — production, typically. `db:pull` checks that the cluster it
gets back carries the name it asked for, and stops if it does not, rather than dumping or overwriting the
wrong database.

The environment is deliberately *not* inferred from your current git branch, unlike `cloud deploy`.
This command overwrites your local database, so which environment it reads from stays an explicit
choice.

## Pushing into another environment

`--to` copies one environment's database into another environment of the same application, on Laravel
Cloud, instead of into your local database:

```bash
php artisan db:pull acme-web production --to=staging --download --skip-data --no-seed
```

```
 ┌ Push ─────────────────────────────────────────────────────────────────────────┐
 │ From          acme-web › production (acme-db / main)                          │
 │ Into          acme-web › staging (acme-staging / main) on Laravel Cloud       │
 │ Empty tables  cache, cache_locks, sessions, jobs, job_batches, failed_jobs, … │
 └───────────────────────────────────────────────────────────────────────────────┘

 ┌ This replaces a Laravel Cloud database ───────────────────────────────────────┐
 │ Everything in staging (acme-staging / main) is dropped and replaced with      │
 │ production. There is no undo.                                                 │
 └───────────────────────────────────────────────────────────────────────────────┘

 ┌ Type "staging" to push ───────────────────────────────────────────────────────┐
 │ staging                                                                       │
 └───────────────────────────────────────────────────────────────────────────────┘
```

The destination's database is emptied — its `public` schema dropped and recreated — and the dump is
loaded in one transaction, so a restore that fails part way leaves the database empty rather than half
loaded. Pressing Enter is never enough: you type the destination's name. Without a terminal, `--force`
stands in for that, and nothing else does.

**Ownership never travels.** Dumps are taken with `--no-owner --no-privileges`, and the restore strips any
`OWNER TO`, `GRANT`, `REVOKE` or `ALTER DEFAULT PRIVILEGES` an older dump still carries. Every object lands
owned by the destination's own user, instead of failing on a role that only exists on the source.

A push is refused before production is read when:

- the destination is named `prod` or `production`
- the destination is the source environment
- either side is not Postgres — pushing is Postgres only

and refused after the credentials are fetched when the destination resolves to the same host and
database as the source, which is what two environments sharing one database, or a restored cluster
resolving to its source, looks like.

After a push, run the destination's migrations if its branch is ahead of the source's, and clear anything
your app keeps in Redis or another store outside the database. A push does not change the saved target
that a bare `db:pull` offers next time.

### Restoring tables empty

Some tables hold state that belongs to the environment it came from: queued jobs the other environment
would run, sessions and password reset tokens issued there, cache entries and monitoring history.
`--skip-data` creates those tables, with their indexes and sequences, and loads none of their rows:

```bash
php artisan db:pull --skip-data                      # the configured list
php artisan db:pull --skip-data=audits,notifications # only these
php artisan db:pull --skip-data --skip-data=audits   # the configured list, plus audits
```

The configured list is `skip_data_tables`: `cache`, `cache_locks`, `sessions`, `jobs`, `job_batches`,
`failed_jobs`, `password_reset_tokens`, and Telescope's and Pulse's tables. A bare name matches that
table in any schema; `schema.table` matches only there.

The rows are left out at restore time, not at dump time. A day's dump is reused by name, so a dump taken
without those rows would later be restored, still without them, by a pull that wanted them. The cost is
that those rows are still downloaded; they are just never loaded. It works with dumps already on disk.

## Multiple Cloud organizations

Cloud CLI 0.5 holds one API token per organization, and it only prompts you to choose between them when
it is attached to a terminal — which it never is when a package shells out to it. Left alone, it fails
with `Multiple API tokens found`.

This package resolves the organization itself: it asks you once, then forwards the matching token for
the rest of the run. The organization name is remembered with your other preferences. Only the name —
the token is never written to disk.

Naming an organization is treated as an assertion: if it matches nothing, the command stops and lists
what is available, rather than quietly running against a different one. Match is by name or slug — the
CLI's token listing reports no organization id, so an id cannot be matched.

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
| `skip_data_tables` | —                | cache, session, queue, Telescope and Pulse tables | What `--skip-data` with no value leaves empty |
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

### Database client versions

`pg_dump` refuses to read a server newer than itself, and a dump taken from a newer server may not load
into an older one. Most machines have several versions installed with only one of them on the `PATH`,
which is usually the wrong one.

So on the first run `db:pull` asks your local database what version it is, finds every `pg_dump` and
`psql` (or `mysqldump` and `mysql`) on the machine — the `PATH`, DBngin, Postgres.app, Homebrew — and
picks the one that fits:

```
 ┌ Which pg_dump should be used? It reads the Cloud database. Your local database is 18.1. ┐
 │ › pg_dump 18.1  —  /Users/Shared/DBngin/postgresql/18.1/bin/pg_dump  (matches your local database)
 │   pg_dump 17.4  —  /opt/homebrew/opt/libpq/bin/pg_dump
 │   pg_dump 16.2  —  /Users/Shared/DBngin/postgresql/16.2/bin/pg_dump
 └──────────────────────────────────────────────────────────────────────────────────────────┘
```

The choice is remembered in the preferences file with the local server version it was made for. Later
runs say nothing — until something moves:

- your local database is now a different version than when you chose
- the binary you chose has been uninstalled

Then it says what changed and asks again. `--clients` re-opens the question at any time, and setting
`PG_DUMP_PATH`/`PSQL_PATH` (or the MySQL equivalents) in config skips it entirely — an explicit path is
taken as a decision already made.

A local database that is not running is not an error: the version simply stays unknown and the newest
client is preferred.

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

## The terminal screen

Run from a terminal, `db:pull` clears the screen and draws its run with [Laravel Prompts](https://laravel.com/docs/prompts):
a card saying where the data comes from and where it goes, then each step with a live log under it —
the restore names every table as it loads, with its row count — and a desktop notification when a long
run finishes. Questions are Prompts questions, so the arrow keys, `y`/`n` and Enter work as they do in
any Laravel command.

Run without a terminal — piped, in CI, or by an agent — it prints plain lines instead and never clears
anything.

Tools that rewrite Artisan output for AI agents, such as `laravel/pao`, decide from environment
variables, and an editor can pass those into the terminal you type in. They strip colour, which leaves a
yes/no question showing only the marker for the answer that is *not* selected. So when `db:pull` has both
a terminal and permission to ask questions, it writes straight to the terminal, in colour, whatever
those variables say.

## Using it from an AI agent

The package ships a [Laravel Boost](https://github.com/laravel/boost) skill,
`laravel-cloud-db-dumper`, that tells an agent how to run `db:pull` safely without a terminal. Install
or refresh it with:

```bash
php artisan boost:install   # pick the package under third-party skills
```

Without a terminal there is no one to answer, so every question takes its default. That matters:

| Question | Default without a terminal |
| --- | --- |
| Use the saved target? | **Yes** — name the application and environment instead |
| Use a dump already on disk? | Today's, if there is one — pass `--download` |
| Overwrite the local database? | **Yes** — pass `--no-restore` to only dump |
| Run a seeder? | The one used last time — pass `--no-seed` |
| Push into another environment? | Refused unless `--force` is passed |

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
