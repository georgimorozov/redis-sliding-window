import { readdirSync, readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

// The vector format is docs/design.md, "Vector format". This checker is the files' only validation
// before the RSW-2 and RSW-3 runners execute them against a server.
const MAX_LIMIT = Number.MAX_SAFE_INTEGER;
const MAX_WINDOW_MS = 31_536_000_000;

type Json = Record<string, unknown>;
const isObject = (value: unknown): value is Json =>
  typeof value === 'object' && value !== null && !Array.isArray(value);
const isInt = (value: unknown, min: number, max: number): boolean =>
  Number.isSafeInteger(value) && (value as number) >= min && (value as number) <= max;
const onlyKeys = (value: Json, allowed: string[]): boolean =>
  Object.keys(value).every((key) => allowed.includes(key));

function isMs(value: unknown): boolean {
  if (typeof value === 'number') return isInt(value, 0, MAX_WINDOW_MS);
  return (
    isObject(value) &&
    onlyKeys(value, ['min', 'max']) &&
    isInt(value.min, 0, MAX_WINDOW_MS) &&
    isInt(value.max, 0, MAX_WINDOW_MS) &&
    (value.min as number) <= (value.max as number)
  );
}

function isExpectation(value: unknown): boolean {
  return (
    isObject(value) &&
    Object.keys(value).length > 0 &&
    onlyKeys(value, ['allowed', 'remaining', 'retryAfterMs', 'resetAfterMs']) &&
    (value.allowed === undefined || typeof value.allowed === 'boolean') &&
    (value.remaining === undefined || isInt(value.remaining, 0, MAX_LIMIT)) &&
    (value.retryAfterMs === undefined || isMs(value.retryAfterMs)) &&
    (value.resetAfterMs === undefined || isMs(value.resetAfterMs))
  );
}

function isStep(step: unknown): boolean {
  if (!isObject(step)) return false;
  switch (step.op) {
    case 'hit':
      return onlyKeys(step, ['op', 'expect']) && isExpectation(step.expect);
    case 'seed':
      return (
        onlyKeys(step, ['op', 'agesMs']) &&
        Array.isArray(step.agesMs) &&
        step.agesMs.length > 0 &&
        step.agesMs.every((age) => isInt(age, 0, MAX_WINDOW_MS))
      );
    case 'sleep':
      return onlyKeys(step, ['op', 'ms']) && isInt(step.ms, 1, 5000);
    default:
      return false;
  }
}

function isVector(value: unknown): boolean {
  return (
    isObject(value) &&
    onlyKeys(value, ['id', 'description', 'config', 'steps']) &&
    typeof value.id === 'string' &&
    /^[a-z0-9]+(-[a-z0-9]+)*$/.test(value.id) &&
    typeof value.description === 'string' &&
    value.description.length > 0 &&
    isObject(value.config) &&
    onlyKeys(value.config, ['limit', 'windowMs']) &&
    isInt(value.config.limit, 1, MAX_LIMIT) &&
    isInt(value.config.windowMs, 1, MAX_WINDOW_MS) &&
    Array.isArray(value.steps) &&
    value.steps.length > 0 &&
    value.steps.every(isStep)
  );
}

const vectorsDir = new URL('../../vectors/', import.meta.url);
const vectorFiles = readdirSync(vectorsDir)
  .filter((file) => file.endsWith('.json'))
  .sort();
const valid = () => ({
  id: 'example',
  description: 'A valid vector; each rejection case changes one part of it.',
  config: { limit: 1, windowMs: 1000 },
  steps: [{ op: 'hit', expect: { allowed: true } }],
});

describe('tests/vectors', () => {
  it('holds at least one vector', () => {
    expect(vectorFiles.length).toBeGreaterThan(0);
  });

  it.each(vectorFiles)('%s is a valid vector named after its id', (file) => {
    const vector: unknown = JSON.parse(readFileSync(new URL(file, vectorsDir), 'utf8'));
    expect(isVector(vector)).toBe(true);
    expect((vector as { id: string }).id).toBe(file.replace(/\.json$/, ''));
  });

  it('accepts the valid example the rejection cases start from', () => {
    expect(isVector(valid())).toBe(true);
  });

  it.each([
    ['an unknown op', { ...valid(), steps: [{ op: 'jump' }] }],
    ['a fractional limit', { ...valid(), config: { limit: 1.5, windowMs: 1000 } }],
    ['a limit above 2^53 - 1', { ...valid(), config: { limit: 2 ** 53, windowMs: 1000 } }],
    ['a window above 365 days', { ...valid(), config: { limit: 1, windowMs: 31_536_000_001 } }],
    ['an unknown property', { ...valid(), expectConfigError: true }],
    [
      'a range with min above max',
      { ...valid(), steps: [{ op: 'hit', expect: { retryAfterMs: { min: 2, max: 1 } } }] },
    ],
    ['an empty expectation', { ...valid(), steps: [{ op: 'hit', expect: {} }] }],
  ])('rejects %s', (_name, vector) => {
    expect(isVector(vector)).toBe(false);
  });
});
