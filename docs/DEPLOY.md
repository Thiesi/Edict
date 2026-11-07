# Deploying Edict

Host changes go through **Ops: Roanoke** (see `CLAUDE.md`). This is the runbook and the spec for the host setup. Staging comes first; production follows the same pattern in S11.

## How a deploy works

CI builds a release bundle on every push and pull request: a tarball with production dependencies only, plus `edict-deploy`. On the host, `edict-deploy`:

1. Refuses a tarball that is too large, unreadable, contains unsafe paths or isn't an Edict release.
2. Unpacks it into `releases/<UTC timestamp>-<sha12>`, writes `RELEASE` and links `shared/.env`.
3. Runs `bin/edict cache:warmup`. This compiles all templates and also proves the release boots.
4. Runs `bin/edict migrate`.
5. Flips `current` atomically.
6. Polls `/healthz` until it reports this release as healthy. On timeout it flips back and deletes the new release.
7. Keeps the newest `KEEP_RELEASES` releases and the live one.

Nothing visible changes before step 5.

**Rollback restores code, not the database.** Migrations stay applied, so a migration must keep working with the previous release. Split a breaking schema change over two deploys.

Until the first deploy creates `current`, Apache's configtest warns that the DocumentRoot doesn't exist. That's expected.

## Deploying and rolling back

**Staging:** every push to `main` that passes CI deploys through the `deploy-staging` job, so merging a PR is the go. There is no other route, because the key exists only in CI.

**Rollback:** Actions → "Staging rollback" → Run workflow, from `main`. Leave the release empty for the previous one. Or:

```sh
gh workflow run staging-rollback.yml --repo Thiesi/Edict
gh workflow run staging-rollback.yml --repo Thiesi/Edict -f release=<release>
```

The log of that run lists the releases afterwards. The next push to `main` deploys forward again.

**The key:** `EDICT_STAGING_DEPLOY_KEY` is a secret of the GitHub environment `staging`, whose deployments are restricted to `main`. Pull requests never reach it. The workflows pin Roanoke's host key instead of scanning for it. If the host key ever changes, update both workflows.

**Which build is this?** Every page on staging ends with a small link to the commit it was built from. Production doesn't show it, but its `/healthz` names the release.

**Production** isn't set up yet. It comes with S11, and each deploy will need an approval in GitHub.

## Host setup for staging

Facts below were confirmed by Ops on 2026-11-06.

| Item | Value |
|---|---|
| Site | `Staging.Edict.NetWorkXXIII.de` (DNS and certificate are live) |
| Vhost | `Use VHost Staging.Edict.NetWorkXXIII.de 80` and `Use TLSVHost Staging.Edict.NetWorkXXIII.de 443`, with `sites-local/hosts/<Name>/` modelled on ChitChat |
| Docroot | `/usr/local/share/edict-staging/current/public` (needs `FollowSymLinks`) |
| Routing | Existing files are served directly; everything else goes to `/index.php` through the pool. `/healthz` must reach PHP |
| Staging only | `X-Robots-Tag: noindex` |
| Pool user | `edictstg`, nologin, with its own pool and socket |
| Deploy user | `edictstg-deploy`, `/bin/sh`, home = `/usr/local/share/edict-staging`, member of group `edictstg`. Owns the code, so a compromised worker can't rewrite it |
| Deploy key | `command="/usr/local/sbin/edict-deploy staging ssh",no-pty,no-port-forwarding,no-agent-forwarding,no-X11-forwarding` in `/usr/pkg/etc/ssh/authorized_keys/edictstg-deploy`. That path applies only through sshd's `Match User` block, so `edictstg-deploy` must be added to it, followed by an sshd reload |
| Script | `deploy/edict-deploy` installed root-owned as `/usr/local/sbin/edict-deploy`. It changes only through Ops |
| PHP extensions | `mbstring`, `openssl`, `pdo`, `pdo_pgsql` and `iconv`, for both the CLI and the pool |
| Settings | `/usr/local/etc/edict-deploy.staging.conf`, root-owned |
| Database | Role `edictstg` owning database `edictstg`, reachable from the pool and the deploy user. It runs its own migrations |

`/usr/local/etc/edict-deploy.staging.conf`:

```sh
BASE=/usr/local/share/edict-staging
HEALTH_URL=https://staging.edict.networkxxiii.de/healthz
PHP=/usr/pkg/bin/php
export OPENSSL_CONF=/etc/openssl/openssl.cnf
```

Optional settings with their defaults: `HEALTH_TIMEOUT=60` (seconds), `KEEP_RELEASES=5`, `MAX_BYTES=268435456`, `MAX_ENTRIES=50000`. The CLI and the pool must run the same PHP minor version, or `/healthz` reports `"templates":"unavailable"` and the deploy flips back.

Directories:

| Path | Owner:group | Mode | Notes |
|---|---|---|---|
| `/usr/local/share/edict-staging` | `edictstg-deploy:edictstg` | 0755 | The lock, upload and `current` link live here. Apache must be able to traverse it |
| `.../releases` | `edictstg-deploy:edictstg` | 0755 | The script's umask is 022: code is world-readable, so Apache can serve static files with no group changes |
| `.../shared` | `root:edictstg` | 0750 | Apache has no access |
| `.../shared/.env` | `root:edictstg` | 0640 | The only secret. Read by the pool and the deploy user. Borg already covers it: its paths include `/usr`, and `/usr/local/share` is on that filesystem |

Pool settings:

```ini
clear_env = yes
env[OPENSSL_CONF] = /etc/openssl/openssl.cnf
php_admin_value[realpath_cache_ttl] = 2
```

Errors go to the vhost's error log, so the pool sets no `error_log` path, and the two new vhost logs need newsyslog entries.

Adding the pool needs one php-fpm reload, which touches every pool on the host once. Deploys need none: the short realpath TTL is what makes workers pick up a flip. **OPcache isn't loaded on Roanoke. If it is ever enabled, this needs revisiting**, because cached scripts would outlive the flip.

`shared/.env`:

```sh
APP_ENV=staging
APP_URL=https://Staging.Edict.NetWorkXXIII.de
APP_SECRET=          # generated on the host: php -r "echo bin2hex(random_bytes(32));"
DATABASE_URL=pgsql://edictstg:<password>@127.0.0.1:5432/edictstg
GOOGLE_OIDC_CLIENT_ID=
GOOGLE_OIDC_CLIENT_SECRET=
TWITCH_OIDC_CLIENT_ID=
TWITCH_OIDC_CLIENT_SECRET=
DATA_DIR=/home/edictstg/data
FFMPEG_BIN=/usr/pkg/bin/ffmpeg8   # pkgsrc installs them under versioned names
FFPROBE_BIN=/usr/pkg/bin/ffprobe8
```

Secrets are put in place directly on the host, never through chats or commits. A provider with empty values just isn't offered, so the provider values can follow later. Changing `APP_SECRET` makes stored authenticator secrets unreadable, so run `admin:reset` afterwards.

The OAuth redirect URLs to register are `https://staging.edict.networkxxiii.de/auth/{google,twitch}/callback`.

**The deploy key is a code-execution credential.** Each tarball's PHP runs as the deploy user, who can read `.env`, so holding the key means access to the staging secrets. Keep one key per instance, held only by that instance's GitHub environment.

If a machine dies mid-deploy, the lock stays and the forced-command key can't clear it. Ops removes it with `rmdir /usr/local/share/edict-staging/.deploy.lock`, after checking that no deploy is running.

## Checks only Roanoke can answer

Ops runs these before the first deploy, because CI runs on Linux:

1. `sh -c 'trap "echo BAD" EXIT; (:) | cat; x=$(:); exit 0' 2>&1` prints `BAD` exactly once. If it's more, `/bin/sh` runs EXIT traps in subshells, and the script's cleanup needs a guard.
2. `/bin/tar` lists and extracts the CI bundle with `tar -tzf` and `tar -xzf`. It's a gzipped ustar archive.
3. `sshd` lets the new deploy user log in (`AllowUsers`/`AllowGroups`), and that user gets `php` at the configured path.
4. As the deploy user, an HTTPS request to `HEALTH_URL` verifies its certificate, with the settings file's `OPENSSL_CONF`.
5. In the pool, not just the CLI: an HTTPS request to `https://oauth2.googleapis.com/token` verifies its certificate. Login needs this, and `/healthz` doesn't cover it.

## Admin account

The owner creates it once on the host after the first deploy, as a user that can read `shared/.env`:

```sh
php /usr/local/share/edict-staging/current/bin/edict admin:create <username>
```

## Done when

- `https://Staging.Edict.NetWorkXXIII.de/` shows "Hello, Edict" over HTTPS.
- `/healthz` reports `"status":"ok"`, `"database":"ok"` and the deployed release.
- A second deploy and a rollback have both been demonstrated.
- The pool can't write to `releases/`.
