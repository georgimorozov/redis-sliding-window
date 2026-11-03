#!/usr/bin/env bash
# The gate's smoke scenarios (docs/smoke-tests.md), run inside the Node test image from
# scripts/docker-test.sh smoke: compiles and packs the package, installs the tarball into a fresh consumer
# project (with the locked ioredis, preferring the npm cache), and runs every tests/smoke/*.mjs there
# against the server at REDIS_HOST:REDIS_PORT. Written for bash 3.2.
set -euo pipefail
cd "$(dirname "$0")/../.."
npm run compile
work="$(mktemp -d)"
npm pack --pack-destination "$work" > /dev/null
ioredis_version="$(node -p "require('./node_modules/ioredis/package.json').version")"
mkdir "$work/consumer"
cp tests/smoke/*.mjs "$work/consumer/"
cd "$work/consumer"
printf '{ "name": "rsw-smoke-consumer", "private": true, "type": "module" }\n' > package.json
npm install --prefer-offline --ignore-scripts --no-audit --no-fund "$work"/redis-sliding-window-*.tgz "ioredis@$ioredis_version"
status=0
for scenario in *.mjs; do
  echo "=== smoke: $scenario"
  node "$scenario" || status=1
done
exit "$status"
