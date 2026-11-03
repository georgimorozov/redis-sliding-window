// @ts-check
import { defineConfig, globalIgnores } from 'eslint/config';
import js from '@eslint/js';
import tseslint from 'typescript-eslint';
import comments from '@eslint-community/eslint-plugin-eslint-comments/configs';
import eslintConfigPrettier from 'eslint-config-prettier/flat';

// defineConfig flattens config arrays. ESLint ignores node_modules/ and .git/ by default.
export default defineConfig(
  globalIgnores(['dist/', 'coverage/', 'vendor/']),
  js.configs.recommended,
  tseslint.configs.recommended,
  // Every eslint-disable directive carries a "-- <reason>", and a disable block needs its enable.
  comments.recommended,
  {
    rules: {
      '@eslint-community/eslint-comments/require-description': [
        'error',
        { ignore: ['eslint-enable'] },
      ],
      '@eslint-community/eslint-comments/disable-enable-pair': 'error',
    },
  },
  {
    // Swallowed errors: no empty blocks (catch included), no floating or misused promises.
    files: ['**/*.ts'],
    languageOptions: {
      parserOptions: { projectService: true, tsconfigRootDir: import.meta.dirname },
    },
    rules: {
      'no-undef': 'off',
      'no-empty': 'error',
      '@typescript-eslint/no-floating-promises': 'error',
      '@typescript-eslint/no-misused-promises': 'error',
      '@typescript-eslint/no-unused-vars': [
        'error',
        { argsIgnorePattern: '^_', varsIgnorePattern: '^_' },
      ],
    },
  },
  // The library reports through the caller's onError callback, never by printing.
  { files: ['src/**/*.ts'], rules: { 'no-console': 'error' } },
  eslintConfigPrettier,
);
