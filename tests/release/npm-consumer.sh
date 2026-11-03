#!/usr/bin/env bash
# The npm half of scripts/docker-test.sh tag-check, in a plain node image (no repository source, no dev
# dependencies): installs git+file:///repo#$TAG with npm's normal lifecycle behaviour into a fresh consumer,
# then checks the resolved commit, the shipped files, an ESM import and a NodeNext TypeScript consumer.
# Written for bash 3.2.
set -euo pipefail
fail() { echo "tag-check (npm): $*" >&2; exit 1; }
. /check/restore-repo.sh
export npm_config_cache=/tmp/npm-cache
mkdir /consumer && cd /consumer
printf '{ "name": "rsw-tag-consumer", "private": true, "type": "module" }\n' > package.json
npm install --no-audit --no-fund "git+file:///repo#$TAG" 'ioredis@^6.0.0'
pkg=node_modules/redis-sliding-window
resolved="$(node -p "require('./package-lock.json').packages['$pkg'].resolved")"
case "$resolved" in *"#$COMMIT") ;; *) fail "resolved $resolved, expected commit $COMMIT" ;; esac
for file in dist/index.js dist/index.d.ts lua/sliding-window.lua; do
  [ -f "$pkg/$file" ] || fail "$file is missing from the installed package (was dist/ committed on the tag?)"
done
node -e "
const p = require('./$pkg/package.json');
const banned = ['build', 'prepare', 'prepack', 'preinstall', 'install', 'postinstall'];
const found = banned.filter((s) => p.scripts && s in p.scripts);
if (found.length || p.workspaces) { console.error('tag-check (npm): lifecycle scripts or workspaces: ' + found.join(' ')); process.exit(1); }
"
for dev in vitest typescript eslint prettier; do
  [ ! -e "node_modules/$dev" ] || fail "the limiter's dev dependency $dev was installed"
done
cp /check/consumer.mjs /check/consumer.ts .
node consumer.mjs
mkdir /tsc && (cd /tsc && printf '{ "private": true }\n' > package.json \
  && npm install --no-audit --no-fund typescript@~6.0.3 @types/node@^22 > /dev/null)
/tsc/node_modules/.bin/tsc --noEmit --strict --module nodenext --moduleResolution nodenext --target es2022 \
  --types node --typeRoots /tsc/node_modules/@types consumer.ts
echo "tag-check (npm): $TAG installs at $COMMIT, imports as ESM and type-checks under NodeNext"
