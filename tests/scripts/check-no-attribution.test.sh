#!/usr/bin/env bash
# Tests for scripts/check-no-attribution.sh: single messages (--stdin) and commit ranges in a throwaway
# repository. Prints one "ok"/"not ok" line per case; exits 1 if any case failed.
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
script="$root/scripts/check-no-attribution.sh"
failed=0

check() { # <description> <expected exit status> <command...>
  local description="$1" want="$2" got=0
  shift 2
  "$@" > /dev/null 2>&1 || got=$?
  if [ "$got" -eq "$want" ]; then
    echo "ok - $description"
  else
    echo "not ok - $description (exit $got, expected $want)"
    failed=1
  fi
}

message() { printf '%s\n' "$1" | bash "$script" --stdin; }

check "the current default trailer is caught" 1 message $'Fix a bug\n\nCo-authored-by: Claude <claude@anthropic.com>'
check "an older trailer with a model name is caught" 1 message $'Fix a bug\n\nCo-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>'
check "a trailer with only an anthropic.com address is caught" 1 message $'Fix a bug\n\nCo-authored-by: Assistant <bot@anthropic.com>'
check "the generated-with line with its link is caught" 1 message $'Fix a bug\n\nGenerated with [Claude Code](https://claude.com/claude-code)'
check "a plain generated-with line is caught" 1 message $'Fix a bug\n\nGenerated with Claude Code'
check "a session link is caught" 1 message $'Fix a bug\n\nhttps://claude.ai/code/session_0123'
check "a human co-author passes" 0 message $'Fix a bug\n\nCo-authored-by: Jane Doe <jane@example.com>'
check "a co-author whose name only contains claude passes" 0 message $'Fix a bug\n\nCo-authored-by: Claudette Smith <claudette@example.com>'
check "a subject that mentions Claude without attribution passes" 0 message 'Document the rule against Claude attribution'

repo="$(mktemp -d)"
trap 'rm -rf "$repo"' EXIT
g() { git -C "$repo" -c user.name=Test -c user.email=test@example.com -c commit.gpgsign=false -c core.hooksPath=/dev/null "$@"; }
range() { (cd "$repo" && bash "$script" "$@"); }

g init -q -b main
g commit -q --allow-empty -m $'Base commit\n\nCo-authored-by: Claude <claude@anthropic.com>'
g branch base
g switch -q -c feature
g commit -q --allow-empty -m "A clean change"
check "a clean branch passes even when its base carries attribution" 0 range base
g commit -q --allow-empty -m $'A change\n\nCo-authored-by: Claude <claude@anthropic.com>'
check "a branch commit with the trailer fails" 1 range base
g reset -q --hard HEAD~1
g -c user.email=noreply@anthropic.com commit -q --allow-empty -m "Authored by an anthropic.com address"
check "an anthropic.com author fails" 1 range base
g reset -q --hard HEAD~1
GIT_COMMITTER_EMAIL=noreply@anthropic.com g commit -q --allow-empty -m "Committed by an anthropic.com address"
check "an anthropic.com committer fails" 1 range base
g reset -q --hard HEAD~1
check "a missing base ref is an error, not a pass" 2 range no-such-ref
g update-ref refs/remotes/origin/main base
check "the default base is origin/main" 0 range

exit "$failed"
