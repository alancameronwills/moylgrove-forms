import { defineConfig, devices } from '@playwright/test';

// Runs against the WordPress on the local UniServer (Apache + MySQL must be running).
// Tests share one database, so they run one at a time.
export default defineConfig({
  testDir: 'specs',
  globalSetup: './global-setup.ts',
  globalTeardown: './global-teardown.ts',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 60_000, // the dev site takes ~3s per page
  expect: { timeout: 10_000 },
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: process.env.MG_BASE_URL || 'http://localhost',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
