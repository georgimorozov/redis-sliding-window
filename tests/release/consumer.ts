// tag-check's TypeScript consumer: resolves the package's exports.types under NodeNext.
import { Redis } from 'ioredis';
import { SlidingWindowLimiter, type LimitResult } from 'redis-sliding-window';

const limiter: SlidingWindowLimiter = new SlidingWindowLimiter({
  client: new Redis({ lazyConnect: true }),
  name: 'tagcheck',
  limit: 1,
  windowMs: 1000,
  failMode: 'closed',
});
export const pending: Promise<LimitResult> = limiter.hit('id');
