import { randomBytes } from 'node:crypto';
import { Redis, type RedisOptions } from 'ioredis';

// The server for feature and integration tests: REDIS_HOST and REDIS_PORT, default 127.0.0.1:6379.
// These tests need one and fail without it (scripts/docker-test.sh integration starts the servers).
export const host = process.env.REDIS_HOST || '127.0.0.1';
export const port = Number(process.env.REDIS_PORT) || 6379;

/** A client that fails fast instead of retrying forever. Callers disconnect it. */
export function connect(options: RedisOptions = {}): Redis {
  return new Redis({
    host,
    port,
    connectTimeout: 2000,
    commandTimeout: 2000,
    maxRetriesPerRequest: 1,
    retryStrategy: () => null,
    ...options,
  });
}

export const hex = (bytes: number): string => randomBytes(bytes).toString('hex');
export const sleep = (ms: number): Promise<void> =>
  new Promise((resolve) => setTimeout(resolve, ms));
