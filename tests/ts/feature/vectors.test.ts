import { readdirSync, readFileSync } from 'node:fs';
import type { Redis } from 'ioredis';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { SlidingWindowLimiter, type LimitResult } from '../../../src/index.js';
import { connect, hex, sleep } from '../support/server.js';

// Feature level: every shared vector (tests/vectors, docs/design.md "Vector format") through the public
// API on a real server. tests/ts/unit/vectors.test.ts validates the files' format.
type Range = { min: number; max: number };
type Expect = Partial<Record<'remaining' | 'retryAfterMs' | 'resetAfterMs', number | Range>> & {
  allowed?: boolean;
};
type Step =
  { op: 'hit'; expect: Expect } | { op: 'seed'; agesMs: number[] } | { op: 'sleep'; ms: number };
interface Vector {
  id: string;
  description: string;
  config: { limit: number; windowMs: number };
  steps: Step[];
}

// One script reads TIME and adds one entry per age (score now - age * 1000, member seed-<index>).
const SEED = `
local t = redis.call('TIME')
local now = tonumber(t[1]) * 1000000 + tonumber(t[2])
for i = 2, #ARGV do
  redis.call('ZADD', KEYS[1], string.format('%d', now - tonumber(ARGV[i]) * 1000), 'seed-' .. (i - 2))
end
redis.call('PEXPIRE', KEYS[1], ARGV[1])
return #ARGV - 1
`;

const dir = new URL('../../vectors/', import.meta.url);
const vectors: Vector[] = readdirSync(dir)
  .filter((file) => file.endsWith('.json'))
  .sort()
  .map((file) => JSON.parse(readFileSync(new URL(file, dir), 'utf8')) as Vector);

function check(result: LimitResult, expected: Expect): void {
  expect(result.degraded).toBe(false);
  if (expected.allowed !== undefined) expect(result.allowed).toBe(expected.allowed);
  for (const field of ['remaining', 'retryAfterMs', 'resetAfterMs'] as const) {
    const want = expected[field];
    if (want === undefined) continue;
    if (typeof want === 'number') {
      expect(result[field], field).toBe(want);
    } else {
      expect(result[field], field).toBeGreaterThanOrEqual(want.min);
      expect(result[field], field).toBeLessThanOrEqual(want.max);
    }
  }
}

describe('shared vectors', () => {
  let client: Redis;

  beforeAll(() => {
    client = connect();
  });

  afterAll(() => {
    client.disconnect();
  });

  it('has vectors to run', () => {
    expect(vectors.length).toBeGreaterThan(0);
  });

  it.each(vectors.map((vector) => [vector.id, vector] as const))('%s', async (_id, vector) => {
    const name = `vec-${vector.id}-${hex(4)}`;
    const prefix = 'rsw-vectors:';
    const limiter = new SlidingWindowLimiter({
      client,
      name,
      prefix,
      failMode: 'closed',
      ...vector.config,
    });
    await limiter.load();
    const key = `${prefix}${name}:${vector.config.windowMs}:vector`;
    try {
      for (const step of vector.steps) {
        if (step.op === 'hit') check(await limiter.hit('vector'), step.expect);
        else if (step.op === 'seed') {
          await client.eval(
            SEED,
            1,
            key,
            String(vector.config.windowMs),
            ...step.agesMs.map(String),
          );
        } else await sleep(step.ms);
      }
    } finally {
      await client.del(key);
    }
  });
});
