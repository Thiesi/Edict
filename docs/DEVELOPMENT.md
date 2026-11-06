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

Then, with the tunnel running, create the schema and your admin account:

```sh
php bin/edict migrate
php bin/edict admin:create <username>   # prompts for a password, then shows a QR code for your authenticator app
```

Open the site as `http://localhost:8080`, not `127.0.0.1`: `APP_URL` must match for the CSRF origin check, and passkeys and OAuth need the name.

## Daily use

```sh
bin/tunnel            # terminal 1: 127.0.0.1:55432 -> Roanoke PostgreSQL (Ctrl+C closes)
composer serve        # terminal 2: http://127.0.0.1:8080
composer check        # code style + PHPStan (level 9) + PHPUnit, as CI runs them
composer cs-fix       # apply code style
```

Tests in the `db` group need `TEST_DATABASE_URL` (and so the tunnel). Without it they are skipped, not failed. They wipe that database, so they refuse any database whose name doesn't end in `_test`. `vendor/bin/phpunit --exclude-group db` runs everything else offline.

Database facts (from Ops, 2026-11-05):

- Role `edict_dev` owns `edict_dev` and `edict_test`, with no superuser or createdb rights.
- The cluster collation is `C` (byte order). Use explicit ICU collations where locale-aware sorting matters.
- Both DBs are included in Roanoke's nightly `pg_dumpall`.

## The editor

The editor bundle lives in `frontend/editor/`. Vite builds it into `public/assets/build/`, which is gitignored and shipped with each release. `node_modules` never reaches the host.

```sh
npm ci
npm run build         # needed once before the editor page works locally
npm run test:e2e      # browser tests; see the "browser" job in .github/workflows/ci.yml for the setup
```

Adding a block type means changing both the editor (`frontend/editor/`) and the allow-lists in `src/Content/Body.php`. Anything `Body` doesn't know is dropped on save.

## Media locally

Set `DATA_DIR` in `.env` to a folder outside the repo, and install libvips (`vipsthumbnail`, `vipsheader`). Run `php bin/edict worker` so uploads get processed. Without `DATA_DIR`, uploads fail with a message.

## Admin commands

| Command | Purpose |
|---|---|
| `php bin/edict migrate` | Apply pending migrations from `migrations/` |
| `php bin/edict worker` | Run background jobs for 55 seconds, then exit. Cron starts it every minute when deployed |
| `php bin/edict cache:warmup` | Compile all templates; the deploy script runs this |
| `php bin/edict admin:create <username>` | Create the single admin account |
| `php bin/edict admin:reset` | New password and authenticator secret; removes all passkeys, ends all admin sessions and lifts the username throttle. The recovery path if you are locked out |
| `php bin/edict admin:revoke-sessions` | End all admin sessions, e.g. after losing a device |
| `php bin/edict admin:allow <google\|twitch> <id>` | Let that account sign in as admin |
| `php bin/edict admin:disallow <google\|twitch> <id>` | Remove it again; ends all admin sessions |
| `php bin/edict admin:identities` | List the allow-listed accounts |

To allow-list your own account: sign in with it on the site, copy its ID from `/account`, then run `admin:allow`. The ID moves from that new reader account to the admin account, and the reader account is deleted if it has no other login. Sign in again afterwards to get an admin session.

`EDICT_ADMIN_PASSWORD` supplies the password for non-interactive runs. Changing `APP_SECRET` makes stored authenticator secrets unreadable, so run `admin:reset` afterwards.

## Deploying

See `docs/DEPLOY.md`. `tests/deploy/` holds the deploy tests. They need real symlinks and a database, so they run in CI, in the `release` job.

## Disk hygiene

Read `CLAUDE.md`, "Disk hygiene". In short:

- Tool caches go to fixed paths: `.phpunit.cache/`, `.scratch/phpstan`, `.scratch/php-cs-fixer.cache`.
- Tests use the system temp dir and clean up after themselves.
- The test bootstrap refuses to run when scratch exceeds 50,000 files.
- `python scripts/clean_scratch.py` cleans up.

## CI

`.github/workflows/ci.yml` runs on every PR and on pushes to `main`: composer validate, code style, PHPStan and PHPUnit on PHP 8.3, with a PostgreSQL 16 service for the `db` tests. The Claude Code review workflow comments on PRs separately.
