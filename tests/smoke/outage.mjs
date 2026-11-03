// Smoke: with the server unreachable, fail open lets the request through and fail closed denies it,
// and both report through onError without the identifier.
import assert from 'node:assert/strict';
import console from 'node:console';
import { Redis } from 'ioredis';
import { SlidingWindowLimiter } from 'redis-sliding-window';

// Port 1 refuses connections: an outage as the consumer sees it.
const redis = new Redis({
  host: '127.0.0.1',
  port: 1,
  commandTimeout: 1000,
  maxRetriesPerRequest: 1,
  retryStrategy: () => null,
});
redis.on('error', () => undefined);

for (const failMode of ['open', 'closed']) {
  const reports = [];
  const limiter = new SlidingWindowLimiter({
    client: redis,
    name: 'smoke-outage',
    limit: 5,
    windowMs: 1000,
    failMode,
    onError: (report, context) => reports.push({ report, context }),
  });
  const result = await limiter.hit('user-42');
  assert.equal(result.degraded, true);
  assert.equal(result.allowed, failMode === 'open');
  assert.equal(reports.length, 1);
  assert.deepEqual(reports[0].report, {
    reason: 'connection',
    message: 'Redis or Valkey unavailable: connection',
  });
  assert.deepEqual(reports[0].context, { name: 'smoke-outage', failMode });
  assert.ok(!JSON.stringify(reports).includes('user-42'));
  console.log(
    `outage: fail ${failMode} -> allowed ${result.allowed}, reason ${reports[0].report.reason}`,
  );
}
redis.disconnect();
