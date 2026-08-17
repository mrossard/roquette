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

- [x] **2.2 `PollFactory`/`PollUpdater`**
  - Service `PollFactory` unifiant la validation, la construction initiale et la mise à jour différentielle de `Poll` + `PollOption` dans `MessagePublishService`, `MessageManager` et `CreatePollTool`.

- [x] **2.3 `MessageFeedContextService`**
  - Centralisation de l'agrégation de contexte de feed (`replyCounts`, `subchannelByParentMessageId`, `savedMessageIds`) dans `MessageFeedContextService` pour `ChannelController`, `SearchController` et `MessageController`.

- [x] **2.4 `RobotDmMessageService`**
  - Centralisation de la persistance et mise à jour de l'historique DM du robot dans `RobotDmMessageService` pour `LlmQueryHandler` et `PendingConfirmationService`.

- [x] **2.5 `MessagePromptFormatter` + `JsonExtractor`**
  - Unification du formatage des messages en texte/structure pour les prompts LLM (`MessagePromptFormatter`) et centralisation du parsing JSON avec nettoyage de markdown fences (`JsonExtractor`).

- [x] **2.6 Pagination admin + helpers HX**
  - Trait `AdminPaginationTrait` (7 contrôleurs admin dont `AdminWorkspaceController`, pagination et calcul de pages unifiés).
  - Trait `HxControllerTrait` (`redirectOrHxRedirect()` et `findActiveChannelFromHxRequest()`, intégrés dans `ChannelMembershipController`, `SubChannelController`, `InvitationController`, `ChannelActionController` et `ChannelController`).

---

## Phase 3 — God classes (risque moyen/élevé)

- [x] **3.1 `MessagePublishService` (380 L, `publish()` = 63 L, 6 early-returns)**
  - Extraction de `RobotInteractionService` (détection mention robot, rate limiting, confirmations, dispatch `LlmQueryMessage`, rendu OOB) et `MessageFactory` (instanciation du `Message` et attachement de fichiers).
  - Remplacement de la requête lourde `findLatestInChannel` par `findPreviousMessage` sur chaque publication.

- [x] **3.2 `MessageManager` (9 deps, 4 responsabilités)**
  - Scission en `MessageEditor` (édition unifiée texte + sondage et broadcast), `MessageDeletionService` (suppression sécurisée, nettoyage fichiers, dé-épinglage et broadcast) et `SavedMessageService` (sauvegarde/favoris).
  - `MessageManager` refactorisé en façade légère.

- [x] **3.3 `LlmQueryHandler` (18 deps) — avec bug latent**
  - `StreamResponseCoordinator` et `RobotDmMessageService` extraits.
  - Remplacement des tuples positionnels par les Value Objects `SummaryPromptResult` et `LlmPromptBundle`.
  - Correction du bug latent de prompt vide quand un seul batch résultait du plafonnement.

- [x] **3.4 `PendingConfirmationService` (5 deps nullable, cycle non cassé)**
  - Suppression de la dépendance inutilisée `RobotUserProvider`.
  - Retrait des 5 `?` nullables dans `PendingConfirmationService`, `LlmQueryHandler`, `RobotInteractionService` et `HelpSlashCommand`.
  - Injection autowirée de `MERCURE_TOPIC_PREFIX`.

---

## Phase 4 — Dettes architecturales (au fil de l'eau)

- [x] **4.1** `AssistantIntent` enum + constantes de noms d'outils (strings magiques `'help'|'resumer'|'sondage'`).
  - Création de l'enum typé `AssistantIntent`.
  - Définition des constantes `NAME` sur chaque outil IA.
  - Typage de `LlmQueryMessage`, `IntentClassifier`, `LlmIntentClassifier`, `LlmQueryHandler`, `ChannelActionController`, `HelpSlashCommand`, `PollSlashCommand`.
- [x] **4.2** Scinder `ChannelActionController` (9 actions) / `ChannelController::channel()` / parseur de DSL de recherche.
  - Extraction de `MessageSearchParser` et du Value Object `ParsedSearchQuery` avec tests unitaires complets.
  - Extraction de `ChannelExportController` (`/channels/{slug}/export` et `/exports/{id}/download`).
- [x] **4.3** DM : déplacer la création `openDm()` du controller vers `ChannelManager` + unifier les 2 conventions de slug (`dm-{id}-{id}` vs `dm-robot-{slug}`).
  - Méthodes `getOrCreateDm` et `generateDmSlug` ajoutées à `ChannelManager`.
  - Allègement de `ChannelMembershipController::openDm`.
- [x] **4.4** `ChannelGroupController` : supprimer `$this->forward('ModalController::editModal')`, logique dans `GroupSubscriptionManager`.
  - Logique `subscribe()`, `unsubscribe()` et `getResolvedSubscriptions()` centralisée dans `GroupSubscriptionManager`.
  - Service `ChannelEditModalDataProvider` créé pour découpler le rendu de la modale d'édition.
  - Suppression de tout `$this->forward()` dans `ChannelGroupController`.
- [x] **4.5** Réconcilier les 2 conventions d'erreur (`PublishResult` vs catch `HttpExceptionInterface`) entre MessageSubmissionHandler et MessageController.
  - Création du Value Object `EditResult` symétrique à `PublishResult`.
  - Harmonisation de `MessageEditor` et `MessageManager` pour retourner `EditResult` avec message chargé et statut d'erreur.
  - Élimination des `try/catch` avec double requête SQL de rattrapage dans `MessageController::editMessage`.
  - Tests unitaires et fonctionnels complets.
- [x] **4.6** `MessageFormatter` : rendre les 4 processors requis (les `new` fallback sont du code mort en prod).
  - Rendre `EmoticonProcessor`, `EmojiProcessor`, `MentionProcessor` et `HtmlDecorator` obligatoires et typés dans le constructeur de `MessageFormatter`.
  - Suppression des dépendances transitives inutiles (`Security`, `$emojiBaseUrl`, `ChannelRepository`, `UserRepository`).
  - Nettoyage des tests unitaires dans `MessageFormatterTest`.
- [x] **4.7** Perf : `HelpStreamPublisher` O(n²) (reformat complet à chaque chunk), `ChannelResolver::findAll()` fallback.
  - Ajout de `ChannelRepository::findOneByNameOrSlugFuzzy()` et suppression du `findAll()` en boucle dans `ChannelResolver`.
  - Scission en `HelpStreamPublisher::publishStreamChunk()` (rendu léger instantané sans DB) et `publishStreamText()` (rendu riche final).
  - Tests unitaires complets dans `ChannelResolverTest` et `StreamResponseCoordinatorTest`.
- [x] **4.8** `MercurePublisher` : `encodePayload()` privé (3× dupliqué), dédupliquer topic construction via HelpStreamPublisher/AppExtension.
  - Centralisation de l'encodage JSON dans `MercurePublisher::encodePayload()` et délégation de `publishToChannel` / `publishToUser` à `publishToTopic`.
  - Utilisation systématique des méthodes `getUserTopic()`, `getStatusTopic()`, `getAdminModerationTopic()`, `getChannelTopic()` et `publishUserStatus()` dans `AppExtension`, `ActivitySubscriber`, `HelpStreamPublisher`, `PendingConfirmationService` et `ChannelActionController`.
  - Tests unitaires et fonctionnels complets (`ActivitySubscriberTest`, `AppExtensionTest`, `HelpStreamPublisherTest`, `MessageControllerTest`).

---

## Vérification

- Chaque phase : `vendor/bin/mago` (lint/format) + `bin/phpunit` + tests fonctionnels ciblés.
- Phase 1 et 2 en PRs séparées ; Phase 3 par service, avec tests existants comme filet (MessagePublishService, LlmQueryHandler, PendingConfirmationService ont déjà des tests).
