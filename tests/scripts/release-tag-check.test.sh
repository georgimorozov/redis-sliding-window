#!/usr/bin/env bash
# Tests scripts/docker-test.sh release-dist and tag-check in a disposable clone of this checkout's HEAD
# (the candidate commit, so commit first): a v1.0.0 tag without dist/ must fail tag-check on the missing
# compiled files (negative control), malformed, lightweight and version-mismatched tags must fail their own
# checks without reaching Docker, and after release-dist and a dist/ commit, a v1.0.0 tag must pass.
# Needs Docker (DOCKER_HOST for the build box). Written for bash 3.2.
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
work="$(mktemp -d "${TMPDIR:-/tmp}/rsw-release-test.XXXXXX")"
trap 'rm -rf "$work"' EXIT
git clone -q "$root" "$work/repo"
cd "$work/repo"
git() { command git -c user.name=rsw-test -c user.email=rsw-test@example.invalid "$@"; }
version="$(sed -n 's/^  "version": "\(.*\)",$/\1/p' package.json)"
tag="v$version"

# Validation guards, before any container: a fake docker on PATH records any call, so each case must be
# rejected by its own check with its own message and never reach Docker.
mkdir -p "$work/fakebin"
printf '#!/bin/sh\necho called >> "%s/docker-called"\nexit 1\n' "$work" > "$work/fakebin/docker"
chmod +x "$work/fakebin/docker"
expect_guard() { # <name> <expected message> <tag-check argument...>
  local name="$1" want="$2" status=0
  shift 2
  PATH="$work/fakebin:$PATH" bash scripts/docker-test.sh tag-check "$@" > "$work/$name.log" 2>&1 || status=$?
  if [ "$status" -eq 0 ] || ! grep -qF "$want" "$work/$name.log" || [ -e "$work/docker-called" ]; then
    cat "$work/$name.log"
    echo "release-tag-check.test: $name: expected a nonzero exit with '$want' and no docker call" >&2
    exit 1
  fi
  echo "ok: tag-check rejects $name"
}
expect_guard malformed 'usage: scripts/docker-test.sh tag-check vX.Y.Z' "not-a-tag"
expect_guard missing-argument 'usage: scripts/docker-test.sh tag-check vX.Y.Z'
git tag "$tag"
expect_guard lightweight "$tag is not an annotated tag in this repository" "$tag"
git tag -d "$tag" > /dev/null
git tag -a v0.0.1 -m v0.0.1
expect_guard version-mismatch 'package.json at v0.0.1 does not say version 0.0.1' v0.0.1
git tag -d v0.0.1 > /dev/null

echo "=== negative control: $tag without dist/"
git tag -a "$tag" -m "$tag"
status=0
bash scripts/docker-test.sh tag-check "$tag" > "$work/neg.log" 2>&1 || status=$?
if [ "$status" -eq 0 ]; then
  cat "$work/neg.log"
  echo "release-tag-check.test: tag-check passed a tag without dist/" >&2
  exit 1
fi
if ! grep -q 'dist/index.js is missing from the installed package' "$work/neg.log"; then
  cat "$work/neg.log"
  echo "release-tag-check.test: tag-check failed, but not on the missing dist/" >&2
  exit 1
fi
echo "ok: tag-check rejects $tag without dist/"
git tag -d "$tag" > /dev/null

echo "=== positive: release-dist, dist/ committed, $tag"
bash scripts/docker-test.sh release-dist
[ -f dist/index.js ] && [ -f dist/index.d.ts ] || { echo "release-tag-check.test: release-dist wrote no dist/" >&2; exit 1; }
git add -f dist
git commit -q -m "Release $tag: compiled files"
[ -z "$(git diff --name-only HEAD~1 HEAD | grep -v '^dist/' || true)" ] || { echo "release-tag-check.test: release commit touches more than dist/" >&2; exit 1; }
git tag -a "$tag" -m "$tag"
bash scripts/docker-test.sh tag-check "$tag"
echo "ok: tag-check accepts $tag with dist/"
