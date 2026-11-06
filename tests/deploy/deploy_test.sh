#!/bin/sh
# Tests deploy/edict-deploy with small fake releases behind PHP's built-in server.
# Needs real symlinks, so it runs in CI on Linux rather than on the Windows dev PC.
set -eu

here=$(cd "$(dirname "$0")" && pwd)
script=$here/../../deploy/edict-deploy
port=8097

work=$(mktemp -d)
server=
cleanup() {
    [ -z "$server" ] || kill "$server" 2>/dev/null || true
    rm -rf "$work"
}
trap cleanup EXIT

EDICT_BASE=$work/base
export EDICT_DEPLOY_CONF="$work/deploy.conf"
mkdir -p "$EDICT_BASE/releases" "$EDICT_BASE/shared"
echo 'APP_ENV=staging' >"$EDICT_BASE/shared/.env"
cat >"$EDICT_DEPLOY_CONF" <<EOF
BASE=$EDICT_BASE
MAX_BYTES=1048576
HEALTH_URL=http://127.0.0.1:$port/healthz
HEALTH_TIMEOUT=2
KEEP_RELEASES=3
EOF

deploy() { sh "$script" staging "$@"; }

failures=0
check() {
    if [ "$2" = "$3" ]; then
        echo "ok: $1"
    else
        echo "FAILED: $1: expected '$3', got '$2'" >&2
        failures=$((failures + 1))
    fi
}

live() { basename "$(readlink "$EDICT_BASE/current" 2>/dev/null)" 2>/dev/null || true; }
count() { find "$EDICT_BASE/releases" -mindepth 1 -maxdepth 1 | wc -l | tr -d ' '; }

# Builds a fake release. $2: empty, or how it should fail: boot, migrate, unhealthy, stale.
build() {
    dir=$work/build-$1
    mkdir -p "$dir/public" "$dir/bin"
    printf '%040d\n' "$1" | tr '0123456789' 'abcdef0123' >"$dir/BUILD_SHA"
    case ${2:-} in
    boot) echo '<?php exit(1);' >"$dir/bin/edict" ;;
    migrate) echo '<?php exit(($argv[1] ?? "") === "migrate" ? 1 : 0);' >"$dir/bin/edict" ;;
    *) echo '<?php if (($argv[1] ?? "") === "migrate") { file_put_contents(dirname(__DIR__, 3) . "/migrated", "x", FILE_APPEND); }' >"$dir/bin/edict" ;;
    esac
    case ${2:-} in
    unhealthy) echo '<?php echo json_encode(["status" => "degraded", "release" => trim(file_get_contents(__DIR__ . "/../RELEASE"))]);' >"$dir/public/index.php" ;;
    stale) echo '<?php echo json_encode(["status" => "ok", "release" => "some-other-release"]);' >"$dir/public/index.php" ;;
    *) echo '<?php echo json_encode(["status" => "ok", "release" => trim(file_get_contents(__DIR__ . "/../RELEASE"))]);' >"$dir/public/index.php" ;;
    esac
    tar -czf "$work/$1.tar.gz" -C "$dir" .
    echo "$work/$1.tar.gz"
}

# Serves whatever `current` points at, resolving the symlink on every request.
cat >"$work/router.php" <<EOF
<?php
\$index = '$EDICT_BASE/' . readlink('$EDICT_BASE/current') . '/public/index.php';
is_file(\$index) ? require \$index : http_response_code(503);
EOF
php -d realpath_cache_size=0 -S "127.0.0.1:$port" "$work/router.php" >/dev/null 2>&1 &
server=$!
sleep 1

# Timestamps have one-second resolution and name the release.
next_second() { sleep 1; }

echo '--- first deploy'
deploy deploy "$(build 1)"
first=$(live)
check 'first release is live' "$(echo "$first" | grep -Ec '^[0-9]{14}-[0-9a-f]{12}$')" 1
check 'env is linked' "$(cat "$EDICT_BASE/current/.env")" 'APP_ENV=staging'
check 'release file written' "$(cat "$EDICT_BASE/current/RELEASE")" "$first"
check 'migrations ran' "$(cat "$EDICT_BASE/migrated")" x
check 'lock released' "$(test -e "$EDICT_BASE/.deploy.lock" && echo held || echo free)" free

echo '--- nothing to roll back to'
if deploy rollback 2>/dev/null; then r=succeeded; else r=refused; fi
check 'rollback with one release is refused' "$r" refused

echo '--- second deploy, then rollback and forward again'
next_second
deploy deploy "$(build 2)"
second=$(live)
check 'second release is live' "$(test "$second" != "$first" && echo yes)" yes
deploy rollback
check 'rollback goes to the previous release' "$(live)" "$first"
deploy rollback "$second"
check 'rollback to a named release' "$(live)" "$second"
if deploy rollback ../../etc 2>/dev/null; then r=succeeded; else r=refused; fi
check 'bad release name is refused' "$r" refused
if deploy rollback "$second" 2>/dev/null; then r=succeeded; else r=refused; fi
check 'rollback to the live release is refused' "$r" refused
check 'still on the second release' "$(live)" "$second"

echo '--- failures leave the live release in place'
for mode in boot migrate unhealthy stale; do
    next_second
    before=$(count)
    if deploy deploy "$(build "1$(printf '%s' "$mode" | wc -c | tr -d ' ')" "$mode")" 2>/dev/null; then r=succeeded; else r=failed; fi
    check "$mode: deploy fails" "$r" failed
    check "$mode: live release unchanged" "$(live)" "$second"
    check "$mode: no leftovers" "$(count)" "$before"
    check "$mode: lock released" "$(test -e "$EDICT_BASE/.deploy.lock" && echo held || echo free)" free
done

echo '--- a failed first deploy leaves no current link'
rm -rf "$EDICT_BASE/current" "$EDICT_BASE/releases"/*
if deploy deploy "$(build 20 unhealthy)" 2>/dev/null; then r=succeeded; else r=failed; fi
check 'fails' "$r" failed
check 'no current link' "$(test -L "$EDICT_BASE/current" && echo present || echo absent)" absent
check 'no release left' "$(count)" 0

echo '--- a held lock blocks a deploy'
mkdir "$EDICT_BASE/.deploy.lock"
if deploy deploy "$(build 21)" 2>/dev/null; then r=succeeded; else r=refused; fi
check 'refused' "$r" refused
check 'another deploy does not remove the lock' "$(test -d "$EDICT_BASE/.deploy.lock" && echo held)" held
rmdir "$EDICT_BASE/.deploy.lock"

echo '--- pruning keeps the newest releases and the live one'
for n in 31 32 33 34; do
    next_second
    deploy deploy "$(build "$n")"
done
check 'keeps three' "$(count)" 3
newest=$(live)
oldest=$(deploy releases | head -n 1)
deploy rollback "$oldest"
next_second
deploy deploy "$(build 35)"
check 'an old release is pruned' "$(test -d "$EDICT_BASE/releases/$oldest" && echo kept || echo pruned)" pruned
check 'the newest stays' "$(deploy releases | tail -n 1 | cut -d' ' -f1)" "$(live)"
check 'the one before stays' "$(test -d "$EDICT_BASE/releases/$newest" && echo kept)" kept
check 'list marks the live release' "$(deploy releases | grep -c '(live)')" 1

echo '--- tarball on stdin'
next_second
before_stdin=$(live)
deploy deploy - <"$(build 41)"
check 'a tarball on stdin deploys' "$(test "$(live)" != "$before_stdin" && echo yes)" yes
check 'the upload is removed' "$(find "$EDICT_BASE" -maxdepth 1 -name '.upload-*' | wc -l | tr -d ' ')" 0

echo '--- forced-command key'
next_second
before_ssh=$(live)
SSH_ORIGINAL_COMMAND=deploy sh "$script" staging ssh <"$(build 42)"
check 'ssh deploy' "$(test "$(live)" != "$before_ssh" && echo yes)" yes
SSH_ORIGINAL_COMMAND=rollback sh "$script" staging ssh
check 'ssh rollback' "$(live)" "$before_ssh"
check 'ssh releases' "$(SSH_ORIGINAL_COMMAND=releases sh "$script" staging ssh | grep -c '(live)')" 1
for bad in '' 'sh -c id' 'deploy extra' 'releases; id' 'rollback ../x' 'rollback a b' '*'; do
    if SSH_ORIGINAL_COMMAND=$bad sh "$script" staging ssh </dev/null >/dev/null 2>&1; then r=succeeded; else r=refused; fi
    check "ssh refuses '$bad'" "$r" refused
done
check 'still live after refused commands' "$(live)" "$before_ssh"

echo '--- unsafe tarballs'
mkdir -p "$work/evil/public" "$work/evil/bin"
tar -P -czf "$work/traversal.tar.gz" -C "$work/evil" --transform 's,^,../../,' .
if deploy deploy "$work/traversal.tar.gz" 2>/dev/null; then r=succeeded; else r=refused; fi
check 'path traversal is refused' "$r" refused
head -c 2097152 /dev/zero >"$work/huge.tar.gz"
if deploy deploy "$work/huge.tar.gz" 2>/dev/null; then r=succeeded; else r=refused; fi
check 'an oversized file is refused' "$r" refused
if deploy deploy - <"$work/huge.tar.gz" 2>/dev/null; then r=succeeded; else r=refused; fi
check 'an oversized upload is refused' "$r" refused
check 'still live after unsafe tarballs' "$(live)" "$before_ssh"

echo '--- bad arguments'
if sh "$script" prod releases 2>/dev/null; then r=succeeded; else r=refused; fi
check 'unknown environment is refused' "$r" refused
: >"$work/garbage.tar.gz"
if deploy deploy "$work/garbage.tar.gz" 2>/dev/null; then r=succeeded; else r=refused; fi
check 'a broken tarball is refused' "$r" refused

if [ "$failures" -ne 0 ]; then
    echo "$failures check(s) failed" >&2
    exit 1
fi
echo 'deploy_test: OK'
