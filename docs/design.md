# Design

The v1 contract for redis-sliding-window. Code that disagrees with it is a bug in one of the two, and a behavior
change updates this file in the same PR. RSW-2 (script and TypeScript) and RSW-3 (PHP and Laravel) implement it.

## Scope

In v1: an exact sliding window (a sliding log) on the server's clock; one atomic script call per request; a
TypeScript package (ioredis) and a PHP package (Predis, phpredis) that send the same script file and pass the same
vectors, plus a thin Laravel integration; an explicit fail-open or fail-closed choice. Servers: Redis 7.2+ and
Valkey 7.2+, through a client connected to one node (a standalone server or a primary).

Not in v1: cluster clients, other algorithms, weighted hits, read-only checks, a node-redis adapter, registry
publishing (RSW-6), callback throttling, durability beyond the server's own, ioredis databases other than 0,
client-level key prefixes.

## Layout

`lua/sliding-window.lua` the script (RSW-2) · `src/` TypeScript (ESM) · `dist/` compiled, only in release tags ·
`php/src/` PHP, PSR-4 `GeorgiMorozov\RedisSlidingWindow\` · `tests/ts/{unit,integration}/` Vitest ·
`php/tests/{Unit,Feature,Integration}/` PHPUnit suites `unit`, `feature`, `integration` · `php/src/Laravel/` the Laravel integration, `php/config/` its config · `tests/vectors/` shared vectors ·
`tests/scripts/` tests for repository scripts · `scripts/`, `docker/` gate and helpers.

## Buckets and keys

- Key: `<prefix><name>:<windowMs>:<identifier>`, for example `rsw:login:900000:203.0.113.7`.
- `prefix`: default `rsw:`. Empty, or segments that each end in `:`; no segment empty or all digits
  (`^([^:]*[^:0-9][^:]*:)*$`). Use one prefix per application.
- `name`: non-empty, no `:`, not all digits (`^[^:]*[^:0-9][^:]*$`).
- `identifier`: any non-empty string. It is the key's last part, so it may contain `:` (IPv6 addresses).
- Why the digit rule: the first all-digit segment of a key is then always the window, so every key parses back into
  exactly one (prefix, name, window, identifier), and distinct configurations never share a key. Without it,
  prefix `a:` with name `5` and window W, and prefix `` with name `a`, window 5 and identifier `W:…`, collide.
- The window is part of the key, so a bucket's window never changes. One name with two windows is two independent
  buckets. Changing a limiter's window starts new, empty buckets (the old keys expire within the old window; during
  a rolling deploy a client can get up to old limit + new limit).
- The limit is not part of the key. Instances with the same prefix, name and window share one log, and each decides
  against its own limit. A bucket left overfull by a lowered limit stays denied until enough entries age out, and
  the denied result says when (example 2). To discard history on purpose, rename the limiter.

## Algorithm

A bucket is a sorted set with one member per allowed request: the call's nonce, scored with the server time of
the request in integer microseconds. One script run per call does this atomically:

1. `now` = `TIME` in microseconds.
2. Remove members scored at or below `now - windowMs * 1000`.
3. If the nonce is still a member, the call is a resend of an allowed call whose entry remains: allowed, nothing
   changes.
4. Count the members. Below `limit`: add the nonce with score `now`, `PEXPIREAT` the key at `ceil(now / 1000) + windowMs` ms, allow.
   Otherwise deny and change nothing.

Guarantees (the tests pin each):
- N concurrent calls on an empty bucket with limit L allow exactly min(N, L).
- Capacity returns one request at a time as entries age out; denied calls are not recorded.
- A denied result says when the next request can succeed, also when the bucket holds more entries than the limit.
- Distinct (prefix, name, window) configurations never share a bucket.
- A call resent with the same nonce counts once while its entry remains; once the entry has aged out, or the key is
  gone, a resend is a new request.
- Every key expires `windowMs` after its newest entry. The error callback never receives the identifier.

They assume the server's atomic script execution and that bucket state survives on the server. Eviction,
`FLUSHDB`/`FLUSHALL`, a restart without persistence or a failover that loses writes drop entries: capacity returns,
and no result is degraded.

Changed from the in-house limiter this generalizes: the server's `TIME` instead of the app server's clock; random
nonces instead of timestamp-derived members; the window in the key; the retry time from rank `count - limit`
instead of the oldest entry; `SCRIPT LOAD` + `EVALSHA` instead of `EVAL` on every call; a classified failure policy
instead of a catch-all; no default fail mode.

## The Lua script

`lua/sliding-window.lua`. Both packages send its exact bytes; its `EVALSHA` id is the lowercase hex SHA-1 of them.

| Input | Meaning | Format |
|---|---|---|
| `KEYS[1]` | bucket key | string |
| `ARGV[1]` | `limit` | decimal integer, 1 to 2^53 - 1 |
| `ARGV[2]` | `windowMs` | decimal integer, 1 to 31536000000 (365 days) |
| `ARGV[3]` | nonce | 32 lowercase hex characters: 16 CSPRNG bytes, new for every `hit()` |

The packages validate inputs; the script trusts them.

- Clock: `TIME` gives seconds and microseconds; `now` stays below 2^53, so it is exact as a Lua number and as a
  sorted-set score. Numbers passed to commands are exact integer decimal strings, never Lua's default formatting
  (it rounds). Reading `TIME` before writing is safe because Redis 7.0+ and Valkey replicate a script's effects,
  not the script; hence the Redis 7.2 floor.
- Data: member = nonce, score = `now`. `PEXPIREAT key ceil(now / 1000) + windowMs` (milliseconds, absolute, rounded up so the key never expires before its newest entry's microsecond window ends) only when an entry is added. The replay check is
  `ZSCORE key nonce` (absent: nil, seen by Lua as `false`).
- Return: `{allowed, remaining, retryAfterMs, resetAfterMs}`, four integers.

| Case | allowed | remaining | retryAfterMs | resetAfterMs |
|---|---|---|---|---|
| new entry | 1 | `limit - count - 1` (count before the call) | 0 | `windowMs` |
| replay | 1 | `max(0, limit - count)` | 0 | until the newest entry ages out |
| denied | 0 | 0 | until the entry at rank `count - limit` ages out | until the newest entry ages out |

Ranks are 0-based, oldest first. "Until an entry with score s ages out" = `ceil((s + windowMs*1000 - now) / 1000)`
ms, clamped to 1..`windowMs`. With `count` >= `limit` entries, a request succeeds once `count - limit + 1` have aged
out, which is when the entry at rank `count - limit` does.

Reply validation: the packages throw, in both modes, unless the reply is exactly four integers with `allowed` 0 or
1 and, when allowed, `retryAfterMs` 0, `remaining` 0..`limit - 1`, `resetAfterMs` 1..`windowMs`; when denied,
`remaining` 0 and 1 <= `retryAfterMs` <= `resetAfterMs` <= `windowMs`. This also rejects replies from an
unsupported client state (a queued `MULTI` reply, a pipeline handle).

Worked examples (limit 3, window 60000 ms):
1. Three hits on an empty bucket are allowed (`remaining` 2, 1, 0; `resetAfterMs` 60000). A fourth, d1 ms after the
   first and d3 ms after the third, is denied with `retryAfterMs` 60000 - d1 and `resetAfterMs` 60000 - d3 (vector
   `limit-then-deny`).
2. Overfull after the limit was lowered from 5: entries 50, 40, 30, 20 and 10 s old. Rank 5 - 3 = 2 is the 30 s old
   entry, so `retryAfterMs` is 30000; `resetAfterMs` is 50000. The oldest entry would say 10000, and a retry then
   would be denied again (vector `lowered-limit-retry`).
3. Nonce a allowed at 0 s, b at 10 s, a resent at 20 s: allowed, `remaining` 1, `resetAfterMs` 50000, nothing
   changes. Resent at 61 s instead, a has aged out: a new request (`remaining` 1, `resetAfterMs` 60000).

### Loading and calling

The script cache is volatile (restart, failover, `SCRIPT FLUSH`).
- `load()` sends `SCRIPT LOAD <script>` and throws unless the reply is the package's SHA. It never applies the
  failure policy: any error throws, so a wrong password, a missing ACL permission or an unreachable server fails
  the deploy. Call it once at startup.
- `hit()` sends `EVALSHA <sha> 1 <key> <limit> <windowMs> <nonce>`; on `NOSCRIPT` it sends the same call once as
  `EVAL`, with the same nonce. `NOSCRIPT` never reaches the failure policy; a failure of that `EVAL` is classified
  normally. The packages send these commands themselves, so every adapter sends the same sequence.

## Public API

### Results

`hit(identifier)` returns `allowed`, `remaining`, `retryAfterMs`, `resetAfterMs`, `resetAt` (the caller's clock
plus `resetAfterMs`) and `degraded` (the failure policy produced the result; the request may still have been
recorded). `retryAfterSeconds(result)` (PHP: `$result->retryAfterSeconds()`) is `ceil(retryAfterMs / 1000)`, at
least 1 when denied and 0 when allowed, for a 429's `Retry-After`.

### Validation

Validation errors are programming errors: they throw in both modes and never reach the failure policy.

| Input | Rule | TypeScript | PHP |
|---|---|---|---|
| `client` | TS: an ioredis `Redis` (not `Cluster`) whose `options.db` is `undefined` or `0` and whose `options.keyPrefix` is unset or empty. PHP: a Predis `ClientInterface` whose `getConnection()` is a `NodeConnectionInterface` other than `RelayConnection` (no cluster, replication or aggregate) and whose `prefix` option is unset or an empty `KeyPrefixProcessor`; or a phpredis `\Redis` whose `OPT_PREFIX` is unset or empty (read again on the first call when phpredis cannot read options after a failed connect) | `TypeError` | `InvalidArgumentException` |
| `name` | as in "Buckets and keys" | `TypeError` | `InvalidArgumentException` |
| `limit` | integer, 1 to 2^53 - 1 | `TypeError` | `InvalidArgumentException` |
| `windowMs` | integer, 1 to 31536000000 | `TypeError` | `InvalidArgumentException` |
| `failMode` | open or closed, no default | `TypeError` | the `FailMode` enum |
| `onError` | optional function | `TypeError` | `?\Closure` |
| `prefix` | as in "Buckets and keys", default `rsw:` | `TypeError` | `InvalidArgumentException` |
| `identifier` | non-empty string | the promise rejects with `TypeError` | `InvalidArgumentException` |

- Why ioredis database 0 only: ioredis reports a failed `SELECT` only on its `error` event and keeps running
  commands on database 0, so a wrong `db` would silently enforce limits in another database. A client switched with
  `SELECT` at run time is unsupported.
- Why no client key prefix: whether a client prefixes script keys is not documented for every client, and buckets
  must have the same physical key in both languages.
- PHP must be 64-bit (`php-64bit` in composer.json): limits up to 2^53 - 1 and microsecond scores need 64-bit
  integers.

### TypeScript

```ts
import type { Redis } from 'ioredis';

export type FailMode = 'open' | 'closed';
export type UnavailableReason = 'connection' | 'timeout' | 'LOADING' | 'BUSY' | 'MASTERDOWN' | 'READONLY';
/** Built from the reason alone and frozen: message is `Redis or Valkey unavailable: ${reason}`. */
export interface UnavailableReport { readonly reason: UnavailableReason; readonly message: string }
export interface ErrorContext { readonly name: string; readonly failMode: FailMode }
export type OnError = (report: UnavailableReport, context: ErrorContext) => void | Promise<void>;
export interface LimiterOptions {
  readonly client: Redis; readonly name: string; readonly limit: number; readonly windowMs: number;
  readonly failMode: FailMode; readonly onError?: OnError; readonly prefix?: string;
}
export interface LimitResult { /* as src/index.ts */ }
export declare class SlidingWindowLimiter {
  constructor(options: LimiterOptions);
  load(): Promise<void>;
  hit(identifier: string): Promise<LimitResult>;
}
export declare function retryAfterSeconds(result: LimitResult): number;
```

### PHP

```php
namespace GeorgiMorozov\RedisSlidingWindow;

enum FailMode: string { case Open = 'open'; case Closed = 'closed'; }

/** What onError receives. Not a Throwable: no stack trace, no frame arguments. */
final readonly class UnavailableReport
{
    public string $message; // 'Redis or Valkey unavailable: ' . $reason
    /** @param 'connection'|'timeout'|'LOADING'|'BUSY'|'MASTERDOWN'|'READONLY' $reason */
    public function __construct(public string $reason);
}

final readonly class ErrorContext { public function __construct(public string $name, public FailMode $failMode); }

final readonly class Result
{
    public function __construct(public bool $allowed, public int $remaining, public int $retryAfterMs,
        public int $resetAfterMs, public \DateTimeImmutable $resetAt, public bool $degraded);
    public function retryAfterSeconds(): int;
}

final class SlidingWindowLimiter
{
    /** @param null|\Closure(UnavailableReport, ErrorContext): void $onError */
    public function __construct(\Predis\ClientInterface|\Redis $client, string $name, int $limit, int $windowMs,
        FailMode $failMode, ?\Closure $onError = null, string $prefix = 'rsw:');
    public function load(): void;
    public function hit(#[\SensitiveParameter] string $identifier): Result;
}
```

### Laravel

`RedisSlidingWindowServiceProvider` (auto-discovered through `extra.laravel.providers`) merges
`config/redis-sliding-window.php` (`connection`, `prefix`, `limiters`: name => `limit`, `window_ms`, `fail_mode`),
binds `Laravel\Limiters` as a singleton (one limiter per name, on the connection's `client()`) and registers
the route middleware alias `sliding-window`. `sliding-window:<name>` hits once per request with the identifier
`user:<auth id>` when signed in, else `ip:<client IP>`, and throws `ThrottleRequestsException` with `Retry-After`
(Laravel renders a 429) when denied. A degraded result is logged as a warning (`limiter`, `reason`,
`fail_mode`; never the identifier). Validation errors from the config throw. Nothing calls `load()`: the
`NOSCRIPT` fallback loads the script on first use.

## Failure policy

Every error from `hit()`'s script call goes through one stateless rule, the same in both modes:

1. `NOSCRIPT`: the `EVAL` fallback.
2. **A server error reply**, wherever the client delivers it (the call itself, or the client's connection setup:
   `AUTH`, `HELLO`, `SELECT`), classified by its code (its first word): `LOADING`, `BUSY`, `MASTERDOWN` or
   `READONLY` **degrades** (`reason` is the code). Any other code **throws**: `NOAUTH`, `WRONGPASS`, `NOPERM`,
   `WRONGTYPE`, `OOM`, `MISCONF`, `ERR` (script errors included), unknown codes.
3. **No server reply, and a transport failure** from the client's list below: **degrade** (`connection` or
   `timeout`).
4. **Everything else throws**: malformed replies, protocol errors, `TypeError`s, other programming errors.

| Client | Server reply (step 2) | Transport failure (step 3) |
|---|---|---|
| ioredis 6 | `ReplyError`. Calls waiting in the offline queue receive the server's `AUTH` refusal this way | message exactly `Command timed out` (`timeout`); exactly `Connection is closed.`, exactly `Stream isn't writeable and enableOfflineQueue options is false`, or `name === 'MaxRetriesPerRequestError'` (`connection`) |
| Predis 3 | `ServerException`, or a returned `ErrorInterface` with `exceptions` off (code: `getErrorType()`); a `ConnectionException` whose message starts with `Failed: `, which is a setup command's reply (code: the next word) | `TimeoutException` (`timeout`); any other `ConnectionException`, and `StreamInitException` (`connection`), except a `StreamInitException` starting `Error while setting SSL context options`, a client configuration error, which throws |
| phpredis 6.3+ | The package calls `clearLastError()` before each call and sends commands with `rawCommand()` (no prefix, serializer or compression). A `RedisException` or `false` while `getLastError()` holds a string is that reply. That includes `AUTH failed while reconnecting` / `SELECT failed while reconnecting` when phpredis recorded the server's refusal | a `RedisException` while `getLastError()` is null, unless its message starts with `protocol error` (`connection`). That includes the two reconnect messages when the socket failed during `AUTH` or `SELECT`, and a client whose connect failed (phpredis then throws from `getLastError()` itself). phpredis before 6.3 does not record a refused reconnect `AUTH`, so there it degrades |

A reply that must throw but came back as a value (Predis `ErrorInterface`, phpredis `false`) is thrown as the
client's own exception (`Predis\Response\ServerException`, `RedisException`) with the reply as its message.

**Observed rejections only.** A credential or configuration refusal throws when the call receives the server's
reply. A transport failure the client raises first degrades: ioredis with `enableOfflineQueue: false` before it is
ready, or a timeout or retry limit while ioredis reconnects with rejected credentials. ioredis reports every
rejected `AUTH` on its `error` event, and `load()` at startup throws on any of them (with the offline queue off,
await the client's `ready` event before `load()`).

### Degraded results

| Field | fail open | fail closed |
|---|---|---|
| `allowed` | true | false |
| `remaining` | `limit` | 0 |
| `retryAfterMs` | 0 | `windowMs` |
| `resetAfterMs` | `windowMs` | `windowMs` |
| `degraded` | true | true |

### The callback

- `onError(report, context)` runs once per degraded result, before it is returned. `report` is exactly
  `{ reason, message }` and `context` exactly `{ name, failMode }`, both built by the library. Neither holds the
  identifier or anything from the client's error (message, class, command, arguments, host).
- PHP `hit()` marks `$identifier` `#[\SensitiveParameter]`, so a trace through `hit()` shows
  `Object(SensitiveParameterValue)`. Deeper frames (the client's own methods) can still hold the key.
- Errors `hit()` throws are the client's own, unchanged (an ioredis `ReplyError` carries the key in
  `command.args`). Handle them like any other client error.
- A callback that throws, or (TypeScript) returns a promise that rejects, never changes the result. TypeScript
  attaches a rejection handler without awaiting and emits a process warning, code `RSW_ON_ERROR_FAILED`, as a string
  with the callback error's message (`(unreadable error)` when reading it throws). PHP writes `redis-sliding-window: onError callback failed: <class>: <message>`
  with `error_log()`.
- Not throttled: during an outage it runs on every call. Throttle inside it (for example one report a minute per
  limiter name).

### Choosing a mode

Fail open when the limit protects fairness or capacity (API quotas, per-user burst caps): a limiter outage should
not become a product outage, and the worst case is some unthrottled traffic. Fail closed when a flood would be
harmful and the caller is unauthenticated: email or SMS sends (sign-in links, password resets), contact forms,
sign-up, guessable secrets.

### Indeterminate outcomes

A timeout or a connection lost after the command went out does not tell whether the script ran: the result is
degraded, and the request may still count for `windowMs`. Client resends (ioredis `autoResendUnfulfilledCommands`,
default on) and the `NOSCRIPT` fallback carry the same nonce, so they count once while the entry remains. A new
`hit()` after a degraded one is a new request.

## Connections

The library never creates, connects or closes a client. Pass one long-lived client per process and call `load()`
once at startup.
- ioredis: one client at module level; set `commandTimeout` (for example 1000 ms) and a low `maxRetriesPerRequest`
  (for example 1); listen for `error` events. Either offline-queue setting works.
- PHP-FPM: persistent connections with short timeouts. Predis: `'persistent' => true` (or a string id per pool),
  `'timeout' => 1.0` (connect), `'read_write_timeout' => 1.0`. phpredis: `pconnect($host, $port, 1.0,
  'rate-limit', 0, 1.0)` (connect timeout, `persistent_id`, retry interval, read timeout). Laravel: a dedicated
  connection with `'prefix' => ''`, `'persistent' => true`, `'timeout' => 1.0` and `'read_timeout' => 1.0`
  (phpredis) or `'read_write_timeout' => 1.0` (Predis); see `docs/php.md`.
- A fresh connection per request, especially a fresh TLS connection, is a known failure mode: every request pays the
  handshake and every network blip, and under load the handshakes exhaust the server's or proxy's connection
  capacity.

## Operating notes

- Memory: about 100 bytes per allowed request per key, held for `windowMs`; for limits of many thousands per
  window, consider a counter-based algorithm.
- Eviction: keys have TTLs, so `volatile-*` policies evict limiter keys first, which resets those limits. Use
  `noeviction` if limits must hold under memory pressure.
- Durability: replication is asynchronous; a failover can lose acknowledged entries (`WAIT` reduces but cannot rule
  this out). v1 adds no durability mechanism.
- Clock: a forward jump of J ms ages entries out J ms early (up to `limit` extra requests, once). A backward jump, or
  a failover to a primary J ms behind, keeps earlier entries counting up to J ms longer; reported durations are
  clamped, so a retry at `retryAfterMs` can be denied again.

## Supported versions

| | Declared | Tested by the gate |
|---|---|---|
| Node.js | >=22 (`engines`) | unit on 22, 24, 26 (the LTS lines); everything else on 24 |
| PHP | ^8.3, 64-bit | unit on 8.3, 8.4, 8.5; everything else on 8.4. PHPUnit 12 because it supports 8.3 |
| ioredis | ^6 (peer) | the locked version |
| Predis | ^3.7 (suggest) | the locked version |
| phpredis | 6.3+ (suggest) | 6.3.0, in every PHP test image |
| Laravel | not required (suggest) | the locked version (13) through Orchestra Testbench |
| Servers | Redis 7.2+, Valkey 7.2+ | integration and vectors on Valkey 9.1, Valkey 7.2 and Redis 7.2; smoke on Valkey 9.1 |

## Test plan

Levels: unit (no server: Vitest under `tests/ts/unit`, PHPUnit `unit`); feature (every vector through each
package's public API on a real server, from RSW-2/RSW-3; run with the integration tests, `tests/ts/feature` for TypeScript); integration (real-server
behavior beyond the vectors); repository-script tests (`tests/scripts/`); smoke (from RSW-2, the gate's `smoke`
runs consumer scenario scripts, indexed in `docs/smoke-tests.md`). Integration tests read `REDIS_HOST` and
`REDIS_PORT` (default 127.0.0.1:6379) and fail without a server.

### Vector format

One JSON file per vector, named after its `id` (kebab-case): `{ "id", "description", "config": { "limit",
"windowMs" }, "steps": [...] }`, no other properties, limits as in "Validation". Steps run in order against one
limiter and one identifier:
- `{ "op": "hit", "expect": {...} }`: one `hit()`. `expect` holds at least one of `allowed`, `remaining`,
  `retryAfterMs`, `resetAfterMs`. A number matches exactly, `{ "min", "max" }` is an inclusive range with min <= max,
  and an absent field is not checked. Every result must also have `degraded` false.
- `{ "op": "seed", "agesMs": [...] }`: one entry per age straight into the bucket (score `now - age * 1000`,
  member `seed-<index>`), in one script that reads `TIME` itself, then `PEXPIRE` `windowMs`.
- `{ "op": "sleep", "ms": n }`: wait n ms (1 to 5000).

Runners use the name `vec-<id>-<8 hex>`, prefix `rsw-vectors:`, identifier `vector`, fail mode closed, and delete
the key afterwards. Time-dependent ranges leave at least 200 ms for latency. `tests/ts/unit/vectors.test.ts` checks
every file against this format.

### Acceptance outcomes for RSW-2 (TypeScript) and RSW-3 (Predis, phpredis)

Unit:
- Every validation rule fails if its check is removed, including: prefix `a:` + name `5` rejected; prefix `a::`
  rejected; ioredis `db: 1` and `db: 99` rejected; a non-empty ioredis `keyPrefix` rejected; Predis `prefix` and
  phpredis `OPT_PREFIX` rejected. Key building.
- Every reply-validation rule: a malformed reply throws in both modes; a replay-shaped reply is accepted.
- Classification in both modes: the four codes and each transport kind degrade. These throw: `NOAUTH`,
  `WRONGPASS`, `NOPERM`, `WRONGTYPE`, `OOM`, `ERR`, a malformed reply, a client `TypeError`. Predis: `Failed:
  WRONGPASS …` and `Failed: ERR DB index is out of range …` throw, `Failed: LOADING …` degrades, and the
  SSL-context `StreamInitException` throws. phpredis: each reconnect message throws with a recorded `WRONGPASS`
  and degrades with no recorded reply.
- `NOSCRIPT`: one `EVAL` with the same nonce; a failure of that `EVAL` is classified normally.
- `load()`: `SCRIPT LOAD` with the file's bytes; a different SHA throws; every client error, an outage included,
  throws in both modes without the callback.
- Degraded results per the table. The callback runs once, with `report` strictly equal to `{ reason, message }`
  and `context` to `{ name, failMode }` (PHP: not a `Throwable`, `get_object_vars` exactly those keys), so any added
  property fails. A throwing or rejecting callback still yields the degraded result and the warning, with no
  unhandled rejection. PHP `hit()`'s `$identifier` carries `SensitiveParameter` (Reflection).
- `retryAfterSeconds`.

Feature: every vector in `tests/vectors/`, per package.

Integration (real server):
- 40 concurrent hits with limit 7 allow exactly 7 and leave 7 members (TypeScript over several connections, PHP
  over separate processes).
- An entry exactly `windowMs` old is pruned and one 1 ms younger counts; a denied call leaves the TTL unchanged.
- Replay: counts once within the window (members, score and TTL unchanged; `resetAfterMs` from the newest entry);
  in a full bucket the replay is allowed and a new nonce denied; after expiry a resend is a new request.
- `load()` leaves the SHA cached (`SCRIPT EXISTS`); after `SCRIPT FLUSH`, `hit()` succeeds through `EVAL`.
- `BUSY` and `READONLY` degrade; `WRONGTYPE` throws.
- Outages degrade in both modes with the callback: a refused port, a connect timeout, a lost reply (paused server).
  Fail open returns allowed.
- 200 hits on one client raise the server's `total_connections_received` by at most 1.
- Credentials (`requirepass`): a wrong password throws from `hit()` and `load()` in both modes, with no callback.
  phpredis: a reconnect refused by the server's reply throws; a reconnect whose connection is lost while waiting
  for the `AUTH` reply, or the `SELECT` reply, degrades in both modes.
- Database: ioredis `db` other than 0 never reaches the server; Predis `database` 99 throws in both modes; no
  limiter call succeeds against another database.

Smoke: RSW-2 adds the gate's `smoke` command (`scripts/docker-test.sh smoke`) and the consumer scenario scripts in
`tests/smoke/`, run from the packed tarball; RSW-3 adds `tests/smoke/php/`, run from the `composer archive` of the package.
