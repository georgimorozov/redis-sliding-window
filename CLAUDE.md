# CLAUDE.md: redis-sliding-window

A small, hardened **sliding-window rate limiter for Redis and Valkey**, with one atomic Lua script shared by a
TypeScript package and a PHP package. Public, MIT-licensed. This repo is built with the `directives` plugin's
PR loop, like the owner's other projects.

## Owner rules for this repo (read first)

- **Public repository.** Everything here is world-readable. Never commit secrets, hostnames, customer data, or
  details of incidents in any other project (owner, 2026-11-03: "NEVER EVER FUCKING SEND DETAILS OF OUR ISSUES
  ANYWHERE TO CLIENTS"). Write about the library itself, generically.
- **No Claude attribution anywhere** (owner, 2026-11-03: "no claude attribution on commits"). No `Co-Authored-By`
  trailer, no "Generated with Claude Code" line in PR descriptions, no session links. `.claude/settings.json`
  turns Claude Code's attribution off; the gate's attribution check fails a branch whose commit messages carry it.
- **Same workflow as the owner's other projects** (owner: "use the same workflow we use for our sites to
  build/release versions"): roadmap queue, one PR per item, Codex review, `bin/gate` on the final head, then merge.
  Releases are semantic-version git tags plus a GitHub release with notes from `CHANGELOG.md` (see
  `docs/release.md` once it exists). Consumers pin a tag.

## What it must be

- **Algorithm:** a true sliding window (not a fixed-window counter), atomic in one `EVAL`/`EVALSHA` round trip,
  safe under concurrent bursts at the window boundary. The reference implementation to extract and generalize is
  the owner's existing in-house limiter (cykanner: `src/lib/rate-limit/index.ts` with
  `tests/lib/rate-limit.test.ts` and the real-Redis `tests/integration/rate-limit-redis.test.ts`, including its
  40-way concurrent-burst test). Read it before designing; keep its guarantees.
- **One Lua script** (`lua/`), loaded with `SCRIPT LOAD` and called by SHA, with an `EVAL` fallback on
  `NOSCRIPT`. Both language packages use the same script file and the same test vectors (a shared conformance
  suite in `tests/vectors/`).
- **TypeScript package** (root `package.json`, ESM + types, ioredis first; node-redis adapter only if cheap).
- **PHP package** (root `composer.json`, PSR-4, Predis and phpredis; a thin Laravel integration: a limiter class
  and a middleware, auto-discovered service provider).
- **Results** expose: allowed or not, remaining, the reset/retry-after time, so callers can send `429` with
  `Retry-After`.
- **Failure policy is explicit:** callers choose fail-open or fail-closed on connection-level Redis errors
  (connect, TLS handshake, timeout). Fail-open reports through a callback (for Sentry or logs) and never throws.
  Document why fairness quotas usually fail open.
- **Connections:** the library never opens connections itself; callers pass a client. Document using persistent
  or pooled clients (a fresh TLS connection per request is a known failure mode).
- **Tests:** unit tests, plus integration tests against a real Redis/Valkey container on the build box
  (`.directives/gate.json`, `remote_docker`), including the concurrent-burst test in both languages.

## Commands

Every check runs in Docker (`scripts/docker-test.sh`, images from `docker/test.Dockerfile`, nothing
bind-mounted), so the same commands work against the build box; `bin/gate` points Docker there itself
(`"remote_docker": true`). By hand, first
`export DOCKER_HOST="$(tr -d '[:space:]' < ~/.config/directives/docker-remote-host)"`. Scripts target bash 3.2.

- TypeScript: `bash scripts/docker-test.sh ts` (Prettier check, ESLint, typecheck, compile on Node 24; unit tests on Node 22, 24, 26).
- PHP: `bash scripts/docker-test.sh php` (`composer validate --strict`, Pint, PHPStan on PHP 8.4; unit tests on PHP 8.3, 8.4, 8.5).
- Vectors and integration, both languages, against Valkey 9.1, Valkey 7.2 and Redis 7.2: `bash scripts/docker-test.sh integration`.
- Smoke (consumer scenarios from the packed tarball, `docs/smoke-tests.md`): `bash scripts/docker-test.sh smoke`.
- Package checks (the gate's `live` step): `bash scripts/docker-test.sh package`.
- Fix formatting: `bash scripts/docker-test.sh format` (Prettier); for Pint, apply the diff `pint --test -v` prints.
- After a dependency change: `bash scripts/docker-test.sh locks` (rewrites `package-lock.json` and `composer.lock`).
- No Claude attribution on this branch: `bash scripts/check-no-attribution.sh`; its tests: `bash tests/scripts/check-no-attribution.test.sh`.
- Gate: `bin/gate start all` (tests, then smoke).

With Node 24 and PHP 8.4 locally, the package scripts also run directly: `npm ci`, `npm run lint`,
`npm run typecheck`, `npm test`, `npm run test:feature`, `npm run test:integration`; `composer install`, `composer lint`, `composer analyse`,
`composer test`, `composer test:integration`. Integration tests need a server at `REDIS_HOST`:`REDIS_PORT` (default
127.0.0.1:6379) and fail without one. Never add a `build`, `prepare`, `prepack`, `preinstall`, `install` or
`postinstall` script to `package.json` (`docs/release.md`). Contract: `docs/design.md`.
