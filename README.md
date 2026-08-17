# Roquette

Application de messagerie et de collaboration en temps réel.

**Stack** : Symfony 8.0, PHP 8.4+, HTMX + Idiomorph, Mercure SSE, PostgreSQL 16, AssetMapper, Redis.

## Fonctionnalités

- **Messagerie temps réel** via Mercure SSE (pas de WebSocket)
- **Espaces de travail (Workspaces)** cloisonnés, publics ou privés
- **Canaux** publics, privés, messages directs (DM), todo lists
- **Sous-canaux / Discussions** rattachés à un message parent
- **Kanban** — les canaux todo peuvent être visualisés en mode tableau Kanban
- **Fils de discussion (Threads)** — réponses chaînées aux messages
- **Sondages** — choix unique ou multiple, mise à jour en temps réel
- **Réactions** émojis sur les messages + sélecteur d'émojis personnalisés
- **Messages enregistrés** (étoile) et **Mes réactions**
- **Épinglage** de messages (un par canal)
- **Markdown** (GFM) avec coloration syntaxique, aperçu en direct
- **Mentions** `@user`, **références** `#canal`, autocomplétion avancée
- **Commandes slash** : `/me`, `/color`, `/shrug`, `/help`, `/poll`
- **Fichiers** — glisser-déposer, scan ClamAV, prévisualisations (image, audio, vidéo, PDF, texte)
- **Médiathèque** par canal (images, documents, médias)
- **Aperçus de liens** (Open Graph) asynchrones
- **Webhooks entrants** — publication automatisée par des services externes
- **Assistant IA** — LLM via Ollama (`/help`, `/poll`, résumés de canaux, canal dédié 🤖, validation interactive des actions, outils autonomes : création de sondages, programmation de rappels, recherche intelligente de messages)
- **Recherche globale** (`Ctrl+K`) avec filtres avancés + recherche par canal
- **Notifications** de bureau, mise en sourdine des canaux, mode "Occupé"
- **Statut de présence** : en ligne, absent, occupé, hors ligne (détection d'inactivité)
- **Thème clair/sombre** persistant
- **Personnalisation** : couleur de profil (teinte HSL), nom d'affichage, langue (FR/EN)
- **Administration** : utilisateurs, modération et protection contre fuite de secrets, groupes (LDAP), exports, journaux d'audit, émojis custom, espaces de travail
- **Export** de l'historique d'un canal en HTML standalone
- **OAuth2** (connexion externe) avec PKCE, mock OAuth2 en dev
- **i18n** français et anglais

## Prérequis

- PHP 8.4+
- Docker & Docker Compose
- Composer

## Installation

```bash
# 1. Cloner et installer les dépendances
git clone <repository_url>
cd roquette
composer install

# 2. Configuration
cp .env .env.local && cp .env.test .env.test.local
composer generate-vapid-keys  # clés pour notifications push

# 3. Démarrer les services (PostgreSQL, Mercure, ClamAV, MinIO, Ollama)
docker compose up -d

# 4. Base de données
bin/console doctrine:migrations:migrate

# 5. Assets JS
bin/console importmap:install

# 6. Serveur de dev
symfony server:start -d
```

Accès direct via le port 80 (voir `compose.override.yaml`).

## Assistant IA (Ollama)

Configuration dans `.env.local` :

```env
LLM_MODEL=qwen2.5:3b
LLM_ENDPOINT=http://ollama:11434
LLM_SYSTEM_PROMPT="Tu es l'Assistant Roquette, un assistant virtuel d'aide pour l'application Roquette."
```

## Tests

```bash
# Nécessite PostgreSQL : docker compose up -d database
bin/console doctrine:database:create --env=test --if-not-exists
bin/console doctrine:migrations:migrate --env=test --no-interaction
vendor/bin/phpunit
```

Tests de charge dans `tests/Load/` (k6).

## Commandes utiles

| Action | Commande |
|---|---|
| Migrations | `bin/console doctrine:migrations:migrate` |
| Créer une migration | `bin/console make:migration` |
| Routes | `bin/console debug:router` |
| Cache | `bin/console cache:clear` |
| Assets (prod) | `bin/console asset-map:compile` |
| Émojis | `composer generate-emoji-mapping` |
| Lint | `vendor/bin/mago` |

## Architecture

| Dossier | Rôle |
|---|---|
| `src/Controller/` | Routes HTMX (fragments HTML) |
| `src/Entity/` | Doctrine ORM |
| `src/Repository/` | Repositories |
| `src/Service/` | Logique métier (Mercure, fichiers, LLM, etc.) |
| `src/Ai/` | Outils & infrastructure IA (`AiToolInterface`, `ToolRunner`, `ToolRegistry`, etc.) |
| `src/MessageHandler/` | Messenger async (LLM, Mercure) |
| `src/EventSubscriber/` | Event subscribers |
| `src/Command/` | CLI commands |
| `templates/` | Twig (pas de frontend framework) |
| `assets/` | JS modules (AssetMapper) |
| `translations/` | YAML i18n (FR/EN) |

## Documentation utilisateur

Voir [`DOC_UTILISATEUR.md`](DOC_UTILISATEUR.md) pour le guide complet.

## Déploiement

- Image Docker : `dunglas/frankenphp:1.12.4-php8.5`
- Build prod : `Dockerfile` ; Dev : `Dockerfile-dev` (Xdebug)
- Migrations automatiques au démarrage
- Supervisor pour FrankenPHP worker
