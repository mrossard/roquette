# TODO — Roquette

## Tests

- [ ] `NotificationController` (355 lignes) — tests fonctionnels
- [ ] `FileController` — téléchargement, preview, lightbox
- [ ] `OAuthController` — mock provider, connexion OAuth
- [ ] `DashboardController` — page d'accueil, directory
- [ ] `AccountController` — page de compte
- [ ] `AdminController` — ban/unban utilisateurs
- [ ] `ModalController` — modales de création/édition/invitation
- [ ] Tests unitaires entités : `Reaction`, `Invitation`, `UserChannelRead`, `Webhook`, `PollOption`, `PollVote`
- [ ] Tests JS modules : `app.js`, `ui.js`, `editor.js`, `mercure.js`, `autocomplete.js`
- [ ] Mise à jour des tests de charge k6 (`tests/Load/`) après le refactor subchannels

## UX — Sous-canaux

- [ ] Remplacer l'indentation `└` par du `padding-left` CSS (fragile selon la fonte)
- [ ] Éviter le layout shift des sous-canaux inactifs qui disparaissent/réapparaissent
- [ ] Exclure `.subchannel-link` du `draggable` SortableJS pour éviter le repositionnement post-drag
- [ ] Optimiser la boucle `O(n²)` en Twig pour le rendu des sous-canaux dans la sidebar

## Architecture

- [ ] Refactorer `ChannelController` (949 lignes) — extraire la logique métier dans des services
- [ ] Refactorer `MessageController` (589 lignes) — idem
- [ ] Ajouter Redis pour le cache Doctrine, les sessions, les topics Mercure
- [ ] Remplacer les `LIKE %query%` par des index `tsvector` PostgreSQL pour la recherche
- [ ] Introduire des DTOs pour les formulaires au lieu de lier directement les entités
- [ ] Ajouter PHPStan ou Psalm pour l'analyse statique (complément à Mago)

## Fonctionnalités

- [ ] UI d'épinglage de messages (`pinnedMessage` existe côté serveur, routes `pin`/`unpin` faites, template
  `_pinned_banner.html.twig` à finaliser)
- [ ] Historique des versions d'un message modifié
- [ ] Notifications email quand l'utilisateur est hors ligne
- [ ] Partage/renvoi d'un message vers un autre canal
- [ ] Planification de messages (`/schedule`)
- [ ] Archivage des messages avant purge (rétention)
- [ ] Catégories de canaux personnalisables (au lieu de Favoris/Canaux/DMs en dur)
- [ ] Groupes d'utilisateurs/équipes

## Sécurité

- [ ] Rate limiting sur les uploads de fichiers
- [ ] Rate limiting sur les recherches (locale et globale)
- [ ] Rate limiting sur les endpoints webhooks
- [ ] 2FA optionnel (TOTP)

## UI / Frontend

- [ ] Design responsive mobile (sidebar + chat + panneau sous-canaux)
- [ ] Raccourcis clavier (Ctrl+K recherche, Ctrl+N nouveau canal, etc.)
- [ ] Barre d'outils formatting riche (alternative à la saisie Markdown)
- [ ] Feedback visuel pour le drag-and-drop de fichiers
- [ ] Transitions/animations entre les vues

## Qualité de code

- [ ] Supprimer les `TODO`/`FIXME`/`XXX` dans le code source
- [ ] Vérifier la couverture de code et viser > 80%
- [ ] Standardiser les conventions (typed properties, named arguments, etc.)
