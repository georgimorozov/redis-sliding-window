# redis-sliding-window

A small, hardened sliding-window rate limiter for Redis and Valkey: one atomic Lua script, with TypeScript and
PHP packages that share it.

Status: in development; the first release (v1.0.0) is planned (`docs/roadmap.md`). The contract (bucket keys, the
script's interface, both APIs, the failure policy and the test plan) is `docs/design.md`.

## Architecture

```mermaid
flowchart LR
  app_ts["Node.js app"] --> ts["TypeScript package (src/)"]
  app_php["PHP app"] --> php["PHP package (php/src/)"]
  ts -->|"ioredis: SCRIPT LOAD at startup, EVALSHA, EVAL on NOSCRIPT"| server[("Redis or Valkey")]
  app_laravel["Laravel app"] -->|"sliding-window:name middleware"| laravel["Laravel integration (php/src/Laravel/)"]
  laravel -->|"Limiters registry, config/redis-sliding-window.php"| php
  php -->|"Predis or phpredis: SCRIPT LOAD at startup, EVALSHA, EVAL on NOSCRIPT"| server
  lua["lua/sliding-window.lua"] -.->|"same script text"| ts
  lua -.->|"same script text"| php
  vectors["tests/vectors/"] -.->|"same conformance cases"| ts
  vectors -.-> php
```

The application passes its own long-lived client; the library never opens connections. Each call runs the script
once: it reads the server's clock, drops entries older than the window, and either records the request or reports
when to retry. If the server cannot answer, the limiter fails open or closed, as the caller chose, and reports the
outage through the caller's callback; a server reply that rejects the credentials or the configuration throws.

The script, the TypeScript package (`SlidingWindowLimiter`, `retryAfterSeconds`) and the PHP package (`SlidingWindowLimiter`
for Predis and phpredis, plus a Laravel middleware and auto-discovered service provider, `docs/php.md`) are
implemented; full usage documentation comes with the first release (RSW-4, RSW-5).

License: MIT.
