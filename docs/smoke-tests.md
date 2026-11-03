# Smoke tests

The gate's `smoke` command (`bash scripts/docker-test.sh smoke`) runs two consumer projects against Valkey 9.1.
npm: it compiles and packs the npm package, installs the tarball with ioredis into a fresh project and runs
`tests/smoke/*.mjs` (`tests/smoke/run-consumer.sh`). Composer: it archives the package (`composer archive`, which
honours `archive.exclude`), installs it with the locked Predis into a fresh project and runs
`tests/smoke/php/scenario-*.php` with Predis and phpredis (`tests/smoke/php/run-consumer.sh`). Each scenario
loads the package as a consumer does.

| Scenario | File | Checks |
|---|---|---|
| HTTP 429 | `tests/smoke/http-429.mjs` | A Node HTTP server with a limit of 2 per minute answers 200, 200, then 429 with a `Retry-After` of 59 to 60 seconds. |
| Outage | `tests/smoke/outage.mjs` | With the server unreachable, fail open allows and fail closed denies; both results are degraded and `onError` gets exactly `{ reason: 'connection', message }` and `{ name, failMode }`, without the identifier. |
| PHP HTTP 429 | `tests/smoke/php/scenario-http-429.php` (`app.php`) | A PHP app under `php -S` with a limit of 2 per minute answers 200, 200, then 429 with a `Retry-After` of 59 to 60 seconds, with a persistent Predis client and with phpredis `pconnect()`. |
| PHP outage | `tests/smoke/php/scenario-outage.php` | With the server unreachable, for Predis and phpredis, fail open allows and fail closed denies; both results are degraded and `onError` gets exactly `{ reason: 'connection', message }` and `{ name, failMode }`, without the identifier. |

The Laravel middleware and provider are covered by the PHPUnit suites through Orchestra Testbench
(`php/tests/Unit/Laravel`), not by a smoke scenario.
