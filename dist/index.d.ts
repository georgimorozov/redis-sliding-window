import type { Redis } from 'ioredis';
import { type UnavailableReason } from './classify.js';
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
/** Seconds for a 429's Retry-After: ceil(retryAfterMs / 1000), at least 1 when denied, 0 when allowed. */
export declare function retryAfterSeconds(result: LimitResult): number;
export declare class SlidingWindowLimiter {
    #private;
    constructor(options: LimiterOptions);
    /** Sends SCRIPT LOAD; call it once at startup. Every error throws: the failure policy does not apply. */
    load(): Promise<void>;
    /** Records one request for `identifier` if the window has room. */
    hit(identifier: string): Promise<LimitResult>;
}
