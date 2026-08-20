import { test, expect } from '@playwright/test';

test.describe('Authentification', () => {
  test.describe('Connexion', () => {
    test('affiche le formulaire de connexion', async ({ page }) => {
      await page.goto('/login');

      await expect(page.locator('#username')).toBeVisible();
      await expect(page.locator('#password')).toBeVisible();
      await expect(page.locator('form.input-form button.btn-publish')).toBeVisible();
    });

    test('connexion avec identifiants valides redirige vers le dashboard', async ({ page }) => {
      await page.goto('/login');

      await page.locator('#username').fill('e2e_alice');
      await page.locator('#password').fill('password123');
      await page.locator('form.input-form button.btn-publish').click();

      await expect(page).toHaveURL(/\/channels\//);
    });

    test('connexion avec identifiants invalides affiche une erreur', async ({ page }) => {
      await page.goto('/login');

      await page.locator('#username').fill('e2e_alice');
      await page.locator('#password').fill('wrongpassword');
      await page.locator('form.input-form button.btn-publish').click();

      await expect(page.locator('.error-alert')).toBeVisible();
    });

    test('connexion avec un utilisateur inexistant affiche une erreur', async ({ page }) => {
      await page.goto('/login');

      await page.locator('#username').fill('nonexistent_user');
      await page.locator('#password').fill('password123');
      await page.locator('form.input-form button.btn-publish').click();

      await expect(page.locator('.error-alert')).toBeVisible();
    });

    test('champs requis empêchent la soumission vide', async ({ page }) => {
      await page.goto('/login');

      const usernameInput = page.locator('#username');
      const passwordInput = page.locator('#password');

      await expect(usernameInput).toHaveAttribute('required', '');
      await expect(passwordInput).toHaveAttribute('required', '');
    });
  });

  test.describe('Déconnexion', () => {
    test('déconnexion redirige vers la page de connexion', async ({ page }) => {
      // Login first
      await page.goto('/login');
      await page.locator('#username').fill('e2e_alice');
      await page.locator('#password').fill('password123');
      await page.locator('form.input-form button.btn-publish').click();
      await expect(page).toHaveURL(/\/channels\//);

      // Logout
      await page.goto('/logout');

      await expect(page).toHaveURL(/\/login/);
      await expect(page.locator('#username')).toBeVisible();
    });
  });

  test.describe('Inscription', () => {
    test('affiche le formulaire d\'inscription', async ({ page }) => {
      await page.goto('/register');

      await expect(page.locator('h1')).toContainText('Inscription');
      await expect(page.locator('form.input-form button.btn-publish')).toBeVisible();
    });

    test('inscription avec données valides redirige vers la connexion', async ({ page }) => {
      await page.goto('/register');

      const unique = Date.now();
      await page.locator('input[name="registration_form[username]"]').fill(`e2e_user_${unique}`);
      await page.locator('input[name="registration_form[email]"]').fill(`user_${unique}@e2e.test`);
      await page.locator('input[name="registration_form[plainPassword]"]').fill(`E2e!Test#${unique}`);
      await page.locator('form.input-form button.btn-publish').click();

      await expect(page).toHaveURL(/\/login/);
      await expect(page.locator('.flash-success')).toBeVisible();
    });

    test('inscription avec mot de passe trop court affiche une erreur', async ({ page }) => {
      await page.goto('/register');

      const unique = Date.now();
      await page.locator('input[name="registration_form[username]"]').fill(`e2e_user_${unique}`);
      await page.locator('input[name="registration_form[email]"]').fill(`user_${unique}@e2e.test`);
      await page.locator('input[name="registration_form[plainPassword]"]').fill('123');
      await page.locator('form.input-form button.btn-publish').click();

      await expect(page.locator('.error-alert')).toBeVisible();
    });

    test('lien vers la connexion est présent', async ({ page }) => {
      await page.goto('/register');

      await expect(page.locator('a[href="/login"]')).toBeVisible();
    });
  });
});
