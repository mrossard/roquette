# Plan de refactoring — Roquette

## Phase 1 — Quick wins (risque faible, ~aucune dépendance entre items)

- [x] **1.1 Supprimer le code mort**
  - Supprimé `src/Ai/AiContextBuilder.php` (commit `8be4e67`).

- [x] **1.2 Réhabiliter `AbstractAiTool`**
  - `CreatePollTool`, `SummarizeChannelTool`, `ScheduleReminderTool` passent de `implements AiToolInterface` → `extends AbstractAiTool` (commit `f3a83b4`).
  - Utilisation de `resolveChannelAndCheckAccess()` pour la résolution de canal et la vérification des permissions.
  - Nettoyage du double lookup dans `ScheduleReminderTool` et passage par `RobotUserProvider`.

- [x] **1.3 Brancher `RobotUserProvider`**
  - Ajout des méthodes `getDmChannelSlug(User): string` et `isRobotDmChannel(string): bool` (commit `82dd7e4`).
  - Centralisation des lookups utilisateur robot et des slugs de canaux DM robot à travers l'application.

- [x] **1.4 Extraire `LlmRateLimiter`**
  - Service `LlmRateLimiter` introduit avec `consume(User): bool` et `consumeConfirmation(User): bool` (commit `82c3182`).
  - Unification de la gestion du rate limiting dans `MessagePublishService`, `HelpSlashCommand`, `PollSlashCommand`, `PendingConfirmationService`.

- [x] **1.5 Consolider les lookup de canaux/workspaces**
  - Création de `ChannelAccessTrait` (`findAuthorizedChannel()`, `authorizeChannelAccess()`, `authorizeMessageAccess()`) et usage généralisé de `ChannelManager` / `WorkspaceManager` dans les contrôleurs (commit `7bf650d`).

---

## Phase 2 — Consolidation de la duplication métier

- [x] **2.1 `MessageBroadcaster` (cœur du pipeline message)**
  - Centralisation de `renderFeedItem(['oob' => true]) + publishToChannel('message_' . $slug)` et de `publishCurrentModerationCount()`.
  - Suppression de la duplication `renderMessageItem` dans `MessagePinController`.

- [ ] **2.2 `PollFactory`/`PollUpdater`**
  - 3 constructions de `Poll`+`PollOption` (MessagePublishService, MessageManager, CreatePollTool) + 2 validations « ≥2 options ».

- [ ] **2.3 `MessageFeedContextService`**
  - Bloc `array_map(getId()) + findReplyCounts + findSubchannelsByChannel` (7× : ChannelController, SearchController, MessageController).

- [ ] **2.4 `RobotDmMessageService`**
  - Fusionne `LlmQueryHandler::persistRobotDmMessage` et `PendingConfirmationService::updateRobotDmMessageHistory`.

- [ ] **2.5 `MessagePromptFormatter` + `JsonExtractor`**
  - Un seul format messages→texte LLM (4 implémentations divergentes) ; `JsonExtractor::parse()` pour les fences ```json (2×).

- [ ] **2.6 Pagination admin + helpers HX**
  - Trait `AdminPaginatedControllerTrait` (6 controllers, PER_PAGE=25).
  - `redirectOrHxRedirect()` / `activeChannelFromHxRequest()` (dupliqués dans ChannelMembership, SubChannel, Invitation, ChannelAction, ChannelController).

---

## Phase 3 — God classes (risque moyen/élevé)

- [ ] **3.1 `MessagePublishService` (380 L, `publish()` = 63 L, 6 early-returns)**
  - Extraire `RobotInteractionService` (mention robot, rate-limit, dispatch `LlmQueryMessage`, rendu OOB — incluant l'entité `Message` transitoire utilisée comme DTO) + `MessageFactory` (build + attachPoll).
  - Remplacer la requête lourde `findLatestInChannel` sur chaque publish par une requête légère.

- [ ] **3.2 `MessageManager` (9 deps, 4 responsabilités)**
  - Scinder en edit/delete/save + extraire le broadcast dupliqué `editText`/`editPoll`.

- [ ] **3.3 `LlmQueryHandler` (18 deps) — avec bug latent**
  - `StreamResponseCoordinator` déjà extrait (commit `34f9013`).
  - Remplacer les tuples positionnels `[prompt, systemPrompt, prefix, batches]` par des value objects (le batching peut envoyer un **prompt vide** si 1 seul batch).
  - Extraire `persistRobotDmMessage`.

- [ ] **3.4 `PendingConfirmationService` (5 deps nullable, cycle non cassé)**
  - Casser le cycle structurellement (tool execution par événement, ou split `ToolRegistry`), pas par nullabilité → **retirer les 5 `?`** ; supprimer le `mercureTopicPrefix` en dur (dérive de l'env).

---

## Phase 4 — Dettes architecturales (au fil de l'eau)

- [ ] **4.1** `AssistantIntent` enum + constantes de noms d'outils (strings magiques `'help'|'resumer'|'sondage'`).
- [ ] **4.2** Scinder `ChannelActionController` (9 actions) / `ChannelController::channel()` / parseur de DSL de recherche.
- [ ] **4.3** DM : déplacer la création `openDm()` du controller vers `ChannelManager` + unifier les 2 conventions de slug (`dm-{id}-{id}` vs `dm-robot-{slug}`).
- [ ] **4.4** `ChannelGroupController` : supprimer `$this->forward('ModalController::editModal')`, logique dans `GroupSubscriptionManager`.
- [ ] **4.5** Réconcilier les 2 conventions d'erreur (`PublishResult` vs catch `HttpExceptionInterface`) entre MessageSubmissionHandler et MessageController.
- [ ] **4.6** `MessageFormatter` : rendre les 4 processors requis (les `new` fallback sont du code mort en prod).
- [ ] **4.7** Perf : `HelpStreamPublisher` O(n²) (reformat complet à chaque chunk), `ChannelResolver::findAll()` fallback.
- [ ] **4.8** `MercurePublisher` : `encodePayload()` privé (3× dupliqué), dédupliquer topic construction via HelpStreamPublisher/AppExtension.

---

## Vérification

- Chaque phase : `vendor/bin/mago` (lint/format) + `bin/phpunit` + tests fonctionnels ciblés.
- Phase 1 et 2 en PRs séparées ; Phase 3 par service, avec tests existants comme filet (MessagePublishService, LlmQueryHandler, PendingConfirmationService ont déjà des tests).
