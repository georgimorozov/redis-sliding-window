// tag-check's ESM consumer: the installed package imports and constructs without a server.
import console from 'node:console';
import { Redis } from 'ioredis';
import { SlidingWindowLimiter, retryAfterSeconds } from 'redis-sliding-window';

const client = new Redis({ lazyConnect: true });
const limiter = new SlidingWindowLimiter({
  client,
  name: 'tagcheck',
  limit: 1,
  windowMs: 1000,
  failMode: 'open',
});
if (!(limiter instanceof SlidingWindowLimiter) || typeof retryAfterSeconds !== 'function') {
  throw new Error('tag-check (npm): unexpected exports');
}
client.disconnect();
console.log('tag-check (npm): ESM import ok');
