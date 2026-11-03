#!/usr/bin/env bash
# Rebuilds /repo from the git bundle scripts/docker-test.sh tag-check copied to /tmp/tag.bundle: only the
# tag, plus a main branch at its commit so the repository has a HEAD. Sourced by the consumer scripts.
set -euo pipefail
git init -q /repo
git -C /repo fetch -q /tmp/tag.bundle "refs/tags/$TAG:refs/tags/$TAG"
git -C /repo update-ref refs/heads/main "$COMMIT"
git -C /repo symbolic-ref HEAD refs/heads/main
