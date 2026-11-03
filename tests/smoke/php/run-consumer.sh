#!/usr/bin/env bash
# The gate's PHP smoke scenarios (docs/smoke-tests.md), run inside the PHP test image from
# scripts/docker-test.sh smoke: archives the Composer package (composer archive, composer.json
# "archive.exclude"), installs it with the locked Predis into a fresh consumer project, and runs every
# tests/smoke/php/scenario-*.php there with Predis and phpredis against the server at REDIS_HOST:REDIS_PORT.
# Written for bash 3.2.
set -euo pipefail
cd "$(dirname "$0")/../../.."
work="$(mktemp -d)"
composer archive --format=tar --dir="$work" --file=package --no-interaction > /dev/null
mkdir "$work/package"
tar -xf "$work/package.tar" -C "$work/package"
predis_version="$(composer show --locked predis/predis --format=json | php -r 'echo json_decode(stream_get_contents(STDIN), true)["versions"][0];')"
mkdir "$work/consumer"
cp tests/smoke/php/*.php "$work/consumer/"
cd "$work/consumer"
cat > composer.json <<JSON
{
  "name": "rsw/smoke-consumer",
  "type": "project",
  "repositories": [{ "type": "path", "url": "../package", "options": { "symlink": false } }],
  "require": { "georgimorozov/redis-sliding-window": "*@dev", "predis/predis": "$predis_version" },
  "config": { "allow-plugins": false }
}
JSON
composer install --no-interaction --no-progress
status=0
for scenario in scenario-*.php; do
  echo "=== smoke: php $scenario"
  php "$scenario" || status=1
done
exit "$status"
