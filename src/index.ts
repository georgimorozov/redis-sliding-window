/**
 * redis-sliding-window: a sliding-window rate limiter for Redis and Valkey. docs/design.md is the contract.
 */
import { randomBytes } from 'node:crypto';
import process from 'node:process';
import type { Redis } from 'ioredis';
import { classify, type UnavailableReason } from './classify.js';
import { SCRIPT, SCRIPT_SHA } from './script.js';

export type { UnavailableReason } from './classify.js';

/** What a limiter does when Redis or Valkey cannot answer (docs/design.md, "Failure policy"). */
export type FailMode = 'open' | 'closed';

/** Built from the reason alone and frozen: message is `Redis or Valkey unavailable: ${reason}`. */
export interface UnavailableReport {
  readonly reason: UnavailableReason;
  readonly message: string;
}

/** Which limiter produced a degraded result. Never holds the identifier. */
export interface ErrorContext {
  readonly name: string;
  readonly failMode: FailMode;
}

/** Runs once per degraded result, before it is returned. What it throws or rejects never changes the result. */
export type OnError = (report: UnavailableReport, context: ErrorContext) => void | Promise<void>;

export interface LimiterOptions {
  /** A long-lived ioredis `Redis` client (not a `Cluster`), database 0, no `keyPrefix`. */
  readonly client: Redis;
  /** Non-empty, no `:`, not all digits. */
  readonly name: string;
  /** Allowed requests per window: an integer, 1 to 2^53 - 1. */
  readonly limit: number;
  /** The window in milliseconds: an integer, 1 to 31536000000 (365 days). */
  readonly windowMs: number;
  /** No default: choose (docs/design.md, "Choosing a mode"). */
  readonly failMode: FailMode;
  readonly onError?: OnError;
  /** Default `rsw:`. Empty, or segments that each end in `:`, none empty or all digits. */
  readonly prefix?: string;
}

/** The outcome of one hit() (docs/design.md, "Results"). */
export interface LimitResult {
  /** Whether this request may proceed. */
  readonly allowed: boolean;
  /** Requests still allowed in the current window after this one. */
  readonly remaining: number;
  /** 0 when allowed; otherwise milliseconds until a request can succeed. */
  readonly retryAfterMs: number;
  /** Milliseconds until the bucket holds no entries. */
  readonly resetAfterMs: number;
  /** The caller's clock plus resetAfterMs. */
  readonly resetAt: Date;
  /** True when the server gave no usable answer and the failure policy produced this result. */
  readonly degraded: boolean;
}

const MAX_WINDOW_MS = 31_536_000_000;
const NAME_PATTERN = /^[^:]*[^:0-9][^:]*$/;
const PREFIX_PATTERN = /^([^:]*[^:0-9][^:]*:)*$/;
const WARNING_CODE = 'RSW_ON_ERROR_FAILED';

type ScriptReply = readonly [
  allowed: number,
  remaining: number,
  retryAfterMs: number,
  resetAfterMs: number,
];

function isIntIn(value: unknown, min: number, max: number): value is number {
  return Number.isSafeInteger(value) && (value as number) >= min && (value as number) <= max;
}

function checkClient(client: unknown): asserts client is Redis {
  const candidate = client as {
    isCluster?: unknown;
    call?: unknown;
    options?: { db?: unknown; keyPrefix?: unknown } | null;
  } | null;
  if (
    typeof candidate !== 'object' ||
    candidate === null ||
    candidate.isCluster !== false ||
    typeof candidate.call !== 'function' ||
    typeof candidate.options !== 'object' ||
    candidate.options === null
  ) {
    throw new TypeError(
      'redis-sliding-window: client must be an ioredis Redis client (not a Cluster)',
    );
  }
  const { db, keyPrefix } = candidate.options;
  if (db !== undefined && db !== 0) {
    throw new TypeError('redis-sliding-window: the ioredis client must use database 0');
  }
  if (keyPrefix !== undefined && keyPrefix !== '') {
    throw new TypeError('redis-sliding-window: the ioredis client must not set keyPrefix');
  }
}

/** Seconds for a 429's Retry-After: ceil(retryAfterMs / 1000), at least 1 when denied, 0 when allowed. */
export function retryAfterSeconds(result: LimitResult): number {
  if (result.allowed) return 0;
  return Math.max(1, Math.ceil(result.retryAfterMs / 1000));
}

export class SlidingWindowLimiter {
  readonly #client: Redis;
  readonly #name: string;
  readonly #limit: number;
  readonly #windowMs: number;
  readonly #failMode: FailMode;
  readonly #onError: OnError | undefined;
  readonly #prefix: string;
  readonly #acceptStrings: boolean;

  constructor(options: LimiterOptions) {
    if (typeof options !== 'object' || options === null) {
      throw new TypeError('redis-sliding-window: options must be an object');
    }
    const { client, name, limit, windowMs, failMode, onError, prefix = 'rsw:' } = options;
    checkClient(client);
    if (typeof name !== 'string' || !NAME_PATTERN.test(name)) {
      throw new TypeError(
        'redis-sliding-window: name must be non-empty, without ":", and not all digits',
      );
    }
    if (!isIntIn(limit, 1, Number.MAX_SAFE_INTEGER)) {
      throw new TypeError('redis-sliding-window: limit must be an integer from 1 to 2^53 - 1');
    }
    if (!isIntIn(windowMs, 1, MAX_WINDOW_MS)) {
      throw new TypeError(
        'redis-sliding-window: windowMs must be an integer from 1 to 31536000000',
      );
    }
    if (failMode !== 'open' && failMode !== 'closed') {
      throw new TypeError('redis-sliding-window: failMode must be "open" or "closed"');
    }
    if (onError !== undefined && typeof onError !== 'function') {
      throw new TypeError('redis-sliding-window: onError must be a function');
    }
    if (typeof prefix !== 'string' || !PREFIX_PATTERN.test(prefix)) {
      throw new TypeError(
        'redis-sliding-window: prefix must be empty or segments ending in ":", none empty or all digits',
      );
    }
    this.#client = client;
    this.#name = name;
    this.#limit = limit;
    this.#windowMs = windowMs;
    this.#failMode = failMode;
    this.#onError = onError;
    this.#prefix = prefix;
    // ioredis returns integer replies as strings when the client sets stringNumbers.
    this.#acceptStrings = (client.options as { stringNumbers?: unknown }).stringNumbers === true;
  }

  /** Sends SCRIPT LOAD; call it once at startup. Every error throws: the failure policy does not apply. */
  async load(): Promise<void> {
    const sha = await this.#client.call('SCRIPT', 'LOAD', SCRIPT);
    if (sha !== SCRIPT_SHA) {
      throw new Error('redis-sliding-window: SCRIPT LOAD returned an unexpected SHA');
    }
  }

  /** Records one request for `identifier` if the window has room. */
  async hit(identifier: string): Promise<LimitResult> {
    if (typeof identifier !== 'string' || identifier === '') {
      throw new TypeError('redis-sliding-window: identifier must be a non-empty string');
    }
    const key = `${this.#prefix}${this.#name}:${this.#windowMs}:${identifier}`;
    const nonce = randomBytes(16).toString('hex');
    const args = [1, key, String(this.#limit), String(this.#windowMs), nonce];
    let reply: unknown;
    try {
      reply = await this.#client.call('EVALSHA', SCRIPT_SHA, ...args);
    } catch (error) {
      const first = classify(error);
      if (first.action === 'degrade') return this.#degrade(first.reason);
      if (first.action === 'throw') throw error;
      try {
        reply = await this.#client.call('EVAL', SCRIPT, ...args);
      } catch (fallbackError) {
        const second = classify(fallbackError);
        if (second.action === 'degrade') return this.#degrade(second.reason);
        throw fallbackError;
      }
    }
    const [allowed, remaining, retryAfterMs, resetAfterMs] = this.#parseReply(reply);
    return {
      allowed: allowed === 1,
      remaining,
      retryAfterMs,
      resetAfterMs,
      resetAt: new Date(Date.now() + resetAfterMs),
      degraded: false,
    };
  }

  #parseReply(reply: unknown): ScriptReply {
    const fail = (): never => {
      throw new Error('redis-sliding-window: unexpected reply from the script');
    };
    if (!Array.isArray(reply) || reply.length !== 4) return fail();
    const values: unknown[] = reply.map((value: unknown) =>
      this.#acceptStrings && typeof value === 'string' && /^-?\d+$/.test(value)
        ? Number(value)
        : value,
    );
    if (!values.every((value) => Number.isSafeInteger(value))) return fail();
    const [allowed, remaining, retryAfterMs, resetAfterMs] = values as unknown as ScriptReply;
    const valid =
      allowed === 1
        ? retryAfterMs === 0 &&
          isIntIn(remaining, 0, this.#limit - 1) &&
          isIntIn(resetAfterMs, 1, this.#windowMs)
        : allowed === 0 &&
          remaining === 0 &&
          retryAfterMs >= 1 &&
          retryAfterMs <= resetAfterMs &&
          resetAfterMs <= this.#windowMs;
    if (!valid) return fail();
    return [allowed, remaining, retryAfterMs, resetAfterMs];
  }

  #degrade(reason: UnavailableReason): LimitResult {
    if (this.#onError) {
      const report: UnavailableReport = Object.freeze({
        reason,
        message: `Redis or Valkey unavailable: ${reason}`,
      });
      const context: ErrorContext = Object.freeze({ name: this.#name, failMode: this.#failMode });
      try {
        const returned: unknown = this.#onError(report, context);
        if (isThenable(returned)) Promise.resolve(returned).catch(warnCallbackFailed);
      } catch (error) {
        warnCallbackFailed(error);
      }
    }
    const open = this.#failMode === 'open';
    return {
      allowed: open,
      remaining: open ? this.#limit : 0,
      retryAfterMs: open ? 0 : this.#windowMs,
      resetAfterMs: this.#windowMs,
      resetAt: new Date(Date.now() + this.#windowMs),
      degraded: true,
    };
  }
}

function isThenable(value: unknown): value is PromiseLike<unknown> {
  return (
    (typeof value === 'object' || typeof value === 'function') &&
    value !== null &&
    typeof (value as { then?: unknown }).then === 'function'
  );
}

function warnCallbackFailed(error: unknown): void {
  const message = error instanceof Error ? error.message : String(error);
  process.emitWarning(`redis-sliding-window: onError callback failed: ${message}`, {
    code: WARNING_CODE,
  });
}
