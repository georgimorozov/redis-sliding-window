#!/usr/bin/env bash
# Fails when a commit on this branch carries Claude attribution (owner rule for this public repo):
# a Co-authored-by trailer naming Claude or an anthropic.com address, the Claude Code "generated
# with" line, a claude.ai/code session link, or an anthropic.com author or committer email.
# The gate runs it on every PR. Written for bash 3.2 (macOS /bin/bash).
#   scripts/check-no-attribution.sh [<base-ref>]  checks <base-ref>..HEAD (default origin/main)
#   scripts/check-no-attribution.sh --stdin       checks one commit message read from standard input
# Exit status: 0 clean, 1 attribution found, 2 the base ref does not exist.
set -euo pipefail

patterns=(
  '^[[:space:]]*co-authored-by:(.*[^[:alnum:]])?claude([^[:alnum:]]|$)'
  '^[[:space:]]*co-authored-by:.*anthropic\.com'
  'generated with \[?claude code'
  'claude\.ai/code'
)

has_attribution() { # <message>
  local pattern
  for pattern in "${patterns[@]}"; do
    if grep -Eiq -- "$pattern" <<< "$1"; then return 0; fi
  done
  return 1
}

if [ "${1:-}" = "--stdin" ]; then
  message="$(cat)"
  if has_attribution "$message"; then
    echo "check-no-attribution: the message carries Claude attribution" >&2
    exit 1
  fi
  exit 0
fi

base="${1:-origin/main}"
if ! git rev-parse --verify --quiet "$base^{commit}" > /dev/null; then
  echo "check-no-attribution: base ref '$base' does not exist (fetch it first: git fetch origin main)" >&2
  exit 2
fi

found=0
count=0
for sha in $(git rev-list "$base..HEAD"); do
  count=$((count + 1))
  if has_attribution "$(git log -1 --format=%B "$sha")"; then
    echo "check-no-attribution: commit $sha carries Claude attribution in its message" >&2
    found=1
  fi
  if grep -Eiq '@anthropic\.com$' <<< "$(git log -1 --format='%ae%n%ce' "$sha")"; then
    echo "check-no-attribution: commit $sha has an anthropic.com author or committer" >&2
    found=1
  fi
done

if [ "$found" -eq 0 ]; then
  echo "check-no-attribution: no Claude attribution in $count commit(s) since $base"
fi
exit "$found"
