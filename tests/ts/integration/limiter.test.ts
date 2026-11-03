import process from 'node:process';
import type { Redis } from 'ioredis';
import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest';
import { SlidingWindowLimiter, type FailMode, type LimiterOptions } from '../../../src/index.js';
import { SCRIPT, SCRIPT_SHA } from '../../../src/script.js';
import { connect, hex, host, port, sleep } from '../support/server.js';

// Real-server behavior beyond the vectors (docs/design.md, "Acceptance outcomes for RSW-2", integration).
// Some tests change server-wide state (SCRIPT FLUSH, CLIENT PAUSE, REPLICAOF, busy-reply-threshold), so
// integration files run one at a time (package.json test:integration, --no-file-parallelism).
const MODES: FailMode[] = ['open', 'closed'];

// The script with TIME replaced by ARGV[4] (seconds) and ARGV[5] (microseconds): a fixed clock for the
// boundary tests. The assertion keeps the replacement honest if the script changes.
const TIME_CALL = "redis.call('TIME')";
const FIXED_CLOCK_SCRIPT = SCRIPT.replace(TIME_CALL, '{ARGV[4], ARGV[5]}');

let admin: Redis;
const clients: Redis[] = [];
function client(options: Parameters<typeof connect>[0] = {}): Redis {
  const created = connect(options);
  // ioredis reports connection failures on 'error'; the tests assert on hit() results instead.
  created.on('error', () => undefined);
  clients.push(created);
  return created;
}

function limiter(overrides: Partial<LimiterOptions> = {}) {
  return new SlidingWindowLimiter({
    client: overrides.client ?? admin,
    name: `it-${hex(4)}`,
    limit: 3,
    windowMs: 60000,
    failMode: 'closed',
    prefix: 'rsw-itest:',
    ...overrides,
  });
}

const keyOf = (name: string, windowMs: number, id: string) => `rsw-itest:${name}:${windowMs}:${id}`;
const runScript = (script: string, key: string, ...args: (string | number)[]) =>
  admin.eval(script, 1, key, ...args.map(String)) as Promise<number[]>;

beforeAll(() => {
  admin = connect();
});

afterAll(() => {
  for (const created of clients) created.disconnect();
  admin.disconnect();
});

describe(`the limiter against ${host}:${port}`, () => {
  it('allows exactly 7 of 40 concurrent hits over several connections, leaving 7 members', async () => {
    const pool = Array.from({ length: 8 }, () => client());
    const name = `burst-${hex(4)}`;
    const limiters = pool.map((c) =>
      limiter({ client: c, name, limit: 7, windowMs: 60000, failMode: 'closed' }),
    );
    await limiters[0]!.load();
    const results = await Promise.all(
      Array.from({ length: 40 }, (_, i) => limiters[i % limiters.length]!.hit('burst')),
    );
    expect(results.filter((r) => r.allowed)).toHaveLength(7);
    expect(results.every((r) => !r.degraded)).toBe(true);
    expect(await admin.zcard(keyOf(name, 60000, 'burst'))).toBe(7);
  });

  it('prunes an entry exactly windowMs old and counts one 1 ms younger', async () => {
    expect(FIXED_CLOCK_SCRIPT).not.toBe(SCRIPT);
    const key = `rsw-itest:boundary-${hex(4)}:1000:x`;
    const now = 1_800_000_000_000_000; // a fixed server time in microseconds
    await admin.zadd(key, now - 1_000_000, 'exactly-window-old', now - 999_000, 'one-ms-younger');
    await admin.pexpire(key, 60000);
    const seconds = Math.floor(now / 1_000_000);
    const reply = await runScript(FIXED_CLOCK_SCRIPT, key, 1, 1000, hex(16), seconds, 0);
    expect(reply).toEqual([0, 0, 1, 1]);
    expect(await admin.zrange(key, '0', '-1')).toEqual(['one-ms-younger']);
  });

  it('the key expires no earlier than its newest entry ages out, at microsecond precision', async () => {
    // A 1 ms window with the entry scored late in its millisecond: a relative millisecond expiry from the
    // command's own clock could end before the entry's window does.
    const key = `rsw-itest:pexpireat-${hex(4)}:1:x`;
    const seconds = 1_800_000_000; // a fixed server time in the future, so the key is not already expired
    const reply = await runScript(FIXED_CLOCK_SCRIPT, key, 1, 1, hex(16), seconds, 999_999);
    expect(reply).toEqual([1, 0, 0, 1]);
    const scoreUs = seconds * 1_000_000 + 999_999;
    const expiresAtMs = Number(await admin.call('PEXPIRETIME', key));
    expect(expiresAtMs * 1000).toBeGreaterThanOrEqual(scoreUs + 1000);
    expect(expiresAtMs).toBe(Math.ceil(scoreUs / 1000) + 1);
  });

  it('a denied call leaves the TTL unchanged', async () => {
    const name = `ttl-${hex(4)}`;
    const l = limiter({ name, limit: 1 });
    expect((await l.hit('x')).allowed).toBe(true);
    const key = keyOf(name, 60000, 'x');
    await admin.pexpire(key, 30000);
    expect((await l.hit('x')).allowed).toBe(false);
    const ttl = await admin.pttl(key);
    expect(ttl).toBeLessThanOrEqual(30000);
    expect(ttl).toBeGreaterThan(25000);
  });

  it('every key expires windowMs after its newest entry', async () => {
    const name = `expiry-${hex(4)}`;
    const l = limiter({ name, windowMs: 300 });
    await l.hit('x');
    const key = keyOf(name, 300, 'x');
    expect(await admin.pttl(key)).toBeGreaterThan(0);
    expect(await admin.pttl(key)).toBeLessThanOrEqual(301); // rounded up to the next millisecond
    await sleep(400);
    expect(await admin.exists(key)).toBe(0);
  });

  describe('replay (the same nonce)', () => {
    it('counts once within the window: members, score and TTL unchanged', async () => {
      const key = `rsw-itest:replay-${hex(4)}:60000:x`;
      const a = hex(16);
      const b = hex(16);
      expect(await runScript(SCRIPT, key, 3, 60000, a)).toEqual([1, 2, 0, 60000]);
      await sleep(50);
      expect(await runScript(SCRIPT, key, 3, 60000, b)).toEqual([1, 1, 0, 60000]);
      const before = await admin.zrange(key, '0', '-1', 'WITHSCORES');
      await admin.pexpire(key, 50000);
      const replay = await runScript(SCRIPT, key, 3, 60000, a);
      expect(replay.slice(0, 3)).toEqual([1, 1, 0]);
      // resetAfterMs comes from the newest entry (b), not the full window.
      expect(replay[3]).toBeLessThanOrEqual(60000);
      expect(replay[3]).toBeGreaterThan(59000);
      expect(await admin.zrange(key, '0', '-1', 'WITHSCORES')).toEqual(before);
      expect(await admin.pttl(key)).toBeLessThanOrEqual(50000);
    });

    it('in a full bucket a replay is allowed and a new nonce denied', async () => {
      const key = `rsw-itest:replay-full-${hex(4)}:60000:x`;
      const a = hex(16);
      await runScript(SCRIPT, key, 2, 60000, a);
      await runScript(SCRIPT, key, 2, 60000, hex(16));
      expect((await runScript(SCRIPT, key, 2, 60000, a)).slice(0, 3)).toEqual([1, 0, 0]);
      expect((await runScript(SCRIPT, key, 2, 60000, hex(16)))[0]).toBe(0);
      expect(await admin.zcard(key)).toBe(2);
    });

    it('after expiry a resend is a new request', async () => {
      const key = `rsw-itest:replay-expired-${hex(4)}:300:x`;
      const a = hex(16);
      await runScript(SCRIPT, key, 2, 300, a);
      await sleep(400);
      expect(await runScript(SCRIPT, key, 2, 300, a)).toEqual([1, 1, 0, 300]);
      expect(await admin.zcard(key)).toBe(1);
    });
  });

  it('load() leaves the SHA cached; after SCRIPT FLUSH, hit() succeeds through EVAL', async () => {
    const l = limiter();
    await l.load();
    expect(await admin.script('EXISTS', SCRIPT_SHA)).toEqual([1]);
    await admin.script('FLUSH');
    expect(await admin.script('EXISTS', SCRIPT_SHA)).toEqual([0]);
    expect(await l.hit('x')).toMatchObject({ allowed: true, degraded: false });
    // EVAL caches the script again.
    expect(await admin.script('EXISTS', SCRIPT_SHA)).toEqual([1]);
  });

  it('WRONGTYPE throws in both modes without the callback', async () => {
    for (const failMode of MODES) {
      const name = `wrongtype-${hex(4)}`;
      await admin.set(keyOf(name, 60000, 'x'), 'not a sorted set', 'PX', 60000);
      const onError = vi.fn();
      const l = limiter({ name, failMode, onError });
      await expect(l.hit('x')).rejects.toThrow(/^WRONGTYPE/);
      expect(onError).not.toHaveBeenCalled();
    }
  });

  it('BUSY degrades', async () => {
    const original = (await admin.config('GET', 'busy-reply-threshold')) as string[];
    await admin.config('SET', 'busy-reply-threshold', '100');
    try {
      for (const failMode of MODES) {
        const busy = client({ commandTimeout: 5000 });
        const running = busy.eval(
          `local s = redis.call('TIME')
           repeat local t = redis.call('TIME')
           until (t[1] - s[1]) * 1000000 + (t[2] - s[2]) > 1000000
           return 1`,
          0,
        );
        await sleep(300);
        const onError = vi.fn();
        const l = limiter({ client: client(), failMode, onError });
        expect(await l.hit('x')).toMatchObject({ allowed: failMode === 'open', degraded: true });
        expect(onError).toHaveBeenCalledWith(
          { reason: 'BUSY', message: 'Redis or Valkey unavailable: BUSY' },
          expect.objectContaining({ failMode }),
        );
        await running;
      }
    } finally {
      await admin.config('SET', 'busy-reply-threshold', original[1] ?? '5000');
    }
  });

  it('READONLY degrades', async () => {
    // A replica of an unreachable primary rejects writes; it never syncs, so no data is replaced.
    await admin.call('REPLICAOF', '127.0.0.1', '1');
    try {
      for (const failMode of MODES) {
        const onError = vi.fn();
        const l = limiter({ client: client(), failMode, onError });
        expect(await l.hit('x')).toMatchObject({ allowed: failMode === 'open', degraded: true });
        expect(onError.mock.calls[0]?.[0]).toEqual({
          reason: 'READONLY',
          message: 'Redis or Valkey unavailable: READONLY',
        });
      }
    } finally {
      await admin.call('REPLICAOF', 'NO', 'ONE');
    }
  });

  describe('outages degrade in both modes, with the callback', () => {
    it.each(MODES)('a refused port (fail %s)', async (failMode) => {
      const onError = vi.fn();
      const l = limiter({ client: client({ port: 1 }), failMode, onError });
      const result = await l.hit('x');
      expect(result).toMatchObject({ allowed: failMode === 'open', degraded: true });
      expect(onError).toHaveBeenCalledTimes(1);
      expect(onError.mock.calls[0]?.[0]).toMatchObject({ reason: 'connection' });
    });

    it.each(MODES)('a connect timeout (fail %s)', async (failMode) => {
      const onError = vi.fn();
      // 192.0.2.0/24 is TEST-NET-1 (RFC 5737): nothing answers there.
      const c = client({ host: '192.0.2.1', connectTimeout: 300, commandTimeout: 1000 });
      const l = limiter({ client: c, failMode, onError });
      expect(await l.hit('x')).toMatchObject({ allowed: failMode === 'open', degraded: true });
      expect(onError).toHaveBeenCalledTimes(1);
      expect(['connection', 'timeout']).toContain(onError.mock.calls[0]?.[0].reason);
    });

    it.each(MODES)('a lost reply: paused server (fail %s)', async (failMode) => {
      const onError = vi.fn();
      const c = client({ commandTimeout: 300 });
      await c.ping();
      const l = limiter({ client: c, failMode, onError });
      await admin.call('CLIENT', 'PAUSE', '1000', 'ALL');
      try {
        expect(await l.hit('x')).toMatchObject({ allowed: failMode === 'open', degraded: true });
        expect(onError.mock.calls[0]?.[0]).toMatchObject({ reason: 'timeout' });
      } finally {
        await sleep(1100);
      }
    });
  });

  it('200 hits on one client raise total_connections_received by at most 1', async () => {
    const connections = async () => {
      const info = await admin.info('stats');
      return Number(/total_connections_received:(\d+)/.exec(info)?.[1]);
    };
    const before = await connections();
    const l = limiter({ client: client(), limit: 1000 });
    for (let i = 0; i < 200; i += 1) await l.hit('x');
    expect((await connections()) - before).toBeLessThanOrEqual(1);
  });

  describe('credentials', () => {
    const user = `rsw-itest-${hex(4)}`;

    beforeAll(async () => {
      await admin.call('ACL', 'SETUSER', user, 'on', '>right-password', '~*', '+@all');
    });

    afterAll(async () => {
      await admin.call('ACL', 'DELUSER', user);
    });

    it.each(MODES)(
      'a wrong password throws from hit() and load() (fail %s), no callback',
      async (failMode) => {
        const onError = vi.fn();
        const warned = vi.spyOn(process, 'emitWarning');
        for (const call of ['hit', 'load'] as const) {
          const c = client({
            username: user,
            password: 'wrong-password',
            retryStrategy: () => null,
          });
          const l = limiter({ client: c, failMode, onError });
          const run = call === 'hit' ? l.hit('x') : l.load();
          await expect(run).rejects.toThrow(/WRONGPASS/);
        }
        expect(onError).not.toHaveBeenCalled();
        warned.mockRestore();
      },
    );

    it('the right password works', async () => {
      const c = client({ username: user, password: 'right-password' });
      const l = limiter({ client: c });
      await l.load();
      expect(await l.hit('x')).toMatchObject({ allowed: true, degraded: false });
    });
  });

  describe('databases', () => {
    it('an ioredis client on another database never reaches the server', () => {
      for (const db of [1, 99]) {
        const c = client({ db, lazyConnect: true });
        const call = vi.spyOn(c, 'call');
        expect(() => limiter({ client: c })).toThrow(TypeError);
        expect(call).not.toHaveBeenCalled();
        expect(c.status).toBe('wait');
      }
    });
  });
});
