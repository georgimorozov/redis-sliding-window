import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import process from 'node:process';
import { ReplyError } from 'ioredis';
import type { Redis } from 'ioredis';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
  retryAfterSeconds,
  SlidingWindowLimiter,
  type LimiterOptions,
  type LimitResult,
} from '../../../src/index.js';
import { SCRIPT, SCRIPT_SHA } from '../../../src/script.js';

// Unit tests with a fake ioredis client: validation, key building, reply validation, the failure policy
// and the callback (docs/design.md, "Acceptance outcomes for RSW-2").
const LUA = readFileSync(new URL('../../../lua/sliding-window.lua', import.meta.url), 'utf8');

type Call = (command: string, ...args: (string | number)[]) => Promise<unknown>;
function fakeClient(call: Call = async () => [1, 2, 0, 60000], options: object = {}) {
  const spy = vi.fn(call);
  const client = { isCluster: false, options: { ...options }, call: spy } as unknown as Redis;
  return { client, call: spy };
}

function limiter(overrides: Partial<Record<keyof LimiterOptions, unknown>> = {}, call?: Call) {
  const fake = fakeClient(call);
  const options = {
    client: fake.client,
    name: 'login',
    limit: 3,
    windowMs: 60000,
    failMode: 'closed',
    ...overrides,
  } as LimiterOptions;
  return { limiter: new SlidingWindowLimiter(options), ...fake };
}

const replyError = (message: string): Error => new ReplyError(message) as Error;
const ERRORS: Record<string, () => Error> = {
  LOADING: () => replyError('LOADING Valkey is loading the dataset in memory'),
  BUSY: () => replyError('BUSY Valkey is busy running a script.'),
  MASTERDOWN: () => replyError('MASTERDOWN Link with MASTER is down'),
  READONLY: () => replyError("READONLY You can't write against a read only replica."),
  timeout: () => new Error('Command timed out'),
  closed: () => new Error('Connection is closed.'),
  offline: () => new Error("Stream isn't writeable and enableOfflineQueue options is false"),
  maxRetries: () => {
    const error = new Error('Reached the max retries per request limit (which is 1).');
    Object.defineProperty(error, 'name', { value: 'MaxRetriesPerRequestError' });
    return error;
  },
};
const REASONS: Record<string, string> = {
  LOADING: 'LOADING',
  BUSY: 'BUSY',
  MASTERDOWN: 'MASTERDOWN',
  READONLY: 'READONLY',
  timeout: 'timeout',
  closed: 'connection',
  offline: 'connection',
  maxRetries: 'connection',
};
const THROWING: [string, () => unknown][] = [
  ['NOAUTH', () => replyError('NOAUTH Authentication required.')],
  ['WRONGPASS', () => replyError('WRONGPASS invalid username-password pair or user is disabled.')],
  ['NOPERM', () => replyError("NOPERM User x has no permissions to run the 'evalsha' command")],
  [
    'WRONGTYPE',
    () => replyError('WRONGTYPE Operation against a key holding the wrong kind of value'),
  ],
  ['OOM', () => replyError("OOM command not allowed when used memory > 'maxmemory'.")],
  ['MISCONF', () => replyError('MISCONF Errors writing to the AOF file')],
  ['ERR', () => replyError('ERR user_script:1: Script attempted to access nonexistent global')],
  ['an unknown code', () => replyError('WEIRD something')],
  ['a client TypeError', () => new TypeError('bad argument')],
  ['another Error', () => new Error('Command timed out after a while')],
  ['a non-Error', () => 'oops'],
];

const noop = (): undefined => undefined;

afterEach(() => {
  vi.restoreAllMocks();
});

describe('the Lua script', () => {
  it('is the exact bytes of lua/sliding-window.lua with their SHA-1', () => {
    expect(SCRIPT).toBe(LUA);
    expect(SCRIPT_SHA).toBe(createHash('sha1').update(LUA).digest('hex'));
    expect(SCRIPT_SHA).toMatch(/^[0-9a-f]{40}$/);
  });
});

describe('validation', () => {
  it('accepts the documented options', () => {
    expect(() => limiter()).not.toThrow();
    expect(() => limiter({ prefix: '' })).not.toThrow();
    expect(() => limiter({ prefix: 'app:rsw:' })).not.toThrow();
    expect(() => limiter({ prefix: 'a1:' })).not.toThrow();
    expect(() => limiter({ name: 'v2' })).not.toThrow();
    expect(() =>
      limiter({ limit: Number.MAX_SAFE_INTEGER, windowMs: 31_536_000_000 }),
    ).not.toThrow();
    expect(() => limiter({ limit: 1, windowMs: 1, failMode: 'open' })).not.toThrow();
    expect(() => limiter({ onError: () => undefined })).not.toThrow();
  });

  it.each([
    ['a missing options object', null],
    ['a Cluster client', { isCluster: true, options: {}, call: noop }],
    ['a client without isCluster', { options: {}, call: noop }],
    ['a client without call', { isCluster: false, options: {} }],
    ['a client without options', { isCluster: false, call: noop }],
    ['a non-object client', 'redis://localhost'],
    ['ioredis db 1', { isCluster: false, options: { db: 1 }, call: noop }],
    ['ioredis db 99', { isCluster: false, options: { db: 99 }, call: noop }],
    ['a non-empty keyPrefix', { isCluster: false, options: { keyPrefix: 'app:' }, call: noop }],
  ])('rejects %s with a TypeError', (name, client) => {
    if (name === 'a missing options object') {
      expect(() => new SlidingWindowLimiter(client as unknown as LimiterOptions)).toThrow(
        TypeError,
      );
      return;
    }
    expect(() => limiter({ client })).toThrow(TypeError);
  });

  it('accepts db 0 and an empty keyPrefix', () => {
    const client = { isCluster: false, options: { db: 0, keyPrefix: '' }, call: noop };
    expect(() => limiter({ client })).not.toThrow();
  });

  it.each([
    ['name', ''],
    ['name', 'a:b'],
    ['name', '5'],
    ['name', '123'],
    ['name', 7],
    ['prefix', 'a::'],
    ['prefix', 'rsw'],
    ['prefix', ':'],
    ['prefix', '5:'],
    ['prefix', 'a:5:'],
    ['prefix', 1],
    ['limit', 0],
    ['limit', 1.5],
    ['limit', 2 ** 53],
    ['limit', '3'],
    ['windowMs', 0],
    ['windowMs', 31_536_000_001],
    ['windowMs', 2.5],
    ['windowMs', '60000'],
    ['failMode', undefined],
    ['failMode', 'maybe'],
    ['onError', 'log'],
    ['onError', null],
  ])('rejects %s %j with a TypeError', (option, value) => {
    expect(() => limiter({ [option]: value })).toThrow(TypeError);
  });

  it('rejects prefix "a:" with name "5" (the digit rule)', () => {
    expect(() => limiter({ prefix: 'a:', name: '5' })).toThrow(TypeError);
  });

  it.each([[''], [5], [undefined]])('rejects identifier %j with a rejected promise', async (id) => {
    const { limiter: l, call } = limiter();
    await expect(l.hit(id as string)).rejects.toThrow(TypeError);
    expect(call).not.toHaveBeenCalled();
  });
});

describe('hit()', () => {
  it('sends EVALSHA with the key, limit, window and a fresh 32-hex nonce', async () => {
    const { limiter: l, call } = limiter({ prefix: 'app:' });
    await l.hit('2001:db8::1');
    await l.hit('2001:db8::1');
    expect(call).toHaveBeenCalledTimes(2);
    const [first, second] = call.mock.calls;
    expect(first?.slice(0, 6)).toEqual([
      'EVALSHA',
      SCRIPT_SHA,
      1,
      'app:login:60000:2001:db8::1',
      '3',
      '60000',
    ]);
    expect(first?.[6]).toMatch(/^[0-9a-f]{32}$/);
    expect(second?.[6]).toMatch(/^[0-9a-f]{32}$/);
    expect(first?.[6]).not.toBe(second?.[6]);
  });

  it('uses the default prefix rsw:', async () => {
    const { limiter: l, call } = limiter();
    await l.hit('203.0.113.7');
    expect(call.mock.calls[0]?.[3]).toBe('rsw:login:60000:203.0.113.7');
  });

  it('maps an allowed reply', async () => {
    const before = Date.now();
    const { limiter: l } = limiter({}, async () => [1, 2, 0, 60000]);
    const result = await l.hit('x');
    expect(result).toMatchObject({
      allowed: true,
      remaining: 2,
      retryAfterMs: 0,
      resetAfterMs: 60000,
      degraded: false,
    });
    expect(result.resetAt.getTime()).toBeGreaterThanOrEqual(before + 60000);
    expect(result.resetAt.getTime()).toBeLessThanOrEqual(Date.now() + 60000);
  });

  it('maps a denied reply', async () => {
    const { limiter: l } = limiter({}, async () => [0, 0, 30000, 50000]);
    expect(await l.hit('x')).toMatchObject({
      allowed: false,
      remaining: 0,
      retryAfterMs: 30000,
      resetAfterMs: 50000,
      degraded: false,
    });
  });

  it('accepts a replay-shaped reply (remaining 0, reset below the window)', async () => {
    const { limiter: l } = limiter({}, async () => [1, 0, 0, 12345]);
    expect(await l.hit('x')).toMatchObject({ allowed: true, remaining: 0, resetAfterMs: 12345 });
  });

  it('accepts integer strings only from a stringNumbers client', async () => {
    const strings = async () => ['1', '2', '0', '60000'];
    const plain = new SlidingWindowLimiter({
      client: fakeClient(strings).client,
      name: 'n',
      limit: 3,
      windowMs: 60000,
      failMode: 'open',
    });
    await expect(plain.hit('x')).rejects.toThrow('unexpected reply');
    const stringy = new SlidingWindowLimiter({
      client: fakeClient(strings, { stringNumbers: true }).client,
      name: 'n',
      limit: 3,
      windowMs: 60000,
      failMode: 'open',
    });
    expect(await stringy.hit('x')).toMatchObject({ allowed: true, remaining: 2 });
  });

  it.each([
    ['not an array', 'OK'],
    ['a queued MULTI reply', 'QUEUED'],
    ['three values', [1, 2, 0]],
    ['five values', [1, 2, 0, 60000, 0]],
    ['a string value', [1, '2', 0, 60000]],
    ['a fractional value', [1, 2.5, 0, 60000]],
    ['allowed 2', [2, 0, 0, 60000]],
    ['allowed with a retry time', [1, 2, 5, 60000]],
    ['allowed with remaining = limit', [1, 3, 0, 60000]],
    ['allowed with negative remaining', [1, -1, 0, 60000]],
    ['allowed with reset 0', [1, 2, 0, 0]],
    ['allowed with reset above the window', [1, 2, 0, 60001]],
    ['denied with remaining', [0, 1, 1000, 2000]],
    ['denied with retry 0', [0, 0, 0, 2000]],
    ['denied with retry above reset', [0, 0, 3000, 2000]],
    ['denied with reset above the window', [0, 0, 1000, 60001]],
    ['a pipeline-like object', { then: undefined }],
  ])('throws on a malformed reply (%s) in both modes', async (_name, reply) => {
    for (const failMode of ['open', 'closed']) {
      const onError = vi.fn();
      const { limiter: l } = limiter({ failMode, onError }, async () => reply);
      await expect(l.hit('x')).rejects.toThrow('unexpected reply');
      expect(onError).not.toHaveBeenCalled();
    }
  });
});

describe('the failure policy', () => {
  describe.each(['open', 'closed'] as const)('fail %s', (failMode) => {
    it.each(Object.keys(ERRORS))('degrades on %s and calls onError once', async (kind) => {
      const onError = vi.fn();
      const { limiter: l } = limiter({ failMode, onError, limit: 5, windowMs: 1000 }, async () => {
        throw ERRORS[kind]!();
      });
      const before = Date.now();
      const result = await l.hit('secret-identifier');
      expect(result).toMatchObject(
        failMode === 'open'
          ? { allowed: true, remaining: 5, retryAfterMs: 0, resetAfterMs: 1000, degraded: true }
          : {
              allowed: false,
              remaining: 0,
              retryAfterMs: 1000,
              resetAfterMs: 1000,
              degraded: true,
            },
      );
      expect(result.resetAt.getTime()).toBeGreaterThanOrEqual(before + 1000);
      expect(onError).toHaveBeenCalledTimes(1);
      const [report, context] = onError.mock.calls[0]!;
      const reason = REASONS[kind];
      expect(report).toStrictEqual({ reason, message: `Redis or Valkey unavailable: ${reason}` });
      expect(context).toStrictEqual({ name: 'login', failMode });
      expect(Object.isFrozen(report)).toBe(true);
      expect(Object.isFrozen(context)).toBe(true);
      expect(JSON.stringify([report, context])).not.toContain('secret-identifier');
    });

    it('degrades without a callback', async () => {
      const { limiter: l } = limiter({ failMode }, async () => {
        throw ERRORS.timeout!();
      });
      expect((await l.hit('x')).degraded).toBe(true);
    });

    it.each(THROWING)(
      'throws the client error unchanged on %s, without onError',
      async (_n, make) => {
        const error = make();
        const onError = vi.fn();
        const { limiter: l } = limiter({ failMode, onError }, async () => {
          throw error;
        });
        await expect(l.hit('x')).rejects.toBe(error);
        expect(onError).not.toHaveBeenCalled();
      },
    );
  });

  it('answers NOSCRIPT with one EVAL of the script carrying the same nonce', async () => {
    const { limiter: l, call } = limiter({}, async (command) => {
      if (command === 'EVALSHA') throw replyError('NOSCRIPT No matching script.');
      return [1, 2, 0, 60000];
    });
    expect(await l.hit('x')).toMatchObject({ allowed: true, degraded: false });
    expect(call).toHaveBeenCalledTimes(2);
    const [evalsha, evalCall] = call.mock.calls;
    expect(evalCall?.[0]).toBe('EVAL');
    expect(evalCall?.[1]).toBe(SCRIPT);
    expect(evalCall?.slice(2)).toEqual(evalsha?.slice(2));
  });

  it('classifies a failure of the EVAL fallback normally', async () => {
    const onError = vi.fn();
    const noscriptThen = (second: () => unknown) => async (command: string) => {
      if (command === 'EVALSHA') throw replyError('NOSCRIPT No matching script.');
      throw second();
    };
    const degraded = limiter({ onError }, noscriptThen(ERRORS.closed!));
    expect(await degraded.limiter.hit('x')).toMatchObject({ degraded: true, allowed: false });
    expect(onError).toHaveBeenCalledTimes(1);
    expect(degraded.call).toHaveBeenCalledTimes(2);

    const wrongType = replyError(
      'WRONGTYPE Operation against a key holding the wrong kind of value',
    );
    const thrown = limiter(
      { onError },
      noscriptThen(() => wrongType),
    );
    await expect(thrown.limiter.hit('x')).rejects.toBe(wrongType);

    const again = replyError('NOSCRIPT No matching script.');
    const twice = limiter(
      { onError },
      noscriptThen(() => again),
    );
    await expect(twice.limiter.hit('x')).rejects.toBe(again);
    expect(twice.call).toHaveBeenCalledTimes(2);
    expect(onError).toHaveBeenCalledTimes(1);
  });
});

describe('the onError callback', () => {
  const failing = async () => {
    throw ERRORS.closed!();
  };

  it('a throwing callback still yields the degraded result and a warning', async () => {
    const warn = vi.spyOn(process, 'emitWarning').mockImplementation(() => undefined);
    const { limiter: l } = limiter(
      {
        failMode: 'open',
        onError: () => {
          throw new Error('sentry down');
        },
      },
      failing,
    );
    expect(await l.hit('x')).toMatchObject({ allowed: true, degraded: true });
    expect(warn).toHaveBeenCalledWith(
      'redis-sliding-window: onError callback failed: sentry down',
      {
        code: 'RSW_ON_ERROR_FAILED',
      },
    );
  });

  it('a rejecting callback still yields the degraded result, a warning, and no unhandled rejection', async () => {
    const warn = vi.spyOn(process, 'emitWarning').mockImplementation(() => undefined);
    const unhandled = vi.fn();
    process.on('unhandledRejection', unhandled);
    try {
      const { limiter: l } = limiter(
        { failMode: 'closed', onError: async () => Promise.reject(new Error('later')) },
        failing,
      );
      expect(await l.hit('x')).toMatchObject({ allowed: false, degraded: true });
      await new Promise((resolve) => setTimeout(resolve, 20));
      expect(warn).toHaveBeenCalledWith('redis-sliding-window: onError callback failed: later', {
        code: 'RSW_ON_ERROR_FAILED',
      });
      expect(unhandled).not.toHaveBeenCalled();
    } finally {
      process.off('unhandledRejection', unhandled);
    }
  });

  it('a callback failure whose message cannot be read still yields the degraded result', async () => {
    const warn = vi.spyOn(process, 'emitWarning').mockImplementation(() => undefined);
    const unhandled = vi.fn();
    process.on('unhandledRejection', unhandled);
    const hostile = {
      toString() {
        throw new Error('hostile');
      },
    };
    const hostileError = new Error('x');
    Object.defineProperty(hostileError, 'message', {
      get() {
        throw new Error('hostile');
      },
    });
    try {
      const sync = limiter(
        {
          failMode: 'open',
          onError: () => {
            throw hostile;
          },
        },
        failing,
      ).limiter;
      expect(await sync.hit('x')).toMatchObject({ allowed: true, degraded: true });
      const async = limiter(
        { failMode: 'closed', onError: async () => Promise.reject(hostileError) },
        failing,
      ).limiter;
      expect(await async.hit('x')).toMatchObject({ allowed: false, degraded: true });
      await new Promise((resolve) => setTimeout(resolve, 20));
      expect(warn).toHaveBeenCalledWith(
        'redis-sliding-window: onError callback failed: (unreadable error)',
        { code: 'RSW_ON_ERROR_FAILED' },
      );
      expect(warn).toHaveBeenCalledTimes(2);
      expect(unhandled).not.toHaveBeenCalled();
    } finally {
      process.off('unhandledRejection', unhandled);
    }
  });

  it('does not await the callback', async () => {
    let release: () => void = () => undefined;
    const pending = new Promise<void>((resolve) => {
      release = resolve;
    });
    const { limiter: l } = limiter({ onError: () => pending }, failing);
    expect((await l.hit('x')).degraded).toBe(true);
    release();
  });
});

describe('load()', () => {
  it('sends SCRIPT LOAD with the file bytes', async () => {
    const { limiter: l, call } = limiter({}, async () => SCRIPT_SHA);
    await l.load();
    expect(call).toHaveBeenCalledWith('SCRIPT', 'LOAD', LUA);
  });

  it('throws on a different SHA', async () => {
    const { limiter: l } = limiter({}, async () => '0'.repeat(40));
    await expect(l.load()).rejects.toThrow('unexpected SHA');
  });

  describe.each(['open', 'closed'] as const)('fail %s', (failMode) => {
    it.each([...Object.keys(ERRORS).map((k) => [k, ERRORS[k]!] as const), ...THROWING])(
      'throws %s without the callback',
      async (_name, make) => {
        const error = make();
        const onError = vi.fn();
        const { limiter: l } = limiter({ failMode, onError }, async () => {
          throw error;
        });
        await expect(l.load()).rejects.toBe(error);
        expect(onError).not.toHaveBeenCalled();
      },
    );
  });
});

describe('retryAfterSeconds', () => {
  const result = (allowed: boolean, retryAfterMs: number): LimitResult => ({
    allowed,
    remaining: 0,
    retryAfterMs,
    resetAfterMs: 60000,
    resetAt: new Date(),
    degraded: false,
  });

  it.each([
    [true, 0, 0],
    [false, 1, 1],
    [false, 999, 1],
    [false, 1000, 1],
    [false, 1001, 2],
    [false, 30000, 30],
    [false, 0, 1],
  ])('allowed %s, retryAfterMs %i: %i', (allowed, ms, seconds) => {
    expect(retryAfterSeconds(result(allowed, ms))).toBe(seconds);
  });
});
