import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    include: ['tests/unit/**/*.test.js'],
    exclude: ['tests/e2e/**', 'node_modules/**'],
    coverage: {
      provider: 'v8',
      include: [
        'src/**/*.js',
        'services/**/*.js',
      ],
      exclude: [
        'node_modules/**',
        'tests/**',
        'packages/**',
        'auditandfix.com/**',
      ],
      thresholds: {
        lines: 85,
        functions: 85,
        branches: 85,
        statements: 85,
      },
    },
  },
});
