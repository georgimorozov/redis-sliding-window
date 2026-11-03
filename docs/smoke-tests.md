# Smoke tests

The gate's `smoke` command (`bash scripts/docker-test.sh smoke`) compiles and packs the npm package, installs the
tarball with ioredis into a fresh consumer project, and runs each scenario below against Valkey 9.1
(`tests/smoke/run-consumer.sh`). Each scenario imports the package by its name, as a consumer does.

| Scenario | File | Checks |
|---|---|---|
| HTTP 429 | `tests/smoke/http-429.mjs` | A Node HTTP server with a limit of 2 per minute answers 200, 200, then 429 with a `Retry-After` of 59 to 60 seconds. |
| Outage | `tests/smoke/outage.mjs` | With the server unreachable, fail open allows and fail closed denies; both results are degraded and `onError` gets exactly `{ reason: 'connection', message }` and `{ name, failMode }`, without the identifier. |

RSW-3 adds the PHP scenarios.
