# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Design contract (`docs/design.md`): bucket keys, the Lua script's interface, the TypeScript and PHP APIs, the failure policy with its error callback, and the test plan.
- The sliding-window Lua script (`lua/sliding-window.lua`): one atomic call on the server's clock, with nonce-based replay protection and a retry time that holds when a lowered limit leaves a bucket overfull.
- The TypeScript package: `SlidingWindowLimiter` for ioredis (`load()` with `SCRIPT LOAD`, `hit()` with `EVALSHA` and an `EVAL` fallback on `NOSCRIPT`), input and reply validation, the fail-open or fail-closed policy with the `onError` callback, and `retryAfterSeconds()` for a 429's `Retry-After`.
- Tests: unit tests, the shared vectors and integration tests (including a 40-way concurrent burst) against Valkey 9.1, Valkey 7.2 and Redis 7.2, and consumer smoke scenarios run from the packed tarball (`docs/smoke-tests.md`).
- Project scaffold: the TypeScript `FailMode` and `LimitResult` types, the PHP `FailMode` enum, two shared test vectors, Docker-based checks (unit tests, integration tests against Valkey, package checks), and the release procedure (`docs/release.md`).

[Unreleased]: https://github.com/georgimorozov/redis-sliding-window/commits/main
