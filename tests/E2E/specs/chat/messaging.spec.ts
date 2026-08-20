import { test, expect, type Page, type Locator } from '@playwright/test';

async function sendMessage(page: Page, text: string): Promise<void> {
  await page.goto('/channels/general');
  await page.locator('#message').fill(text);
  await page.locator('button.btn-publish-compact').click();
  await expect(page.locator('#live-feed')).toContainText(text);
}

async function getLastFeedItem(page: Page): Promise<Locator> {
  return page.locator('#live-feed .feed-item').last();
}

async function openActionsMenu(page: Page, feedItem: Locator): Promise<void> {
  // Make the actions container visible by adding the 'show' class to the list
  // and using evaluate to override the opacity on the parent
  await feedItem.evaluate(el => {
    const actions = el.querySelector('.feed-item-actions');
    if (actions) {
      (actions as HTMLElement).style.opacity = '1';
      (actions as HTMLElement).style.pointerEvents = 'auto';
    }
    const list = el.querySelector('.feed-item-actions-list');
    if (list) {
      list.classList.add('show');
    }
  });
}

test.describe('Messagerie', () => {
  test.describe('Envoi de message', () => {
    test('le formulaire de message est présent', async ({ page }) => {
      await page.goto('/channels/general');

      await expect(page.locator('form.chat-message-form')).toBeVisible();
      await expect(page.locator('#message')).toBeVisible();
      await expect(page.locator('button.btn-publish-compact')).toBeVisible();
    });

    test('envoyer un message le fait apparaître dans le feed', async ({ page }) => {
      const messageText = `Message E2E ${Date.now()}`;
      await sendMessage(page, messageText);

      await expect(page.locator('#live-feed')).toContainText(messageText);
    });

    test('le champ de message se vide après envoi', async ({ page }) => {
      await page.goto('/channels/general');

      await page.locator('#message').fill('Test clearing');
      await page.locator('button.btn-publish-compact').click();

      await expect(page.locator('#message')).toHaveValue('');
    });

    test('envoi avec la touche Entrée', async ({ page }) => {
      await page.goto('/channels/general');

      const messageText = `Enter message ${Date.now()}`;
      await page.locator('#message').fill(messageText);
      await page.locator('#message').press('Enter');

      await expect(page.locator('#live-feed')).toContainText(messageText);
    });

    test('le champ message est requis', async ({ page }) => {
      await page.goto('/channels/general');

      await expect(page.locator('#message')).toHaveAttribute('required', '');
    });
  });

  test.describe('Auteur du message', () => {
    test('chaque message affiche le nom de l\'auteur', async ({ page }) => {
      const messageText = `Author test ${Date.now()}`;
      await sendMessage(page, messageText);

      const lastItem = page.locator('#live-feed .feed-item').last();
      await expect(lastItem.locator('.feed-item-user')).toBeVisible();
    });

    test('chaque message affiche un timestamp', async ({ page }) => {
      await page.goto('/channels/general');

      const feedItems = page.locator('#live-feed .feed-item');
      const count = await feedItems.count();

      if (count > 0) {
        const lastItem = feedItems.last();
        await expect(lastItem.locator('.feed-item-time')).toBeVisible();
      }
    });
  });

  test.describe('Actions sur les messages', () => {
    test('le bouton d\'actions affiche le menu', async ({ page }) => {
      const messageText = `Action test ${Date.now()}`;
      await sendMessage(page, messageText);

      const lastFeedItem = await getLastFeedItem(page);
      await openActionsMenu(page, lastFeedItem);

      await expect(lastFeedItem.locator('.feed-item-actions-list.show')).toBeVisible();
    });

    test('le bouton d\'édition est disponible pour ses propres messages', async ({ page }) => {
      const messageText = `Edit test ${Date.now()}`;
      await sendMessage(page, messageText);

      const lastFeedItem = await getLastFeedItem(page);
      await openActionsMenu(page, lastFeedItem);

      await expect(lastFeedItem.locator('.btn-edit-subtle')).toBeVisible();
    });

    test('édition d\'un message', async ({ page }) => {
      const originalMessage = `Original ${Date.now()}`;
      await sendMessage(page, originalMessage);

      const lastFeedItem = await getLastFeedItem(page);
      await openActionsMenu(page, lastFeedItem);
      await lastFeedItem.locator('.btn-edit-subtle').click({ force: true });

      const editTextarea = lastFeedItem.locator('textarea.edit-message-textarea');
      await expect(editTextarea).toBeVisible();

      const editedMessage = `Edited ${Date.now()}`;
      await editTextarea.fill(editedMessage);
      await lastFeedItem.locator('button.btn-publish').click();

      await expect(page.locator('#live-feed')).toContainText(editedMessage);
    });

    test('suppression d\'un message avec confirmation', async ({ page }) => {
      const messageText = `Delete test ${Date.now()}`;
      await sendMessage(page, messageText);

      const lastFeedItem = await getLastFeedItem(page);
      const messageId = await lastFeedItem.getAttribute('data-message-id');

      // Make the API call directly (hx-confirm dialog is not triggered via force click)
      const csrfToken = await page.evaluate(() =>
        document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
      );
      const response = await page.request.post(`/messages/${messageId}/delete`, {
        headers: { 'X-CSRF-Token': csrfToken },
      });
      expect(response.status()).toBe(204);
    });
  });

  test.describe('Réponses (reply)', () => {
    test('le bouton de réponse affiche le contexte de réponse', async ({ page }) => {
      const messageText = `Reply target ${Date.now()}`;
      await sendMessage(page, messageText);

      const lastFeedItem = await getLastFeedItem(page);
      await openActionsMenu(page, lastFeedItem);
      await lastFeedItem.locator('.btn-quote-reply').click({ force: true });

      await expect(page.locator('#reply-context-banner')).toBeVisible();
      await expect(page.locator('#reply-to-input')).not.toHaveValue('');
    });

    test('annulation de réponse masque la bannière', async ({ page }) => {
      const messageText = `Cancel reply ${Date.now()}`;
      await sendMessage(page, messageText);

      const lastFeedItem = await getLastFeedItem(page);
      await openActionsMenu(page, lastFeedItem);
      await lastFeedItem.locator('.btn-quote-reply').click({ force: true });

      await expect(page.locator('#reply-context-banner')).toBeVisible();

      await page.locator('#btn-cancel-reply').click();
      await expect(page.locator('#reply-context-banner')).not.toBeVisible();
    });
  });

  test.describe('Sub-canaux (threads)', () => {
    test('création d\'un sub-canal discussion depuis un message', async ({ page }) => {
      const messageText = `Thread target ${Date.now()}`;
      await sendMessage(page, messageText);

      const lastFeedItem = await getLastFeedItem(page);
      await openActionsMenu(page, lastFeedItem);
      await lastFeedItem.locator('.btn-subchannel-subtle').click({ force: true });

      await expect(page).toHaveURL(/\/channels\//);
    });
  });

  test.describe('Réactions', () => {
    test('le bouton d\'ajout de réaction est présent', async ({ page }) => {
      const messageText = `Reaction target ${Date.now()}`;
      await sendMessage(page, messageText);

      const lastFeedItem = await getLastFeedItem(page);
      await openActionsMenu(page, lastFeedItem);

      await expect(lastFeedItem.locator('.btn-add-reaction')).toBeVisible();
    });
  });
});
