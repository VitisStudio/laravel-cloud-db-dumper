---
name: laravel-cloud-db-dumper
description: Copies a Laravel Cloud database with `php artisan db:pull`, either into the local database or into another Cloud environment with --to. Use when asked to pull, sync, refresh, clone or copy production or staging data, fill a new environment from production, restore a stored dump, or clear stored dumps.
license: MIT
metadata:
  author: vitisstudio
---

# Copying Laravel Cloud databases with db:pull

`php artisan db:pull` dumps a Laravel Cloud database and restores it into the local database, or, with
`--to`, into another environment's Cloud database. Every run overwrites a database. Treat it as
destructive, and read the plan it prints before trusting the result.

## Before running anything

- **Get explicit approval for every run.** A pull replaces the local database; a push replaces a Cloud
  database, with no undo. Say which one, from where, into where, and wait for a yes.
- **Name the application and environment.** Without them the command uses the saved target from
  `.db-backup-prefs.json`, which may not be what the user means.
- **Confirm the Cloud CLI is authenticated** with `cloud auth:token --list -n` if a lookup fails. Never ask the
  user for an API token or database password.

## Running it without a terminal

An agent has no terminal, so every question takes its default. Pass flags so that no default decides
anything:

| Question | Default without a terminal | Pass instead |
| --- | --- | --- |
| Use the saved target? | Yes | `<application> <environment>` |
| Use a dump already on disk? | Today's, if there is one | `--download` |
| Overwrite the local database? | Yes | `--no-restore` to only dump |
| Run a seeder? | The one used last time | `--no-seed`, or name it with the user |
| Push into a Cloud database? | Refused | `--force`, only after approval |

Pull the production database into the local one:

```bash
php artisan db:pull acme-web production --download --no-seed -n
```

Dump only, leaving every database untouched:

```bash
php artisan db:pull acme-web production --download --no-restore -n
```

Fill another environment from production, leaving queue, session and cache tables empty:

```bash
php artisan db:pull acme-web production --to=staging --download --skip-data --no-seed --force -n
```

Application and environment take an id or a name. Add `--organization="<name>"` when the machine holds
tokens for several Cloud organizations.

## Options that matter

- `--to=<environment>` restores into that environment's Cloud database. Postgres only. It refuses a
  destination named `prod` or `production`, the source environment itself, and any destination that
  resolves to the source's host and database. Do not try to work around a refusal.
- `--skip-data` creates the configured tables (`cache`, `cache_locks`, `sessions`, `jobs`,
  `job_batches`, `failed_jobs`, `password_reset_tokens`, Telescope and Pulse) with no rows.
  `--skip-data=a,b` names the tables instead. Use it for every push, so the destination does not run the
  source's queued jobs or accept its sessions.
- `--no-store` keeps the dump off disk entirely, for data that must not rest on the machine.
- `--prune --force` deletes every stored dump with no confirmation. Do not run it unless the user asked
  for the dumps to be deleted.

## Reading the result

Exit code `0` means it finished. On failure the reason is printed. These are the ones worth knowing:

- `Asked Laravel Cloud for cluster "…" and got "…" back` — the target is a cluster restored from a
  snapshot, which shares its source's id. Nothing was touched. Report it; do not retry by id.
- `Pushing without a prompt needs --force` — expected without a terminal. Add `--force` only once the
  user has approved this push.
- `must be owner of schema public` — the destination's user cannot reset its schema. Nothing was
  dropped. Report it to the user.
- `server version mismatch` from `pg_dump` — the client is older than the server. Re-run in a terminal
  with `--clients`, or set `PG_DUMP_PATH` and `PSQL_PATH` to newer binaries.

After a push, the destination may need `php artisan migrate` (if its branch is ahead of the source's)
and a cache clear for anything kept outside the database. Mention both to the user rather than running
them unasked.

## Never

- Print, log or paste database credentials. The command never shows them; do not fetch them yourself
  with `cloud database-cluster:get --show-sensitive` to work around a failure.
- Commit `database/backups/` or `.db-backup-prefs.json`.
- Run a seeder against pushed production data unless the user named it; general seeders write on top of
  real records.
