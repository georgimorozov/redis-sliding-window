import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

// Negative controls for scripts/check-pack.mjs: a checker that stopped rejecting would still pass the
// gate's package step, so each bad file list must exit non-zero before the import.
const script = fileURLToPath(new URL('../../../scripts/check-pack.mjs', import.meta.url));
const required = ['package.json', 'dist/index.js', 'dist/index.d.ts', 'lua/sliding-window.lua'];
const run = (paths: string[]) =>
  spawnSync(process.execPath, [script], {
    input: JSON.stringify([{ files: paths.map((path) => ({ path })) }]),
    encoding: 'utf8',
  });

describe('scripts/check-pack.mjs', () => {
  it.each([
    ['a missing dist/index.d.ts', required.filter((path) => path !== 'dist/index.d.ts')],
    ['a missing Lua script', required.filter((path) => path !== 'lua/sliding-window.lua')],
    ['a missing package.json', required.filter((path) => path !== 'package.json')],
    ['a shipped source file', [...required, 'src/index.ts']],
    ['a shipped test file', [...required, 'tests/ts/unit/vectors.test.ts']],
    ['a shipped PHP file', [...required, 'php/src/FailMode.php']],
  ])('rejects %s', (_name, paths) => {
    const result = run(paths);
    expect(result.status).toBe(1);
    expect(result.stderr).toContain('check-pack: missing');
  });
});
