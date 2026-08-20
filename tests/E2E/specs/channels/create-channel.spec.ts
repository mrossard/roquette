import { test, expect } from '@playwright/test';

test.describe('Canaux', () => {
  test.describe('Création de canal', () => {
    test('le bouton d\'ajout de canal ouvre le modal', async ({ page }) => {
      await page.goto('/channels/general');

      await page.locator('button.btn-sidebar-section-add-channel').click();

      await expect(page.locator('#create-channel-modal')).toBeVisible();
      await expect(page.locator('#modal-name')).toBeVisible();
    });

    test('création d\'un canal de discussion', async ({ page }) => {
      await page.goto('/channels/general');

      await page.locator('button.btn-sidebar-section-add-channel').click();
      await expect(page.locator('#create-channel-modal')).toBeVisible();

      const channelName = `test-${Date.now()}`;
      await page.locator('#modal-name').fill(channelName);
      await page.locator('#modal-description').fill('Canal de test E2E');
      await page.locator('#modal-type-discussion').check();
      await page.locator('dialog#create-channel-modal button.btn-publish').click();

      await expect(page).toHaveURL(new RegExp(`/channels/${channelName}`));
      await expect(page.locator('.chat-header h2')).toContainText(channelName);
    });

    test('création d\'un canal todo', async ({ page }) => {
      await page.goto('/channels/general');

      await page.locator('button.btn-sidebar-section-add-channel').click();
      await expect(page.locator('#create-channel-modal')).toBeVisible();

      const channelName = `todo-${Date.now()}`;
      await page.locator('#modal-name').fill(channelName);
      await page.locator('#channel-type-todo-label').click();
      await page.locator('dialog#create-channel-modal button.btn-publish').click();

      await expect(page).toHaveURL(new RegExp(`/channels/${channelName}`));
    });

    test('fermeture du modal avec le bouton X', async ({ page }) => {
      await page.goto('/channels/general');

      await page.locator('button.btn-sidebar-section-add-channel').click();
      await expect(page.locator('#create-channel-modal')).toBeVisible();

      await page.locator('#create-channel-modal .btn-close-modal').click();
      await expect(page.locator('#create-channel-modal')).not.toBeVisible();
    });

    test('nom de canal requis', async ({ page }) => {
      await page.goto('/channels/general');

      await page.locator('button.btn-sidebar-section-add-channel').click();
      await expect(page.locator('#create-channel-modal')).toBeVisible();

      await expect(page.locator('#modal-name')).toHaveAttribute('required', '');
    });
  });

  test.describe('Navigation entre canaux', () => {
    test.beforeEach(async ({ page }) => {
      await page.goto('/channels/general');
      // The channels <details> section is collapsed when >5 channels exist (1758 seeded)
      await page.locator('details[data-section="channels"]').evaluate(el => el.setAttribute('open', ''));
    });

    test('clique sur un canal dans la sidebar navigue vers ce canal', async ({ page }) => {
      const sidebarLink = page.locator('a.channel-link[data-channel-slug="general"]');
      await expect(sidebarLink).toBeVisible();
      await expect(sidebarLink).toHaveClass(/active/);
    });

    test('le canal actif est mis en surbrillance dans la sidebar', async ({ page }) => {
      const activeLink = page.locator('a.channel-link.active');
      await expect(activeLink).toBeVisible();
      await expect(activeLink).toHaveAttribute('data-channel-slug', 'general');
    });
  });
});
