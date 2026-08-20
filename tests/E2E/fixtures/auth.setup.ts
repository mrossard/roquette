import { test as setup, expect } from '@playwright/test';
import * as path from 'node:path';
import * as fs from 'node:fs';

const authDir = path.resolve('tests/E2E/.auth');
if (!fs.existsSync(authDir)) {
  fs.mkdirSync(authDir, { recursive: true });
}

const users = [
  { username: 'e2e_alice', password: 'password123', file: 'alice.json' },
  { username: 'e2e_bob', password: 'password123', file: 'bob.json' },
  { username: 'e2e_admin', password: 'password123', file: 'admin.json' },
];

for (const user of users) {
  setup(`Authentifier ${user.username}`, async ({ page }) => {
    await page.goto('/login');
    await page.locator('#username').fill(user.username);
    await page.locator('#password').fill(user.password);
    await page.locator('form.input-form button.btn-publish').click();

    // Login succeeds when we're redirected away from /login to a /channels/ URL
    await expect(page).toHaveURL(/\/channels\//, { timeout: 15_000 });

    const storagePath = path.join(authDir, user.file);
    await page.context().storageState({ path: storagePath });
  });
}
