#!/usr/bin/env bash
# Tests scripts/docker-test.sh release-dist and tag-check in a disposable clone of this checkout's HEAD
# (the candidate commit, so commit first): a v1.0.0 tag without dist/ must fail tag-check on the missing
# compiled files (negative control), and after release-dist and a dist/ commit, a v1.0.0 tag must pass.
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
