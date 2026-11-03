/**
 * redis-sliding-window public types. docs/design.md is the contract; the limiter arrives in RSW-2.
 */

/** What a limiter does when Redis or Valkey cannot answer (docs/design.md, "Failure policy"). */
export type FailMode = 'open' | 'closed';

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
