import { test, expect } from '@playwright/test';

test.describe('Tableau Kanban et Canaux Todo', () => {
  let todoChannelSlug = '';

  test.beforeAll(async ({ browser }) => {
    const context = await browser.newContext({ storageState: 'tests/E2E/.auth/alice.json', ignoreHTTPSErrors: true });
    const page = await context.newPage();
    await page.goto('/channels/general');

    const channelName = `todo-${Date.now()}`;

    // Ouvrir la modale de création d'un canal Todo dédié
    await page.locator('button.btn-sidebar-section-add-todo').click();
    await expect(page.locator('#create-channel-modal')).toBeVisible();

    await page.locator('#modal-name').fill(channelName);
    await page.locator('dialog#create-channel-modal button.btn-publish').click();

    await page.waitForURL(new RegExp(`/channels/${channelName}`));
    const url = page.url();
    const match = url.match(/\/channels\/([^\/?#]+)/);
    if (match) {
      todoChannelSlug = match[1];
    }
    await context.close();
  });

  test.beforeEach(async ({ page }) => {
    if (todoChannelSlug) {
      await page.goto(`/channels/${todoChannelSlug}/kanban`);
    } else {
      await page.goto('/channels/general');
    }
  });

  test.describe('Affichage du tableau Kanban', () => {
    test('le bouton Kanban permet de basculer depuis la vue liste', async ({ page }) => {
      await page.goto(`/channels/${todoChannelSlug}`);
      const kanbanToggle = page.locator('a.btn-kanban-toggle');
      await expect(kanbanToggle).toBeVisible();

      await kanbanToggle.click();
      await expect(page).toHaveURL(new RegExp(`/channels/${todoChannelSlug}/kanban`));
      await expect(page.locator('#kanban-board')).toBeVisible();
    });

    test('le tableau Kanban et la colonne Non trié sont visibles', async ({ page }) => {
      const board = page.locator('#kanban-board');
      await expect(board).toBeVisible();

      const untriagedColumn = page.locator('.kanban-column-untriaged');
      await expect(untriagedColumn).toBeVisible();
      await expect(untriagedColumn.locator('.kanban-column-name')).toContainText('Non trié');
    });

    test('ajouter une nouvelle tâche via le chat crée une carte Kanban', async ({ page }) => {
      const taskTitle = `Tâche test ${Date.now()}`;

      await page.locator('#message').fill(taskTitle);
      await page.locator('button.btn-publish-compact').click();

      // La tâche doit apparaître comme une carte dans la colonne non triée
      const card = page.locator('.kanban-card', { hasText: taskTitle });
      await expect(card).toBeVisible();
    });
  });

  test.describe('Gestion des colonnes Kanban', () => {
    test('ajouter une colonne personnalisée au tableau', async ({ page }) => {
      const columnName = `Colonne ${Date.now()}`;

      const addBtn = page.locator('#btn-kanban-add-column');
      await expect(addBtn).toBeVisible();
      await addBtn.click();

      const addForm = page.locator('#kanban-add-column-form');
      await expect(addForm).toBeVisible();

      await addForm.locator('input[name="name"]').fill(columnName);
      await addForm.locator('button[type="submit"]').click();

      // Vérifier que la nouvelle colonne est présente sur le tableau
      const newColumn = page.locator('.kanban-column', { hasText: columnName });
      await expect(newColumn).toBeVisible();
    });
  });

  test.describe('Actions sur les cartes Kanban', () => {
    test('marquer une tâche comme terminée affiche le badge de complétion', async ({ page }) => {
      const taskTitle = `Tâche à compléter ${Date.now()}`;

      await page.locator('#message').fill(taskTitle);
      await page.locator('button.btn-publish-compact').click();

      const card = page.locator('.kanban-card', { hasText: taskTitle });
      await expect(card).toBeVisible();

      const messageId = await card.getAttribute('data-message-id');

      // Ouvrir le menu d'actions de la carte
      await card.locator('.btn-kanban-card-menu').click();

      // Cliquer sur le bouton pour marquer comme terminé
      const completeBtn = page.locator(`#kanban-card-menu-${messageId} .btn-kanban-complete`);
      await expect(completeBtn).toBeVisible();
      await completeBtn.click();

      // Vérifier que la carte est marquée comme terminée
      await expect(card).toHaveClass(/kanban-card-completed/);
      await expect(card.locator('.kanban-card-completed-badge')).toBeVisible();
    });
  });

  test.describe('Déplacement de cartes (Drag & Drop)', () => {
    test('glisser-déposer une carte vers une autre colonne', async ({ page }) => {
      // 1. Créer une colonne cible
      const targetColumnName = `Cible ${Date.now()}`;
      await page.locator('#btn-kanban-add-column').click();
      const addForm = page.locator('#kanban-add-column-form');
      await addForm.locator('input[name="name"]').fill(targetColumnName);
      await addForm.locator('button[type="submit"]').click();

      const targetColumn = page.locator('.kanban-column', { hasText: targetColumnName });
      await expect(targetColumn).toBeVisible();
      const targetDropZone = targetColumn.locator('.kanban-column-body');

      // 2. Créer une carte
      const taskTitle = `Carte à déplacer ${Date.now()}`;
      await page.locator('#message').fill(taskTitle);
      await page.locator('button.btn-publish-compact').click();

      const card = page.locator('.kanban-card', { hasText: taskTitle });
      await expect(card).toBeVisible();

      // 3. Glisser la carte vers la zone de dépôt de la colonne cible
      await card.dragTo(targetDropZone);

      // 4. Vérifier que la carte se trouve dans la colonne cible
      await expect(targetColumn.locator('.kanban-card', { hasText: taskTitle })).toBeVisible();
    });
  });
});
