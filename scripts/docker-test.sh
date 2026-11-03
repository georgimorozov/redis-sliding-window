#!/usr/bin/env bash
# The gate's Docker checks (.directives/gate.json) and two build helpers. Works against a local daemon or
# the build box (for "remote_docker": true, bin/gate exports DOCKER_HOST or DOCKER_CONTEXT). Images are
# built from the repository (docker/test.Dockerfile); nothing is bind-mounted. Every image, container and
# network name carries this run's id (GATE_RUN_ID, or a random local one), and the EXIT trap removes those
# names, best effort while the daemon answers; a hard kill can leave them (docker ps -a --filter name=rsw-).
# Written for bash 3.2 (macOS /bin/bash).
#
#   ts           Prettier check, ESLint, typecheck, compile (Node 24); unit tests (Node 22, 24, 26)
#   php          composer validate --strict, Pint, PHPStan (PHP 8.4); unit tests (PHP 8.3, 8.4, 8.5)
#   integration  the vectors and both packages' integration tests (PHP: Predis and phpredis) against each server in SERVER_IMAGES
#   package      the packages as consumers get them, and the release helpers' test (the gate's live step)
#   smoke        consumer scenarios, npm and Composer (tests/smoke, docs/smoke-tests.md) against Valkey 9.1 (the gate's smoke)
#   format       rewrites the files Prettier would change (build helper, not in the gate)
#   locks        regenerates package-lock.json and composer.lock (build helper, not in the gate)
#   release-dist compiles dist/ in a fresh Node container and replaces ./dist with it (release helper)
#   tag-check T  installs local tag T (vX.Y.Z) as an npm git dependency and a Composer VCS package in
#                isolated consumers, from a git bundle, and checks what they get (release helper,
#                docs/release.md; its test: tests/scripts/release-tag-check.test.sh)
set -euo pipefail
cd "$(dirname "$0")/.."

DOCKERFILE=docker/test.Dockerfile
NODE_VERSIONS="22 24 26"
PHP_VERSIONS="8.3 8.4 8.5"
MAIN_NODE=24
MAIN_PHP=8.4
VALKEY_IMAGE=valkey/valkey:9.1
# The supported floor (Redis 7.2+, Valkey 7.2+) and the current Valkey (docs/design.md, "Supported versions").
SERVER_IMAGES="valkey/valkey:9.1 valkey/valkey:7.2 redis:7.2"

run_id="${GATE_RUN_ID:-local_$(od -An -N4 -tx1 /dev/urandom | tr -d ' \n')}"
prefix="rsw-$(printf '%s' "$run_id" | tr -c 'a-z0-9_' '_')"
containers=""
networks=""
images=""
tmpfile=""
IMAGE_TAG=""
count=0

cleanup() {
  local rc=$? name
  for name in $containers; do docker rm -fv "$name" > /dev/null 2>&1 || true; done
  for name in $networks; do docker network rm "$name" > /dev/null 2>&1 || true; done
  for name in $images; do docker rmi "$name" > /dev/null 2>&1 || true; done
  if [ -n "$tmpfile" ]; then rm -f "$tmpfile"; fi
  exit "$rc"
}
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

build_image() { # <node|php> <version>: builds that test image and sets IMAGE_TAG
  local kind="$1" version="$2" arg
  IMAGE_TAG="$prefix-$kind-${version//./-}"
  if [ "$kind" = node ]; then arg="NODE_VERSION=$version"; else arg="PHP_VERSION=$version"; fi
  images="$images $IMAGE_TAG"
  echo "=== building $IMAGE_TAG"
  docker build --progress=plain -f "$DOCKERFILE" --target "$kind-test" --build-arg "$arg" -t "$IMAGE_TAG" .
}

run_named() { # <name> <docker run arguments...>: a container registered for cleanup before it starts
  local name="$1"
  shift
  containers="$containers $name"
  docker run --rm --name "$name" "$@"
}

run_in() { # <image> <command...>: one command in a fresh container of a test image
  local image="$1"
  shift
  count=$((count + 1))
  echo "=== $image: $*"
  run_named "$prefix-run-$count" "$image" "$@"
}

start_server() { # <image> <container> <network>: starts a Redis or Valkey server without persistence
  local binary=valkey-server
  case "$1" in redis:*) binary=redis-server ;; esac
  containers="$containers $2"
  docker run -d --name "$2" --network "$3" "$1" "$binary" --save '' --appendonly no > /dev/null
  wait_for_ping "$2"
}

wait_for_ping() { # <container>: waits up to 30 s for the server in it to answer PING
  local attempt=0
  until [ "$(docker exec "$1" sh -c 'valkey-cli ping 2> /dev/null || redis-cli ping' 2> /dev/null || true)" = PONG ]; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 60 ]; then
      echo "docker-test: $1 did not answer PING within 30 s" >&2
      docker logs "$1" >&2 || true
      return 1
    fi
    sleep 0.5
  done
}

cmd_ts() {
  local version
  for version in $NODE_VERSIONS; do
    build_image node "$version"
    if [ "$version" = "$MAIN_NODE" ]; then
      run_in "$IMAGE_TAG" npm run format:check
      run_in "$IMAGE_TAG" npm run lint
      run_in "$IMAGE_TAG" npm run typecheck
      run_in "$IMAGE_TAG" npm run compile
    fi
    run_in "$IMAGE_TAG" npm run test:unit
  done
}

cmd_php() {
  local version
  for version in $PHP_VERSIONS; do
    build_image php "$version"
    if [ "$version" = "$MAIN_PHP" ]; then
      run_in "$IMAGE_TAG" composer validate --strict
      run_in "$IMAGE_TAG" vendor/bin/pint --test -v
      run_in "$IMAGE_TAG" vendor/bin/phpstan analyse --no-progress
    fi
    run_in "$IMAGE_TAG" vendor/bin/phpunit --testsuite unit
  done
}

cmd_integration() {
  local network="$prefix-net" node_image php_image image server n=0
  build_image node "$MAIN_NODE"
  node_image="$IMAGE_TAG"
  build_image php "$MAIN_PHP"
  php_image="$IMAGE_TAG"
  networks="$networks $network"
  docker network create "$network" > /dev/null
  for image in $SERVER_IMAGES; do
    n=$((n + 1))
    server="$prefix-server-$n"
    start_server "$image" "$server" "$network"
    echo "=== TypeScript vectors and integration (Node $MAIN_NODE, ioredis) against $image"
    run_named "$prefix-itest-ts-$n" --network "container:$server" -e REDIS_HOST=127.0.0.1 -e REDIS_PORT=6379 \
      "$node_image" sh -c 'npm run test:feature && npm run test:integration'
    echo "=== PHP vectors and integration (PHP $MAIN_PHP, Predis and phpredis) against $image"
    run_named "$prefix-itest-php-$n" --network "container:$server" -e REDIS_HOST=127.0.0.1 -e REDIS_PORT=6379 \
      "$php_image" sh -c 'vendor/bin/phpunit --testsuite feature && vendor/bin/phpunit --testsuite integration'
    docker rm -fv "$server" > /dev/null
  done
}

cmd_package() {
  build_image node "$MAIN_NODE"
  run_in "$IMAGE_TAG" sh -c 'npm run compile && npm pack --dry-run --json | node scripts/check-pack.mjs'
  build_image php "$MAIN_PHP"
  run_in "$IMAGE_TAG" sh -c 'composer dump-autoload --no-dev --classmap-authoritative && php scripts/check-autoload.php'
  echo "=== release helpers: tests/scripts/release-tag-check.test.sh"
  bash tests/scripts/release-tag-check.test.sh
}

cmd_smoke() {
  local network="$prefix-net" server="$prefix-server-smoke" node_image
  build_image node "$MAIN_NODE"
  node_image="$IMAGE_TAG"
  build_image php "$MAIN_PHP"
  networks="$networks $network"
  docker network create "$network" > /dev/null
  start_server "$VALKEY_IMAGE" "$server" "$network"
  run_named "$prefix-smoke" --network "container:$server" -e REDIS_HOST=127.0.0.1 -e REDIS_PORT=6379 \
    "$node_image" bash tests/smoke/run-consumer.sh
  run_named "$prefix-smoke-php" --network "container:$server" -e REDIS_HOST=127.0.0.1 -e REDIS_PORT=6379 \
    "$IMAGE_TAG" bash tests/smoke/php/run-consumer.sh
}

cmd_format() {
  local files file
  build_image node "$MAIN_NODE"
  containers="$containers $prefix-format-list"
  # --list-different exits 1 when files differ (expected here); any other failure stops the helper.
  local status=0
  files="$(docker run --rm --name "$prefix-format-list" "$IMAGE_TAG" npx prettier --list-different .)" || status=$?
  if [ "$status" -ne 0 ] && [ "$status" -ne 1 ]; then
    echo "docker-test: prettier --list-different failed (exit $status)" >&2
    exit "$status"
  fi
  for file in $files; do
    count=$((count + 1))
    tmpfile="$file.rsw-tmp"
    run_named "$prefix-format-$count" -i "$IMAGE_TAG" npx prettier --stdin-filepath "$file" < "$file" > "$tmpfile"
    mv "$tmpfile" "$file"
    tmpfile=""
    echo "formatted $file"
  done
  echo "docker-test: Prettier done. For PHP, run 'php' and apply the diff 'pint --test -v' prints."
}

cmd_locks() {
  tmpfile=package-lock.json.rsw-tmp
  run_named "$prefix-lock-npm" -i "node:$MAIN_NODE-trixie" sh -c \
    'mkdir /w && cd /w && cat > package.json && npm install --package-lock-only --ignore-scripts --no-audit --no-fund >&2 && cat package-lock.json' \
    < package.json > "$tmpfile"
  mv "$tmpfile" package-lock.json
  tmpfile=composer.lock.rsw-tmp
  run_named "$prefix-lock-composer" -i --entrypoint sh composer/composer:2 -c \
    'mkdir /w && cd /w && cat > composer.json && composer update --no-install --no-interaction --no-progress >&2 && cat composer.lock' \
    < composer.json > "$tmpfile"
  mv "$tmpfile" composer.lock
  tmpfile=""
  echo "docker-test: wrote package-lock.json and composer.lock"
}

cmd_release_dist() {
  local name="$prefix-release-dist"
  build_image node "$MAIN_NODE"
  # Not run_named: its --rm would remove the container before dist/ is copied out of it.
  containers="$containers $name"
  docker create --name "$name" "$IMAGE_TAG" npm run compile > /dev/null
  docker start -a "$name"
  rm -rf dist
  docker cp "$name:/app/dist" dist
  echo "docker-test: wrote dist/ from a fresh compile"
}

cmd_tag_check() { # <vX.Y.Z>: never builds, changes or deletes the tag; exits nonzero on any failed check
  local tag="${1:-}" version commit bundle node_name php_name
  case "$tag" in
    v[0-9]*.[0-9]*.[0-9]*) ;;
    *)
      echo "usage: scripts/docker-test.sh tag-check vX.Y.Z" >&2
      exit 1
      ;;
  esac
  version="${tag#v}"
  if [ "$(git cat-file -t "refs/tags/$tag" 2> /dev/null || true)" != tag ]; then
    echo "docker-test: $tag is not an annotated tag in this repository" >&2
    exit 1
  fi
  commit="$(git rev-parse "refs/tags/$tag^{commit}")"
  if [ "$(git show "$commit:package.json" | sed -n 's/^  "version": "\(.*\)",$/\1/p')" != "$version" ]; then
    echo "docker-test: package.json at $tag does not say version $version" >&2
    exit 1
  fi
  bundle="$(mktemp "${TMPDIR:-/tmp}/rsw-tag.XXXXXX")"
  tmpfile="$bundle"
  git bundle create -q "$bundle" "refs/tags/$tag"
  build_image php "$MAIN_PHP"
  node_name="$prefix-tag-npm"
  php_name="$prefix-tag-composer"
  containers="$containers $node_name $php_name"
  echo "=== npm consumer: git+file:///repo#$tag"
  docker create --name "$node_name" -e TAG="$tag" -e COMMIT="$commit" "node:$MAIN_NODE-trixie" \
    bash /check/npm-consumer.sh > /dev/null
  docker cp "$bundle" "$node_name:/tmp/tag.bundle"
  docker cp tests/release "$node_name:/check"
  docker start -a "$node_name"
  echo "=== Composer consumer: $version from a VCS repository"
  docker create --name "$php_name" -e TAG="$tag" -e COMMIT="$commit" -e VERSION="$version" "$IMAGE_TAG" \
    bash /check/composer-consumer.sh > /dev/null
  docker cp "$bundle" "$php_name:/tmp/tag.bundle"
  docker cp tests/release "$php_name:/check"
  docker start -a "$php_name"
  echo "docker-test: $tag ($commit) installs with npm and Composer"
}

case "${1:-}" in
  ts) cmd_ts ;;
  php) cmd_php ;;
  integration) cmd_integration ;;
  package) cmd_package ;;
  smoke) cmd_smoke ;;
  format) cmd_format ;;
  locks) cmd_locks ;;
  release-dist) cmd_release_dist ;;
  tag-check) cmd_tag_check "${2:-}" ;;
  *)
    echo "usage: scripts/docker-test.sh ts|php|integration|package|smoke|format|locks|release-dist|tag-check vX.Y.Z" >&2
    exit 1
    ;;
esac
