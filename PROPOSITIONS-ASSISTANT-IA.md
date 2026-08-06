# Propositions d'élargissement de l'Assistant IA

Ce document recense les pistes d'évolution de l'Assistant Roquette (assistant virtuel propulsé par LLM via `symfony/ai-bundle` + Ollama/Albert). Chaque proposition décrit le contexte existant, l'apport pour l'utilisateur, les étapes d'implémentation et un effort estimé.

## État actuel

| Capacité | Implémentation |
|---|---|
| Sondages interactifs | Outil `create_poll` (`src/Ai/Tool/CreatePollTool.php`) |
| Rappels programmés | Outil `schedule_reminder` (`src/Ai/Tool/ScheduleReminderTool.php`) |
| Intents | `resumer` (résumé de canal), `sondage`, `help` (`LlmQueryHandler::classifyIntent`) |
| Résumés de canal | Unread-first puis N derniers messages, batching + combinaison (`getSummaryPrompts`) |
| RAG | `DOC_UTILISATEUR.md` indexé (pgvector + `nomic-embed-text`), top-5 récupérés par question |
| Mémoire conversationnelle | `LLM_MEMORY_MESSAGES` (~20 messages), contexte simple ajouté au prompt |
| Boucle d'outils | `ToolRunner` (max 3 itérations), auto-enregistrement via tag `ai.tool` |
| Entrée | Commande `/help`, `/poll`, messages dans le DM `dm-robot-roquette-{slug}`, bouton "Résumer" |

Points d'extension clés (déjà en place) :
- Nouvel outil = une classe implémentant `AiToolInterface` → auto-enregistrée (`config/services.yaml`).
- `LlmService::chat(MessageBag)` existe mais **n'est appelé nulle part** — prévu pour la mémoire multi-tours.
- FTS PostgreSQL `contentTsvector` + `MessageRepository::searchGlobal` existent mais ne sont pas exposés au LLM.
- Champs kanban de `Message` (colonne, assigné, échéance, priorité, labels, done) modélisés mais jamais exposés.
- Infrastructure reminders (Messenger `DelayStamp`) réutilisable pour des tâches périodiques.

---

## Vue d'ensemble

| # | Proposition | Priorité | Valeur | Effort |
|---|---|---|---|---|
| 1 | Recherche de messages par l'IA | P1 | Forte | Faible |
| 2 | Tâches / Kanban intelligent | P1 | Forte | Moyen |
| 3 | Mémoire longue (rolling memory) | P1 | Forte | Moyen |
| 4 | Digest multi-canaux | P2 | Moyenne | Faible–Moyen |
| 5 | Messages assistés (brouillon / réécriture / traduction) | P2 | Moyenne | Faible |
| 6 | Veille intelligente (alertes mots-clés) | P2 | Moyenne | Faible–Moyen |
| 7 | Analyse des pièces jointes | P3 | Moyenne | Moyen |
| 8 | RAG enrichi (docs par workspace) | P3 | Moyenne | Moyen |
| 9 | Robustesse & ops (rate limit, routage modèles, garde-fous) | P4 | Transversale | Faible |
| 10 | Bridge Webhook → IA | P4 | Niche | Faible |

---

## Priorité 1 — Le cœur fonctionnel

### 1. Recherche de messages par l'IA

**Contexte** : `Message::contentTsvector` (FTS) et `MessageRepository::searchGlobal` / `searchInChannel` existent mais ne sont pas câblés au LLM. Aujourd'hui l'assistant ne peut répondre à « qu'est-ce qu'on a dit sur X ? ».

**Apport** : l'utilisateur pose une question factuelle et reçoit une synthèse sourcée depuis l'historique réel des canaux.

**Étapes** :
1. Créer l'outil `search_messages(query, channelSlug?, workspaceId?, limit=10)` dans `src/Ai/Tool/`.
2. Réutiliser `MessageRepository` (FTS `contentTsvector` de préférence, sinon `LOWER(content) LIKE`).
3. Retourner les résultats formatés (auteur, date, extrait) à la boucle d'outils pour synthèse par le modèle.
4. Ajouter les règles impératives dans `LlmQueryHandler::getDefaultHelpPrompts`.
5. Tests unitaires (`SearchMessagesToolTest`) + cas « aucune correspondance » → message clair.

**Effort** : faible (1–2 jours).

### 2. Tâches / Kanban intelligent

**Contexte** : `Message` porte déjà `kanbanColumn`, `assignedTo`, `dueAt`, `priority`, `labels`, `isCompleted`, et `Channel::isTodoList` + `KanbanColumn`. Rien de tout cela n'est exposé au LLM.

**Apport** : « ajoute une tâche "relancer fournisseur" à la colonne À faire du canal #projet, échéance vendredi, priorité haute » est exécuté d'un claquement de doigts.

**Étapes** :
1. Outils : `create_task`, `update_task`, `complete_task`, `list_tasks` (même modèle que `CreatePollTool`).
2. Résolution canal/workspace via `ChannelResolver` (déjà en place).
3. Gestion des dates relatives (« vendredi », « demain ») → parser côté outil ou laisser le modèle calculer.
4. Règles impératives dans `getDefaultHelpPrompts`.
5. Tests unitaires + validation du flux `/help`.

**Effort** : moyen (3–5 jours).

### 3. Mémoire longue (rolling memory)

**Contexte** : mémoire limitée à `LLM_MEMORY_MESSAGES`. `LlmService::chat(MessageBag)` (multi-tours) existe mais est inutilisé.

**Apport** : l'assistant se souvient du fil complet d'une session et résume l'ancien contexte (compression glissante) au lieu de le tronquer.

**Étapes** :
1. Brancher `LlmService::chat(MessageBag)` dans `LlmQueryHandler` (ou `createGenerator`).
2. Quand l'historique dépasse `LLM_MEMORY_MESSAGES`, lancer un résumé de compression (`generateText`) stocké comme « mémoire de session ».
3. Préfixer chaque tour avec : mémoire résumée + les N derniers messages bruts.
4. Persister la mémoire (entité dédiée ou champ sur `Message`) + migration.
5. Tests du comportement de compression.

**Effort** : moyen (3–5 jours).

---

## Priorité 2 — Valeur rapide

### 4. Digest multi-canaux

**Contexte** : résumé mono-canal déjà opérationnel (`getSummaryPrompts`), infra Reminder + Messenger disponible pour la périodicité.

**Apport** : un résumé périodique (quotidien/hebdo) de plusieurs canaux du workspace, poussé dans le DM de l'assistant.

**Étapes** :
1. Commandes : `assistant:digest --channels a,b,c --frequency daily` (dans `src/Command/`).
2. Réutiliser `getSummaryPrompts` par canal puis combiner via un appel LLM final.
3. Poster le digest via `MessagePublishService` dans le DM.
4. Planification via `ScheduleReminderTool` ou un message Messenger récurrent (`DelayStamp`).

**Effort** : faible–moyen (2–3 jours).

### 5. Messages assistés (brouillon / réécriture / traduction)

**Contexte** : l'app est i18n fr/en (`translations/`), `Message::content` modifiable, HTMX pour les modales de brouillon.

**Apport** : `/aide réécris ce message plus formel`, « traduis ce message en anglais », ou génération d'un brouillon insérable dans la zone de saisie.

**Étapes** :
1. Intent `reformulation` dans `classifyIntent` (ou slash command `/rewrite`).
2. Prompt dédié sans outils (génération simple), retour streamé.
3. UI : modale de brouillon avec boutons « Insérer » / « Remplacer » (HTMX OOB).
4. Traduction via prompt « Traduis en {lang} » en lisant la locale utilisateur.

**Effort** : faible (1–2 jours).

### 6. Veille intelligente (alertes mots-clés)

**Contexte** : Reminder + Mercure + notifications Web Push déjà en place ; `MessageRepository` permet de requêter les messages récents.

**Apport** : l'utilisateur active une veille (« alerte-moi si on parle de déploiement dans #ops ») et reçoit des alertes IA.

**Étapes** :
1. Entité `Alert` (mots-clés, canaux, utilisateur, actif) + migration.
2. Commandes cron : scan périodique, appel LLM de pertinence, alerte via `SendReminderMessage`/Mercure.
3. Création des alertes via l'outil `create_alert` (ou slash command).
4. UI de gestion simple.

**Effort** : faible–moyen (2–4 jours).

---

## Priorité 3 — Enrichissement

### 7. Analyse des pièces jointes

**Contexte** : uploads stockés (MinIO/Flysystem) + scan ClamAV, `findFilesByChannel`, métadonnées complètes sur `Message` (mime, taille, path).

**Apport** : « résume le contenu des fichiers partagés dans #docs » — description/classification par type (texte/PDF lus directement ; images via un modèle multimodal à terme).

**Étapes** :
1. Outil `list_files(channelSlug, workspaceId?, limit)` → métadonnées.
2. Outil `read_file(messageId)` → extraction de texte (texte brut, puis PDF).
3. OCR/caption images si un modèle multimodal est configuré (Ollama).
4. Règles + tests.

**Effort** : moyen (3–5 jours).

### 8. RAG enrichi (docs par workspace)

**Contexte** : `DocChunker` + `IndexDocCommand` ne gèrent qu'un seul fichier (`DOC_UTILISATEUR.md`). La stack pgvector est prête.

**Apport** : la base de connaissances devient multi-sources (docs par workspace, manuels, FAQ validées).

**Étapes** :
1. Généraliser `DocChunker` : plusieurs fichiers, métadonnées de source (workspace).
2. Étendre le schéma pgvector (`doc_chunks`) avec une colonne `workspace_id`.
3. Commande `ai:doc:index --file=... --workspace=...`.
4. Restreindre la récupération au workspace courant dans `getDefaultHelpPrompts`.

**Effort** : moyen (3–4 jours).

---

## Priorité 4 — Ops & intégrations

### 9. Robustesse & ops

**Contexte** : aucun rate limiter LLM dédié (seul `message_api` 20/min throttlé indirectement), `MAX_TOOL_ITERATIONS = 3`, pas de routage de modèles.

**Apport** : coûts maîtrisés, latence réduite (petit modèle pour la classification, gros pour la génération), garde-fous par rôle.

**Étapes** :
1. Rate limiter `llm` dédié dans `config/packages/rate_limiter.yaml` + application au dispatch.
2. Routage : `qwen2.5:0.5b` pour `classifyIntent`, `openweight-large` pour la génération (selon ce qui est disponible).
3. Garde-fous : limiter la taille de sortie, restreindre les outils selon le rôle (`ROLE_ADMIN` pour les actions sensibles).
4. Métriques/logs des appels (durée, tokens, erreurs).

**Effort** : faible (1–2 jours).

### 10. Bridge Webhook → IA

**Contexte** : `WebhookController` poste déjà comme `robot-roquette` avec nom/avatar personnalisés.

**Apport** : un service externe peut interroger l'assistant (POST avec une question → réponse via webhook), ou déclencher des actions.

**Étapes** :
1. Nouvelle route `POST /api/webhooks/ai/{token}` (rate limité, payload cap).
2. Dispatch `LlmQueryMessage` + retour de la réponse (synchrone ou via Mercure).
3. Verrouillage sur les webhooks admin / périmètre de canaux.

**Effort** : faible (1–2 jours).

---

## Feuille de route recommandée

1. **#1 Recherche de messages** — débloque le plus de valeur au moindre coût, fonde les suivants.
2. **#5 Messages assistés** — livraison rapide et visible côté UX.
3. **#2 Tâches/Kanban** — le canal le plus riche du modèle de données.
4. **#3 Mémoire longue** — qualité de conversation sur les sessions longues.
5. **#6 Veille** puis **#4 Digest** — s'appuient sur l'infra reminders.
6. **#7/#8** — quand un modèle multimodal / plus de sources est dispo.
7. **#9** en continu (rate limit et routage à faire tôt si coûts), **#10** à la demande.
