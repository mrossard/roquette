<!-- BEGIN AI_MATE_INSTRUCTIONS -->
AI Mate Summary:
- Role: MCP-powered, project-aware coding guidance and tools.
- Required action: Read and follow `mate/AGENT_INSTRUCTIONS.md` before taking any action in this project, and prefer MCP tools over raw CLI commands whenever possible.
- Installed extensions: symfony/ai-mate, symfony/ai-monolog-mate-extension, symfony/ai-symfony-mate-extension.
<!-- END AI_MATE_INSTRUCTIONS -->

# Roquette — real-time messaging app

Symfony 8.0, PHP 8.4+, HTMX, Mercure SSE, PostgreSQL 16, AssetMapper.

## Quick start

```bash
composer install
cp .env .env.local && cp .env.test .env.test.local   # edit secrets
docker compose up -d                                   # DB, Mercure, ClamAV, MinIO, Ollama
bin/console doctrine:migrations:migrate
bin/console importmap:install
symfony server:start -d   # or port 80 via compose.override.yaml
```

## Stack quirks

- **No JS bundler** — AssetMapper + `importmap.php`. Add JS libs via `bin/console importmap:require <package>`.
- **HTMX + Idiomorph** — UI is driven by HTMX with morph-swaps (`hx-swap="morph:outerHTML"`). No React/Vue/Alpine.
- **Real-time via Mercure** (SSE), not WebSockets.
- **Messenger** with Doctrine transport for async — `LlmQueryMessage` and `Mercure\Update` are routed to `async`.
- **Sessions via Redis** (`handler_id: '%env(REDIS_URL)%'`).
- **Rate limiting** uses Symfony rate-limiter (see `config/packages/rate_limiter.yaml`).
- **File uploads** use Flysystem (MinIO S3 in dev, configurable). ClamAV scans all uploads.
- **AI** uses `symfony/ai-bundle` + Ollama. Model defaults to `qwen2.5:0.5b` (`.env`), overridden to `qwen2.5:3b` in
  `compose.yaml`.
- i18n via `symfony/intl-bundle`. Everything should have French and English translations.

## Commands

| Action                | Command                                   |
|-----------------------|-------------------------------------------|
| Run tests             | `bin/phpunit` or `vendor/bin/phpunit`     |
| Lint & format         | `vendor/bin/mago` (config: `mago.toml`)   |
| Run migrations        | `bin/console doctrine:migrations:migrate` |
| Create migration      | `bin/console make:migration`              |
| List routes           | `bin/console debug:router`                |
| Debug assets          | `bin/console debug:asset-map`             |
| Install JS assets     | `bin/console importmap:install`           |
| Compile assets (prod) | `bin/console asset-map:compile`           |
| Gen emoji mapping     | `composer generate-emoji-mapping`         |
| Clear cache           | `bin/console cache:clear`                 |

## Tests

- Require a running PostgreSQL (`docker compose up -d database`).
- Functional tests (`tests/Functional/`) extend `WebTestCase`, create real DB entities in `setUp()`, clean up in
  `tearDown()`.
- CI workflow:
  `bin/console doctrine:database:create --env=test --if-not-exists && bin/console doctrine:migrations:migrate --env=test --no-interaction && vendor/bin/phpunit`
- Load tests (k6) in `tests/Load/`.

## Architecture pointers

| Directory              | Purpose                                                            |
|------------------------|--------------------------------------------------------------------|
| `src/Controller/`      | Route handlers, return HTML fragments for HTMX                     |
| `src/Entity/`          | Doctrine ORM entities                                              |
| `src/Repository/`      | Doctrine repositories                                              |
| `src/Service/`         | Business logic (Mercure publisher, file upload, ClamAV, LLM, etc.) |
| `src/MessageHandler/`  | Messenger async handlers (LlmQuery)                                |
| `src/EventSubscriber/` | Event subscribers                                                  |
| `src/Twig/`            | Twig extensions                                                    |
| `src/Command/`         | CLI commands                                                       |
| `templates/`           | Twig templates (no frontend framework)                             |
| `assets/`              | JS modules loaded via AssetMapper                                  |
| `translations/`        | YAML translation files (`messages.fr.yaml`, `messages.en.yaml`)    |
| `migrations/`          | Doctrine migration classes                                         |
| `config/packages/`     | Symfony bundle configs                                             |

## Deployment

- Docker image: `dunglas/frankenphp:1.12.4-php8.5` (FrankenPHP).
- Production build: `Dockerfile` (no Xdebug). Dev: `Dockerfile-dev` (includes Xdebug).
- Entrypoint auto-runs `doctrine:migrations:migrate` on container start.
- Supervisor manages the FrankenPHP worker process.

## Existing instruction files

- `mate/AGENT_INSTRUCTIONS.md` — MCP tool usage guidance (prefer MCP over raw CLI).
- `mate/extensions.php` — AI Mate extension registry.
