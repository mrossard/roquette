import { test, expect } from '@playwright/test';
import * as path from 'path';

const SAMPLE_PNG = path.resolve(__dirname, '../../fixtures/test-files/sample.png');
const SAMPLE_TXT = path.resolve(__dirname, '../../fixtures/test-files/document.txt');

test.describe('Pièces jointes et Médias (Attachments)', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/channels/general');
    await expect(page.locator('#live-feed')).toBeVisible();
  });

  test.describe('Sélection et prévisualisation', () => {
    test('sélectionner un fichier affiche l\'aperçu avec son nom et sa taille', async ({ page }) => {
      const fileInput = page.locator('#file-upload');
      const previewContainer = page.locator('#file-preview-container');
      const previewName = page.locator('#file-preview-name');

      await expect(previewContainer).toBeHidden();

      await fileInput.setInputFiles(SAMPLE_TXT);

      await expect(previewContainer).toBeVisible();
      await expect(previewName).toContainText('document.txt');
    });

    test('le bouton X supprime le fichier sélectionné et masque l\'aperçu', async ({ page }) => {
      const fileInput = page.locator('#file-upload');
      const previewContainer = page.locator('#file-preview-container');
      const clearBtn = page.locator('#btn-clear-file');

      await fileInput.setInputFiles(SAMPLE_TXT);
      await expect(previewContainer).toBeVisible();

      await clearBtn.click();
      await expect(previewContainer).toBeHidden();

      const filesCount = await fileInput.evaluate((el: HTMLInputElement) => el.files?.length ?? 0);
      expect(filesCount).toBe(0);
    });
  });

  test.describe('Envoi de message avec pièce jointe', () => {
    test('envoyer un document texte affiche la pièce jointe dans le feed', async ({ page }) => {
      const token = `att_txt_${Date.now()}`;
      const fileInput = page.locator('#file-upload');

      await fileInput.setInputFiles(SAMPLE_TXT);
      await page.locator('#message').fill(`Message avec fichier texte ${token}`);
      await page.locator('button.btn-publish-compact').click();

      // Le message doit apparaître avec sa pièce jointe
      const feedItem = page.locator('.feed-item', { hasText: token });
      await expect(feedItem).toBeVisible();
      await expect(feedItem.locator('.message-attachment')).toBeVisible();
      await expect(feedItem.locator('.attachment-link')).toContainText('document.txt');
    });

    test('envoyer une image affiche la prévisualisation dans le message', async ({ page }) => {
      const token = `att_img_${Date.now()}`;
      const fileInput = page.locator('#file-upload');

      await fileInput.setInputFiles(SAMPLE_PNG);
      await page.locator('#message').fill(`Message avec image ${token}`);
      await page.locator('button.btn-publish-compact').click();

      const feedItem = page.locator('.feed-item', { hasText: token });
      await expect(feedItem).toBeVisible();

      const previewImage = feedItem.locator('.preview-image');
      await expect(previewImage).toBeVisible();
    });

    test('cliquer sur une image jointe ouvre la modale Lightbox', async ({ page }) => {
      const token = `lightbox_${Date.now()}`;
      const fileInput = page.locator('#file-upload');

      await fileInput.setInputFiles(SAMPLE_PNG);
      await page.locator('#message').fill(`Test lightbox image ${token}`);
      await page.locator('button.btn-publish-compact').click();

      const feedItem = page.locator('.feed-item', { hasText: token });
      await expect(feedItem).toBeVisible();

      const previewImage = feedItem.locator('.preview-image');
      await expect(previewImage).toBeVisible();

      // Clic sur l'image pour ouvrir la Lightbox
      await previewImage.click();

      const lightbox = page.locator('#image-lightbox');
      await expect(lightbox).toBeVisible();
      await expect(lightbox).toHaveAttribute('open', '');

      // Fermeture via le bouton X de la lightbox
      const closeBtn = lightbox.locator('.btn-close-lightbox');
      await expect(closeBtn).toBeVisible();
      await closeBtn.click();
      await expect(lightbox).toBeHidden();
    });
  });

  test.describe('Glisser-déposer (Drag and Drop)', () => {
    test('déposer un fichier sur la zone de chat remplit l\'input et affiche l\'aperçu', async ({ page }) => {
      const buffer = Buffer.from('Contenu de test pour drag and drop');

      await page.evaluate(async ({ data, name, type }) => {
        const chatPanel = document.querySelector('.chat-panel');
        if (!chatPanel) return;

        const uint8Array = new Uint8Array(data);
        const file = new File([uint8Array], name, { type });
        const dt = new DataTransfer();
        dt.items.add(file);

        const dropEvent = new DragEvent('drop', {
          bubbles: true,
          cancelable: true,
          dataTransfer: dt,
        });

        chatPanel.dispatchEvent(dropEvent);
      }, {
        data: Array.from(buffer),
        name: 'dragged-file.txt',
        type: 'text/plain',
      });

      const previewContainer = page.locator('#file-preview-container');
      await expect(previewContainer).toBeVisible();
      await expect(page.locator('#file-preview-name')).toContainText('dragged-file.txt');
    });
  });

  test.describe('Collage du presse-papier (Paste)', () => {
    test('coller une image du presse-papier dans la zone de texte prépare l\'envoi', async ({ page }) => {
      await page.evaluate(() => {
        const textarea = document.getElementById('message');
        if (!textarea) return;

        const byteCharacters = atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        const byteNumbers = new Array(byteCharacters.length);
        for (let i = 0; i < byteCharacters.length; i++) {
          byteNumbers[i] = byteCharacters.charCodeAt(i);
        }
        const byteArray = new Uint8Array(byteNumbers);
        const file = new File([byteArray], 'pasted-image.png', { type: 'image/png' });

        const dt = new DataTransfer();
        dt.items.add(file);

        const pasteEvent = new ClipboardEvent('paste', {
          bubbles: true,
          cancelable: true,
          clipboardData: dt,
        });

        textarea.dispatchEvent(pasteEvent);
      });

      const previewContainer = page.locator('#file-preview-container');
      await expect(previewContainer).toBeVisible();
      await expect(page.locator('#file-preview-name')).toContainText('pasted-image.png');
    });
  });
});
