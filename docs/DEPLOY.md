# Deploying Edict

Host changes go through **Ops: Roanoke** (see `CLAUDE.md`). This is the runbook and the spec for the host setup. Staging comes first; production follows the same pattern in S11.

## How a deploy works

CI builds a release bundle on every push to `main`: a tarball with production dependencies only, plus `edict-deploy`. On the host, `edict-deploy`:

1. Refuses a tarball that is too large, unreadable, contains unsafe paths or isn't an Edict release.
2. Unpacks it into `releases/<UTC timestamp>-<sha12>`, writes `RELEASE` and links `shared/.env`.
3. Runs `bin/edict cache:warmup`. This compiles all templates and also proves the release boots.
4. Runs `bin/edict migrate`.
5. Flips `current` atomically.
6. Polls `/healthz` until it reports this release as healthy. On timeout it flips back and deletes the new release.
7. Keeps the newest `KEEP_RELEASES` releases and the live one.

Nothing visible changes before step 5.

**Rollback restores code, not the database.** Migrations stay applied, so a migration must keep working with the previous release. Split a breaking schema change over two deploys.

## Deploying and rolling back

Get the bundle from the dev PC, then send the tarball through the deploy key:

```sh
gh run download --repo Thiesi/Edict --name edict-<sha> --dir <empty temp dir>
ssh edictstg-deploy@Roanoke.NetWorkXXIII.de deploy < edict-<sha>.tar.gz
ssh edictstg-deploy@Roanoke.NetWorkXXIII.de releases
ssh edictstg-deploy@Roanoke.NetWorkXXIII.de rollback             # to the previous release
ssh edictstg-deploy@Roanoke.NetWorkXXIII.de rollback <release>
```

CI doesn't deploy by itself. Giving CI a key is the owner's decision, and hasn't been made.

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
| Deploy user | `edictstg-deploy`, `/bin/sh`, member of group `edictstg`. Owns the code, so a compromised worker can't rewrite it |
| Deploy key | `command="/usr/local/sbin/edict-deploy staging ssh",no-pty,no-port-forwarding,no-agent-forwarding,no-X11-forwarding` in `/usr/pkg/etc/ssh/authorized_keys/edictstg-deploy`. The public key comes from the owner |
| Script | `deploy/edict-deploy` installed root-owned as `/usr/local/sbin/edict-deploy`. It changes only through Ops |
| Settings | `/usr/local/etc/edict-deploy.staging.conf`, root-owned |
| Database | Role `edictstg` owning database `edictstg`, reachable from the pool and the deploy user. It runs its own migrations |

`/usr/local/etc/edict-deploy.staging.conf`:

```sh
BASE=/usr/local/share/edict-staging
HEALTH_URL=https://staging.edict.networkxxiii.de/healthz
```

Optional settings with their defaults: `HEALTH_TIMEOUT=60` (seconds), `KEEP_RELEASES=5`, `MAX_BYTES=268435456`, `PHP=php`.

Directories:

| Path | Owner:group | Mode | Notes |
|---|---|---|---|
| `/usr/local/share/edict-staging` | `edictstg-deploy:edictstg` | 0750 | The lock, upload and `current` link live here |
| `.../releases` | `edictstg-deploy:edictstg` | 0750 | New files inherit the group on BSD. The script's umask is 027 |
| `.../shared` | `root:edictstg` | 0750 | |
| `.../shared/.env` | `root:edictstg` | 0640 | Read by the pool and the deploy user |

Pool settings:

```ini
clear_env = yes
env[OPENSSL_CONF] = /etc/openssl/openssl.cnf
php_admin_value[realpath_cache_ttl] = 2
```

The short realpath TTL is what makes workers pick up a flip, since a php-fpm reload would touch every pool on the host. **OPcache isn't loaded on Roanoke. If it is ever enabled, this needs revisiting**, because cached scripts would outlive the flip.

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
```

Secrets are put in place directly on the host, never through chats or commits. A provider with empty values just isn't offered, so the provider values can follow later. Changing `APP_SECRET` makes stored authenticator secrets unreadable, so run `admin:reset` afterwards.

The OAuth redirect URLs to register are `https://staging.edict.networkxxiii.de/auth/{google,twitch}/callback`.

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
