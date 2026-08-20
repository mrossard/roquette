import { test, expect, type Page } from '@playwright/test';

declare global {
  interface Window {
    openGlobalSearch?: () => void;
    closeGlobalSearch?: () => void;
  }
}

async function openSearchModal(page: Page): Promise<void> {
  const modal = page.locator('#global-search-modal');
  const isVisible = await modal.isVisible().catch(() => false);
  if (!isVisible) {
    await page.locator('#global-search-trigger-btn').click();
    await expect(modal).toBeVisible({ timeout: 10_000 });
  }
}

async function submitSearch(page: Page, query: string): Promise<void> {
  const input = page.locator('#global-search-input');
  await expect(input).toBeVisible();
  await input.focus();
  await input.fill(query);
  await input.press('Enter');
}

test.describe('Recherche globale (Ctrl+K & Modale)', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/channels/general');
    await page.waitForFunction(() => typeof window.openGlobalSearch === 'function');
  });

  test.describe('Ouverture et fermeture de la modale', () => {
    test('le bouton de recherche dans le header ouvre la modale', async ({ page }) => {
      await page.locator('#global-search-trigger-btn').click();
      await expect(page.locator('#global-search-modal')).toBeVisible({ timeout: 10_000 });
    });

    test('le raccourci Ctrl+K ouvre la modale de recherche globale', async ({ page }) => {
      await page.locator('body').click();
      await page.keyboard.press('Control+k');

      const modal = page.locator('#global-search-modal');
      await expect(modal).toBeVisible();
      await expect(page.locator('#global-search-input')).toBeFocused();
    });

    test('la touche Échap ferme la modale', async ({ page }) => {
      await openSearchModal(page);
      await page.keyboard.press('Escape');
      await expect(page.locator('#global-search-modal')).toBeHidden();
    });

    test('le bouton de fermeture X ferme la modale', async ({ page }) => {
      await openSearchModal(page);

      const closeBtn = page.locator('.btn-close-global-search');
      await expect(closeBtn).toBeVisible();
      await closeBtn.click();
      await expect(page.locator('#global-search-modal')).toBeHidden();
    });

    test('la modale affiche les astuces d\'utilisation par défaut', async ({ page }) => {
      await openSearchModal(page);

      await expect(page.locator('.global-search-tips')).toBeVisible();
      await expect(page.locator('#global-search-results')).toContainText('from:');
      await expect(page.locator('#global-search-results')).toContainText('in:');
    });
  });

  test.describe('Recherche par texte', () => {
    test('recherche d\'un message existant affiche le résultat', async ({ page }) => {
      const searchToken = `searchtok_${Date.now()}`;

      // Poster un message avec ce jeton unique
      await page.locator('#message').fill(`Bonjour ceci est un test de recherche ${searchToken}`);
      await page.locator('button.btn-publish-compact').click();
      await expect(page.locator('#live-feed')).toContainText(searchToken);
      await page.waitForTimeout(500);

      // Ouvrir la recherche et chercher le jeton
      await openSearchModal(page);
      await submitSearch(page, searchToken);

      // Le résultat doit être affiché dans la section Messages
      const resultCard = page.locator('#global-search-results .search-message-card', { hasText: searchToken });
      await expect(resultCard).toBeVisible();
    });

    test('recherche d\'un terme inexistant affiche l\'état vide', async ({ page }) => {
      const inexistantToken = `inexistant_${Date.now()}_${Math.random().toString(36).substring(7)}`;

      await openSearchModal(page);
      await submitSearch(page, inexistantToken);

      await expect(page.locator('#global-search-results')).toContainText('Aucun résultat trouvé');
    });
  });

  test.describe('Recherche avec filtres', () => {
    test('filtre from: filtre par auteur', async ({ page }) => {
      const searchToken = `authorfilter_${Date.now()}`;

      await page.locator('#message').fill(`Message filtré par auteur ${searchToken}`);
      await page.locator('button.btn-publish-compact').click();
      await expect(page.locator('#live-feed')).toContainText(searchToken);
      await page.waitForTimeout(500);

      await openSearchModal(page);
      await submitSearch(page, `from:e2e_alice ${searchToken}`);

      const resultCard = page.locator('#global-search-results .search-message-card', { hasText: searchToken });
      await expect(resultCard).toBeVisible();
      await expect(resultCard.locator('.author-name')).toContainText('e2e_alice');
    });

    test('filtre in: filtre par canal', async ({ page }) => {
      const searchToken = `channelfilter_${Date.now()}`;

      await page.locator('#message').fill(`Message filtré par canal ${searchToken}`);
      await page.locator('button.btn-publish-compact').click();
      await expect(page.locator('#live-feed')).toContainText(searchToken);
      await page.waitForTimeout(500);

      await openSearchModal(page);
      await submitSearch(page, `in:general ${searchToken}`);

      const resultCard = page.locator('#global-search-results .search-message-card', { hasText: searchToken });
      await expect(resultCard).toBeVisible();
    });
  });

  test.describe('Navigation depuis les résultats de recherche', () => {
    test('cliquer sur "Aller au message" navigue vers le message', async ({ page }) => {
      const searchToken = `navtest_${Date.now()}`;

      await page.locator('#message').fill(`Test navigation recherche ${searchToken}`);
      await page.locator('button.btn-publish-compact').click();
      await expect(page.locator('#live-feed')).toContainText(searchToken);
      await page.waitForTimeout(500);

      await openSearchModal(page);
      await submitSearch(page, searchToken);

      const resultCard = page.locator('#global-search-results .search-message-card', { hasText: searchToken });
      await expect(resultCard).toBeVisible();

      // Cliquer sur le lien "Aller au message"
      await resultCard.locator('a.btn-jump-to').click();

      // La modale doit être fermée et l'URL doit pointer vers le canal avec jumpTo
      await expect(page.locator('#global-search-modal')).not.toHaveAttribute('open', '');
      await expect(page).toHaveURL(/jumpTo=/);
    });
  });

  test.describe('Assistant de filtres (Search Builder)', () => {
    test('afficher/masquer le panneau d\'assistant de filtres', async ({ page }) => {
      await openSearchModal(page);

      const builderPanel = page.locator('#search-builder-panel');
      await expect(builderPanel).toBeHidden();

      const toggleBtn = page.locator('#btn-toggle-search-builder');
      await expect(toggleBtn).toBeVisible();
      await toggleBtn.click();
      await expect(builderPanel).toBeVisible();

      await toggleBtn.click();
      await expect(builderPanel).toBeHidden();
    });

    test('remplir le texte dans l\'assistant met à jour la barre de recherche', async ({ page }) => {
      await openSearchModal(page);

      const toggleBtn = page.locator('#btn-toggle-search-builder');
      await expect(toggleBtn).toBeVisible();
      await toggleBtn.click();

      await expect(page.locator('#search-builder-panel')).toBeVisible();

      // Remplir le champ texte du builder
      await page.locator('#builder-text').fill('test-query');
      await expect(page.locator('#global-search-input')).toHaveValue('test-query');
    });
  });
});
