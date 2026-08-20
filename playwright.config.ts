import { defineConfig, devices } from '@playwright/test';
import * as path from 'node:path';

const authDir = path.resolve('tests/E2E/.auth');

export default defineConfig({
  testDir: './tests/E2E/specs',
  timeout: 30_000,
  expect: {
    timeout: 10_000,
  },
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: 1,
  reporter: [
    ['html', { open: 'never', outputFolder: 'playwright-report' }],
    ['list'],
  ],
  use: {
    baseURL: process.env.APP_BASE_URL || 'http://localhost',
    ignoreHTTPSErrors: true,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  projects: [
    {
      name: 'setup',
      testDir: './tests/E2E/fixtures',
      testMatch: /auth\.setup\.ts/,
    },
    {
      name: 'unauthenticated',
      testDir: './tests/E2E/specs/auth',
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'channels',
      testDir: './tests/E2E/specs/channels',
      use: {
        ...devices['Desktop Chrome'],
        storageState: path.join(authDir, 'alice.json'),
      },
      dependencies: ['setup'],
    },
    {
      name: 'chat',
      testDir: './tests/E2E/specs/chat',
      use: {
        ...devices['Desktop Chrome'],
        storageState: path.join(authDir, 'alice.json'),
      },
      dependencies: ['setup'],
    },
  ],
});
