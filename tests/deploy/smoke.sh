#!/bin/sh
# Runs the built release the way staging does: production dependencies only,
# APP_ENV=staging, real HTTP and a real database. This covers what the PHPUnit
# suite can't: real cookie parsing, __Host- cookies, the Twig cache, a missing
# dev dependency. Usage: tests/deploy/smoke.sh <tarball> <database url>
set -eu

tarball=$(realpath "${1:?tarball}")
database=${2:?database url}
port=8098

work=$(mktemp -d)
server=
cleanup() {
    [ -z "$server" ] || kill "$server" 2>/dev/null || true
    chmod -R u+w "$work" 2>/dev/null || true
    rm -rf "$work"
}
trap cleanup EXIT

fail() {
    echo "smoke: FAILED: $*" >&2
    exit 1
}

tar -xzf "$tarball" -C "$work"
echo 'smoke-release' >"$work/RELEASE"
cat >"$work/.env" <<EOF
APP_ENV=staging
APP_URL=https://Staging.Edict.NetWorkXXIII.de
APP_SECRET=$(printf '0123456789abcdef%.0s' 1 2 3 4)
DATABASE_URL=$database
GOOGLE_OIDC_CLIENT_ID=smoke-google
GOOGLE_OIDC_CLIENT_SECRET=smoke-secret
EOF

[ ! -e "$work/vendor/phpunit" ] || fail "dev dependencies are in the release"

# Warmed from another directory and then read-only, as on the host, where the
# pool has no write access to the release.
(cd / && php "$work/bin/edict" cache:warmup)
(cd / && php "$work/bin/edict" migrate)
chmod -R a-w "$work/var"

php -S "127.0.0.1:$port" -t "$work/public" "$work/public/index.php" >"$work/server.log" 2>&1 &
server=$!

url=http://127.0.0.1:$port
tries=0
until curl -fs "$url/healthz" >"$work/health.json"; do
    tries=$((tries + 1))
    [ "$tries" -lt 20 ] || { cat "$work/server.log"; fail "/healthz never answered"; }
    sleep 1
done

grep -q '"status":"ok"' "$work/health.json" || fail "unhealthy: $(cat "$work/health.json")"
grep -q '"database":"ok"' "$work/health.json" || fail "no database: $(cat "$work/health.json")"
grep -q '"templates":"ok"' "$work/health.json" || fail "templates: $(cat "$work/health.json")"
grep -q '"release":"smoke-release"' "$work/health.json" || fail "wrong release: $(cat "$work/health.json")"

curl -fs "$url/" | grep -q 'Hello, Edict' || fail "home page"
if grep -qi 'fatal\|warning' "$work/server.log"; then
    cat "$work/server.log"
    fail "problems in the server log"
fi

# Login start: the provider redirect uses the lowercase host and a __Host- cookie.
headers=$(curl -s -D - -o /dev/null "$url/auth/google")
echo "$headers" | grep -qi '^location: https://accounts\.google\.com/.*redirect_uri=https%3A%2F%2Fstaging\.edict\.networkxxiii\.de%2Fauth%2Fgoogle%2Fcallback' ||
    fail "login redirect: $headers"
echo "$headers" | grep -qi '^set-cookie: __Host-edict_oauth=.*Path=/.*HttpOnly.*SameSite=Lax.*Secure' || fail "flow cookie: $headers"

# Twitch has no credentials here, so it must not exist.
[ "$(curl -s -o /dev/null -w '%{http_code}' "$url/auth/twitch")" = 404 ] || fail "unconfigured provider is reachable"

# An encoded cookie name must not pass as the protected one.
flow=$(echo "$headers" | sed -n 's/^[Ss]et-[Cc]ookie: __Host-edict_oauth=\([^;]*\).*/\1/p')
state=$(echo "$headers" | sed -n 's/^[Ll]ocation: .*[?&]state=\([^&]*\).*/\1/p' | tr -d '\r')
location=$(curl -s -D - -o /dev/null -H "Cookie: %5F%5FHost-edict_oauth=$flow" "$url/auth/google/callback?code=x&state=$state" |
    sed -n 's/^[Ll]ocation: //p' | tr -d '\r')
[ "$location" = '/login?notice=expired' ] || fail "encoded cookie name was accepted: $location"

# A failing request must not reveal details in production mode.
if curl -s "$url/does-not-exist" | grep -qi 'stack trace'; then
    fail "error page shows a stack trace"
fi
[ "$(curl -s -o /dev/null -w '%{http_code}' "$url/does-not-exist")" = 404 ] || fail "unknown path is not a 404"
[ "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$url/healthz")" = 405 ] || fail "wrong method is not a 405"
if grep -q 'HttpNotFound\|HttpMethodNotAllowed' "$work/server.log"; then
    cat "$work/server.log"
    fail "404s and 405s must not be logged as errors"
fi

echo 'smoke: OK'
