# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Design contract (`docs/design.md`): bucket keys, the Lua script's interface, the TypeScript and PHP APIs, the failure policy with its error callback, and the test plan.
- Project scaffold: the TypeScript `FailMode` and `LimitResult` types, the PHP `FailMode` enum, two shared test vectors, Docker-based checks (unit tests, integration tests against Valkey, package checks), and the release procedure (`docs/release.md`).

[Unreleased]: https://github.com/georgimorozov/redis-sliding-window/commits/main
