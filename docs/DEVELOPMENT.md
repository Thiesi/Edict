# Developing Edict

## Prerequisites (dev PC)

- PHP 8.3 or 8.4 CLI with `pdo_pgsql`. Production runs 8.3; Composer's platform is pinned to 8.3, so dependencies always fit production.
- Composer 2 (`composer.phar` next to `php.exe`, with `composer`/`composer.bat` wrappers).
- SSH access to Roanoke (`thiesi@Roanoke.NetWorkXXIII.de`, port 22).
- There is no local PostgreSQL. Dev and test DBs live on Roanoke and are reached through an SSH tunnel.

## First-time setup

```sh
composer install
cp .env.example .env
```

Fill in `.env`:

- `EDICT_TUNNEL_SSH=thiesi@Roanoke.NetWorkXXIII.de`
- the `edict_dev` password in `DATABASE_URL` and `TEST_DATABASE_URL`. It is stored on Roanoke in `~/.edict-dev-pw` (mode 0600); fetch it yourself, e.g. `ssh thiesi@Roanoke.NetWorkXXIII.de cat .edict-dev-pw`. Never paste it into chats, issues or commits.

## Daily use

```sh
bin/tunnel            # terminal 1: 127.0.0.1:55432 -> Roanoke PostgreSQL (Ctrl+C closes)
composer serve        # terminal 2: http://127.0.0.1:8080
composer check        # code style + PHPStan (level 9) + PHPUnit, as CI runs them
composer cs-fix       # apply code style
```

Tests in the `db` group need `TEST_DATABASE_URL` (and so the tunnel). Without it they are skipped, not failed. `vendor/bin/phpunit --exclude-group db` runs everything else offline.

Database facts (from Ops, 2026-11-05):

- Role `edict_dev` owns `edict_dev` and `edict_test`, with no superuser or createdb rights.
- The cluster collation is `C` (byte order). Use explicit ICU collations where locale-aware sorting matters.
- Both DBs are included in Roanoke's nightly `pg_dumpall`.

## Disk hygiene

Read `CLAUDE.md`, "Disk hygiene". In short:

- Tool caches go to fixed paths: `.phpunit.cache/`, `.scratch/phpstan`, `.scratch/php-cs-fixer.cache`.
- Tests use the system temp dir and clean up after themselves.
- The test bootstrap refuses to run when scratch exceeds 50,000 files.
- `python scripts/clean_scratch.py` cleans up.

## CI

`.github/workflows/ci.yml` runs on every PR and on pushes to `main`: composer validate, code style, PHPStan and PHPUnit on PHP 8.3, with a PostgreSQL 16 service for the `db` tests. The Claude Code review workflow comments on PRs separately.
