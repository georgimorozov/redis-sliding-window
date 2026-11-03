import { describe, expectTypeOf, it } from 'vitest';
import type { FailMode, LimitResult } from '../../../src/index.js';

// Type-level tests: `npm run typecheck` fails when a public type drifts from docs/design.md.
describe('public types', () => {
  it('FailMode is exactly "open" | "closed"', () => {
    expectTypeOf<FailMode>().toEqualTypeOf<'open' | 'closed'>();
    // @ts-expect-error a mode outside the contract is a type error
    const _notAMode: FailMode = 'maybe';
    expectTypeOf(_notAMode).toEqualTypeOf<FailMode>();
  });

  it('LimitResult carries exactly the documented fields', () => {
    expectTypeOf<LimitResult>().toEqualTypeOf<{
      readonly allowed: boolean;
      readonly remaining: number;
      readonly retryAfterMs: number;
      readonly resetAfterMs: number;
      readonly resetAt: Date;
      readonly degraded: boolean;
    }>();
  });
});
