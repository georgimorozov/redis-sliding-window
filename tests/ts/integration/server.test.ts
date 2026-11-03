import { randomBytes } from 'node:crypto';
import { Redis } from 'ioredis';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';

// The server: REDIS_HOST and REDIS_PORT, default 127.0.0.1:6379. These tests need one and fail without
// it (scripts/docker-test.sh integration starts Valkey). Keys expire on their own after 60 s.
const host = process.env.REDIS_HOST || '127.0.0.1';
const port = Number(process.env.REDIS_PORT) || 6379;

// The design's clock assumption: a script may read the server's TIME and then write.
const TIME_THEN_WRITE = `
local t = redis.call('TIME')
redis.call('SET', KEYS[1], t[1], 'PX', 60000)
return t
`;

describe(`Redis or Valkey at ${host}:${port}`, () => {
  let client: Redis;

  beforeAll(() => {
    client = new Redis({
      host,
      port,
      connectTimeout: 2000,
      commandTimeout: 2000,
      maxRetriesPerRequest: 1,
      retryStrategy: () => null,
    });
  });

  afterAll(() => {
    client.disconnect();
  });

  it('answers PING', async () => {
    expect(await client.ping()).toBe('PONG');
  });

  it('lets a script read the server TIME and then write', async () => {
    const key = `rsw-itest:time:${randomBytes(8).toString('hex')}`;
    const reply = await client.eval(TIME_THEN_WRITE, 1, key);
    expect(Array.isArray(reply)).toBe(true);
    const [seconds, micros] = reply as [string, string];
    expect(Number(seconds)).toBeGreaterThan(1_700_000_000);
    expect(Number(micros)).toBeGreaterThanOrEqual(0);
    expect(Number(micros)).toBeLessThan(1_000_000);
    expect(await client.get(key)).toBe(seconds);
  });
});
