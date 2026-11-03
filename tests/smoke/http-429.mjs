// Smoke: a consumer's HTTP server answers 429 with Retry-After once the limit is reached.
// Runs from a directory where the packed tarball and ioredis are installed (tests/smoke/run-consumer.sh).
import assert from 'node:assert/strict';
import console from 'node:console';
import { randomBytes } from 'node:crypto';
import { createServer } from 'node:http';
import process from 'node:process';
import { Redis } from 'ioredis';
import { retryAfterSeconds, SlidingWindowLimiter } from 'redis-sliding-window';

const redis = new Redis({
  host: process.env.REDIS_HOST || '127.0.0.1',
  port: Number(process.env.REDIS_PORT) || 6379,
  commandTimeout: 1000,
  maxRetriesPerRequest: 1,
});
redis.on('error', (error) => console.error('redis error:', error.message));

const limiter = new SlidingWindowLimiter({
  client: redis,
  name: `smoke-${randomBytes(4).toString('hex')}`,
  limit: 2,
  windowMs: 60_000,
  failMode: 'closed',
  prefix: 'rsw-smoke:',
});
await limiter.load();

const server = createServer(async (request, response) => {
  const result = await limiter.hit(request.socket.remoteAddress ?? 'unknown');
  if (!result.allowed) {
    response.writeHead(429, { 'Retry-After': String(retryAfterSeconds(result)) });
    response.end('Too Many Requests');
    return;
  }
  response.writeHead(200, { 'RateLimit-Remaining': String(result.remaining) });
  response.end('ok');
});
await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
const url = `http://127.0.0.1:${server.address().port}/`;

try {
  const statuses = [];
  let retryAfter = null;
  for (let i = 0; i < 3; i += 1) {
    const response = await globalThis.fetch(url);
    statuses.push(response.status);
    if (response.status === 429) retryAfter = response.headers.get('retry-after');
    await response.text();
  }
  assert.deepEqual(statuses, [200, 200, 429]);
  assert.match(retryAfter ?? '', /^\d+$/);
  assert.ok(Number(retryAfter) >= 59 && Number(retryAfter) <= 60, `Retry-After ${retryAfter}`);
  console.log(`http-429: statuses ${statuses.join(', ')}; Retry-After ${retryAfter}`);
} finally {
  server.close();
  redis.disconnect();
}
