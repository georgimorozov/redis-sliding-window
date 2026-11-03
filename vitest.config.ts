import { defineConfig } from 'vitest/config';

// Unit and integration tests are split by path (`vitest run tests/ts/unit`, `vitest run
// tests/ts/integration`). A loaded build box can stretch a test past the 5 s default; 30 s still catches a hang.
export default defineConfig({
  test: { testTimeout: 30_000, hookTimeout: 30_000 },
});
