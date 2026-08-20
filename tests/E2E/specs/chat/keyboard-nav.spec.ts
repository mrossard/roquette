import { test, expect } from '@playwright/test';

test.describe('Navigation clavier & Édition', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/channels/general');
  });

  test('Flèche Haut dans le champ vide active l\'édition du dernier message', async ({ page }) => {
    // 1. Poster un message
    const messageText = `Message avant édition clavier ${Date.now()}`;
    await page.locator('#message').fill(messageText);
    await page.locator('button.btn-publish-compact').click();
    await expect(page.locator('#live-feed')).toContainText(messageText);

    // 2. Le champ doit être vide et focalisé
    const messageInput = page.locator('#message');
    await expect(messageInput).toHaveValue('');
    await messageInput.focus();

    // 3. Appuyer sur ArrowUp
    await page.keyboard.press('ArrowUp');

    // 4. La zone de texte d'édition inline doit apparaître
    const editTextarea = page.locator('textarea.edit-message-textarea');
    await expect(editTextarea).toBeVisible();
    await expect(editTextarea).toHaveValue(messageText);
  });

  test('Touche Échap annule l\'édition inline', async ({ page }) => {
    // 1. Poster un message
    const messageText = `Message cancel edit ${Date.now()}`;
    await page.locator('#message').fill(messageText);
    await page.locator('button.btn-publish-compact').click();
    await expect(page.locator('#live-feed')).toContainText(messageText);

    // 2. Activer l'édition via ArrowUp
    await page.locator('#message').focus();
    await page.keyboard.press('ArrowUp');

    const editTextarea = page.locator('textarea.edit-message-textarea');
    await expect(editTextarea).toBeVisible();

    // 3. Appuyer sur Échap pour annuler
    await page.keyboard.press('Escape');

    // 4. L'éditeur inline doit disparaître et le focus revenir sur le champ principal
    await expect(editTextarea).not.toBeVisible();
    await expect(page.locator('#message')).toBeFocused();
  });

  test('Édition et sauvegarde du message après navigation clavier', async ({ page }) => {
    // 1. Poster un message
    const originalText = `Original keyboard ${Date.now()}`;
    await page.locator('#message').fill(originalText);
    await page.locator('button.btn-publish-compact').click();
    await expect(page.locator('#live-feed')).toContainText(originalText);

    // 2. Activer l'édition avec ArrowUp
    await page.locator('#message').focus();
    await page.keyboard.press('ArrowUp');

    const editTextarea = page.locator('textarea.edit-message-textarea');
    await expect(editTextarea).toBeVisible();

    // 3. Modifier le contenu et sauvegarder
    const updatedText = `Updated keyboard ${Date.now()}`;
    await editTextarea.fill(updatedText);
    await page.locator('form.edit-message-form button.btn-publish').click();

    // 4. Vérifier que le message a été modifié dans le feed
    await expect(page.locator('#live-feed')).toContainText(updatedText);
    await expect(page.locator('#live-feed')).not.toContainText(originalText);
  });

  test('Alt+Entrée insère un saut de ligne sans envoyer le message', async ({ page }) => {
    const messageInput = page.locator('#message');
    await messageInput.focus();
    await messageInput.fill('Ligne 1');
    await page.keyboard.press('Alt+Enter');
    await page.keyboard.type('Ligne 2');

    await expect(messageInput).toHaveValue('Ligne 1\nLigne 2');
  });
});
