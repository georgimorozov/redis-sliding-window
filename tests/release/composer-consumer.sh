#!/usr/bin/env bash
# The Composer half of scripts/docker-test.sh tag-check, run in the PHP test image but outside /app with a
# fresh COMPOSER_HOME: requires exactly $VERSION from the VCS repository /repo, then checks the installed
# commit and runs consumer.php against the consumer's own autoloader. Written for bash 3.2.
set -euo pipefail
. /check/restore-repo.sh
export COMPOSER_HOME=/tmp/composer-home COMPOSER_CACHE_DIR=/tmp/composer-cache
mkdir /consumer && cd /consumer
cat > composer.json <<JSON
{
  "name": "rsw/tag-consumer",
  "repositories": [{ "type": "vcs", "url": "/repo" }],
  "require": { "georgimorozov/redis-sliding-window": "$VERSION" },
  "minimum-stability": "stable"
}
JSON
composer install --no-interaction --no-progress
installed="$(php -r '$i = json_decode(file_get_contents("vendor/composer/installed.json"), true);
foreach ($i["packages"] as $p) { if ($p["name"] === "georgimorozov/redis-sliding-window") { echo $p["version_normalized"], " ", $p["source"]["reference"]; } }')"
if [ "$installed" != "$VERSION.0 $COMMIT" ]; then
  echo "tag-check (composer): installed '$installed', expected '$VERSION.0 $COMMIT'" >&2
  exit 1
fi
cp /check/consumer.php .
php consumer.php
