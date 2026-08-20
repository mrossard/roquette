import { test, expect, type BrowserContext, type Page } from '@playwright/test';
import * as path from 'node:path';

const authDir = path.resolve('tests/E2E/.auth');

test.describe('Temps Réel multi-utilisateurs (Mercure SSE)', () => {
  let bobContext: BrowserContext;
  let bobPage: Page;

  test.beforeEach(async ({ browser }) => {
    bobContext = await browser.newContext({
      storageState: path.join(authDir, 'bob.json'),
    });
    bobPage = await bobContext.newPage();
  });

  test.afterEach(async () => {
    await bobContext.close();
  });

  test('réception instantanée d\'un message d\'Alice vers Bob', async ({ page: alicePage }) => {
    await alicePage.goto('/channels/general');
    await bobPage.goto('/channels/general');

    const messageText = `SSE Instant Alice -> Bob ${Date.now()}`;
    await alicePage.locator('#message').fill(messageText);
    await alicePage.locator('button.btn-publish-compact').click();

    // Alice voit son message
    await expect(alicePage.locator('#live-feed')).toContainText(messageText);

    // Bob reçoit le message en temps réel sans rechargement de page
    await expect(bobPage.locator('#live-feed')).toContainText(messageText);
  });

  test('réception instantanée d\'un message de Bob vers Alice', async ({ page: alicePage }) => {
    await alicePage.goto('/channels/general');
    await bobPage.goto('/channels/general');

    const messageText = `SSE Instant Bob -> Alice ${Date.now()}`;
    await bobPage.locator('#message').fill(messageText);
    await bobPage.locator('button.btn-publish-compact').click();

    // Bob voit son message
    await expect(bobPage.locator('#live-feed')).toContainText(messageText);

    // Alice reçoit le message de Bob en temps réel sans rechargement de page
    await expect(alicePage.locator('#live-feed')).toContainText(messageText);
  });

  test('indicateur de frappe en cours (typing indicator) d\'Alice visible par Bob', async ({ page: alicePage }) => {
    await alicePage.goto('/channels/general');
    await bobPage.goto('/channels/general');

    await expect(alicePage.locator('#live-feed')).toBeVisible();
    await expect(bobPage.locator('#live-feed')).toBeVisible();

    await alicePage.waitForTimeout(1000);
    await bobPage.waitForTimeout(1000);

    // Alice commence à taper
    await alicePage.locator('#message').fill('Salut Bob');
    await alicePage.evaluate(() => {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      fetch('/channels/general/typing', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-CSRF-Token': csrf,
        },
        body: new URLSearchParams({ isTyping: '1' }),
      });
    });

    // Bob voit l'indicateur de frappe pour Alice
    await expect(bobPage.locator('#typing-indicator')).toContainText(/alice/i, { timeout: 15_000 });

    // Alice vide son champ et arrête de taper
    await alicePage.locator('#message').fill('');
    await alicePage.evaluate(() => {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      fetch('/channels/general/typing', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-CSRF-Token': csrf,
        },
        body: new URLSearchParams({ isTyping: '0' }),
      });
    });

    // Bob voit l'indicateur de frappe se vider ou redevenir masqué
    await expect(bobPage.locator('#typing-indicator')).not.toContainText(/alice/i, { timeout: 15_000 });
  });

  test('synchronisation en temps réel d\'une réaction emoji', async ({ page: alicePage }) => {
    await alicePage.goto('/channels/general');
    await bobPage.goto('/channels/general');

    // Alice poste un message
    const messageText = `Reaction SSE test ${Date.now()}`;
    await alicePage.locator('#message').fill(messageText);
    await alicePage.locator('button.btn-publish-compact').click();
    await expect(alicePage.locator('#live-feed')).toContainText(messageText);

    // Bob attend l'apparition du message
    const bobFeedItem = bobPage.locator('#live-feed .feed-item', { hasText: messageText });
    await expect(bobFeedItem).toBeVisible();

    const messageId = await bobFeedItem.getAttribute('data-message-id');
    expect(messageId).not.toBeNull();

    // Bob réagit au message avec 👍
    const csrfToken = await bobPage.evaluate(() =>
      document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
    );
    const reactResponse = await bobPage.request.post(`/messages/${messageId}/react/👍`, {
      headers: { 'X-CSRF-Token': csrfToken },
    });
    expect(reactResponse.ok()).toBeTruthy();

    // Alice voit la réaction 👍 en direct sans rafraîchir
    const aliceFeedItem = alicePage.locator(`#feed-item-${messageId}`);
    await expect(aliceFeedItem.locator('.reaction-badge', { hasText: '👍' })).toBeVisible();
    await expect(aliceFeedItem.locator('.reaction-count')).toContainText('1');

    // Bob retire sa réaction (toggle)
    const unreactResponse = await bobPage.request.post(`/messages/${messageId}/react/👍`, {
      headers: { 'X-CSRF-Token': csrfToken },
    });
    expect(unreactResponse.ok()).toBeTruthy();

    // Alice voit la réaction disparaître en direct
    await expect(aliceFeedItem.locator('.reaction-badge')).not.toBeVisible();
  });

  test('suppression de message synchronisée en direct', async ({ page: alicePage }) => {
    await alicePage.goto('/channels/general');
    await bobPage.goto('/channels/general');

    // Alice envoie un message
    const messageText = `Delete target ${Date.now()}`;
    await alicePage.locator('#message').fill(messageText);
    await alicePage.locator('button.btn-publish-compact').click();

    // Bob voit le message
    const bobFeedItem = bobPage.locator('#live-feed .feed-item', { hasText: messageText });
    await expect(bobFeedItem).toBeVisible();
    const messageId = await bobFeedItem.getAttribute('data-message-id');

    // Alice supprime son message
    const csrfToken = await alicePage.evaluate(() =>
      document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
    );
    const deleteResponse = await alicePage.request.post(`/messages/${messageId}/delete`, {
      headers: { 'X-CSRF-Token': csrfToken },
    });
    expect(deleteResponse.status()).toBe(204);

    // Bob voit le message disparaître en direct du feed
    await expect(bobPage.locator(`#feed-item-${messageId}`)).not.toBeVisible();
  });
});
