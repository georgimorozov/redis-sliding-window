/**
 * redis-sliding-window: a sliding-window rate limiter for Redis and Valkey. docs/design.md is the contract.
 */
import { randomBytes } from 'node:crypto';
import process from 'node:process';
import { classify } from './classify.js';
import { SCRIPT, SCRIPT_SHA } from './script.js';
const MAX_WINDOW_MS = 31_536_000_000;
const NAME_PATTERN = /^[^:]*[^:0-9][^:]*$/;
const PREFIX_PATTERN = /^([^:]*[^:0-9][^:]*:)*$/;
const WARNING_CODE = 'RSW_ON_ERROR_FAILED';
function isIntIn(value, min, max) {
    return Number.isSafeInteger(value) && value >= min && value <= max;
}
function checkClient(client) {
    const candidate = client;
    if (typeof candidate !== 'object' ||
        candidate === null ||
        candidate.isCluster !== false ||
        typeof candidate.call !== 'function' ||
        typeof candidate.options !== 'object' ||
        candidate.options === null) {
        throw new TypeError('redis-sliding-window: client must be an ioredis Redis client (not a Cluster)');
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
export function retryAfterSeconds(result) {
    if (result.allowed)
        return 0;
    return Math.max(1, Math.ceil(result.retryAfterMs / 1000));
}
export class SlidingWindowLimiter {
    #client;
    #name;
    #limit;
    #windowMs;
    #failMode;
    #onError;
    #prefix;
    #acceptStrings;
    constructor(options) {
        if (typeof options !== 'object' || options === null) {
            throw new TypeError('redis-sliding-window: options must be an object');
        }
        const { client, name, limit, windowMs, failMode, onError, prefix = 'rsw:' } = options;
        checkClient(client);
        if (typeof name !== 'string' || !NAME_PATTERN.test(name)) {
            throw new TypeError('redis-sliding-window: name must be non-empty, without ":", and not all digits');
        }
        if (!isIntIn(limit, 1, Number.MAX_SAFE_INTEGER)) {
            throw new TypeError('redis-sliding-window: limit must be an integer from 1 to 2^53 - 1');
        }
        if (!isIntIn(windowMs, 1, MAX_WINDOW_MS)) {
            throw new TypeError('redis-sliding-window: windowMs must be an integer from 1 to 31536000000');
        }
        if (failMode !== 'open' && failMode !== 'closed') {
            throw new TypeError('redis-sliding-window: failMode must be "open" or "closed"');
        }
        if (onError !== undefined && typeof onError !== 'function') {
            throw new TypeError('redis-sliding-window: onError must be a function');
        }
        if (typeof prefix !== 'string' || !PREFIX_PATTERN.test(prefix)) {
            throw new TypeError('redis-sliding-window: prefix must be empty or segments ending in ":", none empty or all digits');
        }
        this.#client = client;
        this.#name = name;
        this.#limit = limit;
        this.#windowMs = windowMs;
        this.#failMode = failMode;
        this.#onError = onError;
        this.#prefix = prefix;
        // ioredis returns integer replies as strings when the client sets stringNumbers.
        this.#acceptStrings = client.options.stringNumbers === true;
    }
    /** Sends SCRIPT LOAD; call it once at startup. Every error throws: the failure policy does not apply. */
    async load() {
        const sha = await this.#client.call('SCRIPT', 'LOAD', SCRIPT);
        if (sha !== SCRIPT_SHA) {
            throw new Error('redis-sliding-window: SCRIPT LOAD returned an unexpected SHA');
        }
    }
    /** Records one request for `identifier` if the window has room. */
    async hit(identifier) {
        if (typeof identifier !== 'string' || identifier === '') {
            throw new TypeError('redis-sliding-window: identifier must be a non-empty string');
        }
        const key = `${this.#prefix}${this.#name}:${this.#windowMs}:${identifier}`;
        const nonce = randomBytes(16).toString('hex');
        const args = [1, key, String(this.#limit), String(this.#windowMs), nonce];
        let reply;
        try {
            reply = await this.#client.call('EVALSHA', SCRIPT_SHA, ...args);
        }
        catch (error) {
            const first = classify(error);
            if (first.action === 'degrade')
                return this.#degrade(first.reason);
            if (first.action === 'throw')
                throw error;
            try {
                reply = await this.#client.call('EVAL', SCRIPT, ...args);
            }
            catch (fallbackError) {
                const second = classify(fallbackError);
                if (second.action === 'degrade')
                    return this.#degrade(second.reason);
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
    #parseReply(reply) {
        const fail = () => {
            throw new Error('redis-sliding-window: unexpected reply from the script');
        };
        if (!Array.isArray(reply) || reply.length !== 4)
            return fail();
        const values = reply.map((value) => this.#acceptStrings && typeof value === 'string' && /^-?\d+$/.test(value)
            ? Number(value)
            : value);
        if (!values.every((value) => Number.isSafeInteger(value)))
            return fail();
        const [allowed, remaining, retryAfterMs, resetAfterMs] = values;
        const valid = allowed === 1
            ? retryAfterMs === 0 &&
                isIntIn(remaining, 0, this.#limit - 1) &&
                isIntIn(resetAfterMs, 1, this.#windowMs)
            : allowed === 0 &&
                remaining === 0 &&
                retryAfterMs >= 1 &&
                retryAfterMs <= resetAfterMs &&
                resetAfterMs <= this.#windowMs;
        if (!valid)
            return fail();
        return [allowed, remaining, retryAfterMs, resetAfterMs];
    }
    #degrade(reason) {
        if (this.#onError) {
            const report = Object.freeze({
                reason,
                message: `Redis or Valkey unavailable: ${reason}`,
            });
            const context = Object.freeze({ name: this.#name, failMode: this.#failMode });
            try {
                const returned = this.#onError(report, context);
                if (isThenable(returned))
                    Promise.resolve(returned).catch(warnCallbackFailed);
            }
            catch (error) {
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
function isThenable(value) {
    return ((typeof value === 'object' || typeof value === 'function') &&
        value !== null &&
        typeof value.then === 'function');
}
function warnCallbackFailed(error) {
    let message;
    try {
        message = error instanceof Error ? String(error.message) : String(error);
    }
    catch {
        message = '(unreadable error)';
    }
    process.emitWarning(`redis-sliding-window: onError callback failed: ${message}`, {
        code: WARNING_CODE,
    });
}
