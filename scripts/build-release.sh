#!/bin/sh
# Builds the release bundle in .scratch/release: the tarball plus the deploy
# script. Replaces vendor/ with production dependencies, so CI runs this in a
# fresh checkout. Usage: scripts/build-release.sh <git sha>
set -eu
cd "$(dirname "$0")/.."

sha=${1:?usage: build-release.sh <git sha>}
out=.scratch/release

rm -rf "$out"
mkdir -p "$out"

composer install --no-dev --classmap-authoritative --no-interaction --no-progress --prefer-dist

printf '%s\n' "$sha" >"$out/BUILD_SHA"
tar -czf "$out/edict-$sha.tar.gz" \
    -C "$out" BUILD_SHA \
    -C "$PWD" bin/edict composer.json config migrations public src templates vendor
rm "$out/BUILD_SHA"

cp deploy/edict-deploy "$out/edict-deploy"
echo "$out/edict-$sha.tar.gz"
