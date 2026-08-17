# Guide de l'utilisateur — Roquette

Bienvenue dans le guide de l'utilisateur de **Roquette**, plateforme de messagerie collaborative en temps réel (alternative moderne à Slack et Discord). Ce guide couvre l'intégralité des fonctionnalités de l'application, organisé par domaine fonctionnel.

---

## Table des matières

1. [Présentation générale](#1-présentation-générale)
2. [Prise en main](#2-prise-en-main)
3. [Espaces de travail (Workspaces)](#3-espaces-de-travail-workspaces)
4. [Gestion du compte et personnalisation](#4-gestion-du-compte-et-personnalisation)
5. [Interface utilisateur](#5-interface-utilisateur)
6. [Canaux de discussion](#6-canaux-de-discussion)
7. [Discussions (sous-canaux)](#7-discussions-sous-canaux)
8. [Todo lists et Tableaux Kanban](#8-todo-lists-et-tableaux-kanban)
9. [Messagerie](#9-messagerie)
10. [Formatage des messages (Markdown)](#10-formatage-des-messages-markdown)
11. [Mentions et références](#11-mentions-et-références)
12. [Émojis et réactions](#12-émojis-et-réactions)
13. [Fils de discussion (Threads)](#13-fils-de-discussion-threads)
14. [Épinglage de messages](#14-épinglage-de-messages)
15. [Sondages](#15-sondages)
16. [Fichiers et médias](#16-fichiers-et-médias)
17. [Aperçus de liens](#17-aperçus-de-liens)
18. [Webhooks entrants](#18-webhooks-entrants)
19. [Commandes slash](#19-commandes-slash)
20. [Recherche](#20-recherche)
21. [Notifications et mise en sourdine](#21-notifications-et-mise-en-sourdine)
22. [Messages enregistrés](#22-messages-enregistrés)
23. [Mes réactions](#23-mes-réactions)
24. [Assistant virtuel et outils IA](#24-assistant-virtuel-et-outils-ia)
25. [Administration](#25-administration)
26. [Export de l'historique](#26-export-de-lhistorique)
27. [Limitations et contraintes techniques](#27-limitations-et-contraintes-techniques)
28. [Dépannage et FAQ](#28-dépannage-et-faq)

---

## 1. Présentation générale

### 1.1 Qu'est-ce que Roquette ?

Roquette est une application web de messagerie instantanée et de collaboration en équipe. Elle permet à des groupes d'utilisateurs de communiquer en temps réel via des espaces de travail cloisonnés (workspaces) contenant des canaux de discussion publics ou privés, d'échanger des fichiers, de créer des sondages, d'organiser des tâches sous forme de todo lists et de bénéficier d'un assistant IA.

### 1.2 Technologies utilisées

- **Temps réel** : Les messages et événements sont diffusés instantanément via Mercure (Server-Sent Events, SSE). Pas de WebSocket ni de polling.
- **Interface** : Rendu côté serveur avec Symfony Twig + HTMX. Pas de framework JavaScript (React, Vue, etc.). Les mises à jour DOM utilisent Idiomorph (morphing) pour des transitions fluides.
- **Stockage de fichiers** : Flysystem (compatible MinIO S3 ou stockage local selon la configuration).
- **Sessions et Cache** : Redis pour la persistance des sessions et du cache.
- **Base de données** : PostgreSQL 16.
- **IA** : Modèle de langage (LLM) via Ollama, intégré avec `symfony/ai-bundle`.

### 1.3 Concepts clés

| Concept | Description |
|---|---|
| **Espace de travail (Workspace)** | Conteneur organisationnel regroupant un ensemble de membres et de canaux. L'application possède un espace public par défaut auquel tout le monde a accès. |
| **Canal** | Espace de discussion thématique au sein d'un workspace. Peut être public (visible par tous les membres du workspace) ou privé (visible uniquement sur invitation). |
| **Message direct (DM)** | Canal privé entre deux utilisateurs au sein d'un workspace. |
| **Sous-canal / Discussion** | Canal fils rattaché à un message parent d'un canal principal, permettant de creuser un sujet sans polluer le flux principal du workspace. |
| **Todo list** | Canal dont chaque message est une tâche. |
| **Assistant** | Canal DM privé avec l'agent IA intégré. |
| **Favori** | Canal ou sous-canal épinglé en haut de la barre latérale pour un accès rapide. |
| **Fil de discussion (Thread)** | Réponses chaînées à un message, affichées dans le flux principal. |

---

## 2. Prise en main

### 2.1 Création de compte

1. Accédez à la page d'accueil.
2. Cliquez sur le bouton **S'inscrire** ou **Register**.
3. Remplissez le formulaire :
   - **Nom d'utilisateur** : identifiant unique (utilisé pour les mentions `@username`).
   - **Mot de passe** : minimum 6 caractères.
4. Validez. Vous êtes automatiquement connecté.

### 2.2 Connexion

1. Saisissez votre nom d'utilisateur et mot de passe.
2. Si l'authentification OAuth2 est configurée, un bouton de connexion externe est disponible (ex: Google, GitHub). 
   > [!NOTE]
   > En environnement de développement, un fournisseur d'autorisation OAuth2 simulé (Mock OAuth2 Provider) permet de tester et faire la démonstration de la connexion OAuth2 sans configuration externe requise.
3. Une connexion réussie vous redirige vers le tableau de bord principal (`/`).

### 2.3 Déconnexion

Utilisez le menu utilisateur (en haut à droite) puis cliquez sur **Déconnexion** / **Logout**.

### 2.4 Interface générale

L'écran principal (tableau de bord) se compose de quatre zones :

```
┌─────────────────────────────────────────────────────────────┐
│  Barre d'en-tête (Header)                     [Menu user]   │
├──────────┬──────────────────────────────────┬───────────────┤
│          │                                  │               │
│ Barre    │  Fenêtre de discussion           │ Panneau des   │
│ latérale │  (messages du canal actif)       │ discussions   │
│ (Sidebar)│                                  │ (sous-canaux) │
│          │                                  │               │
│          │  ── Champ de saisie ──           │               │
├──────────┴──────────────────────────────────┴───────────────┤
│  Pied de page (Footer)                                      │
└─────────────────────────────────────────────────────────────┘
```

- **Sélecteur de Workspace (en haut de la sidebar)** : Permet de basculer d'un espace de travail à un autre. Un menu d'actions rapide (icône engrenage ⚙️) permet de configurer l'espace, d'inviter des membres ou d'afficher la liste des membres.
- **Barre latérale (gauche)** : Raccourcis, favoris, canaux todo, canaux, messages directs et invitations liés au workspace actif.
- **Fenêtre centrale** : Messages du canal actif + champ de saisie.
- **Panneau droit** : Liste des sous-canaux du canal actif (visible uniquement si le canal a des discussions) ou médiathèque.

---

## 3. Espaces de travail (Workspaces)

### 3.1 Présentation des workspaces

Les espaces de travail (workspaces) permettent de cloisonner l'application en différents environnements collaboratifs distincts. Chaque workspace possède ses propres membres, ses propres canaux et ses propres droits d'accès. 
Par défaut, Roquette comprend un espace public nommé **Public**, accessible à l'ensemble des utilisateurs inscrits.

### 3.2 Créer un workspace

Tout utilisateur peut créer un nouvel espace de travail :
1. Cliquez sur le sélecteur d'espace de travail en haut de la barre latérale gauche ou accédez à `/workspaces`.
2. Cliquez sur le bouton **Créer un espace** / **Créer un workspace**.
3. Remplissez le formulaire :
   - **Nom de l'espace** (obligatoire) : Le nom affiché de l'espace.
   - **Description** (optionnel) : Une brève description de son objectif.
4. Validez. L'espace est créé et un canal par défaut `#general` y est automatiquement configuré. Vous êtes désigné comme le créateur et premier membre de cet espace.

### 3.3 Navigation et changement d'espace

- **Sélecteur d'espace** : Situé tout en haut de la barre latérale gauche, il affiche l'espace actuellement actif. Cliquez dessus pour ouvrir un menu déroulant listant tous vos espaces de travail et permettant de passer de l'un à l'autre d'un simple clic.
- **Tableau de bord des espaces** : Accessible à l'adresse `/workspaces`. Il présente une vue d'ensemble de tous les espaces dont vous faites partie, avec le nombre de membres et de canaux de chaque espace, un bouton pour les ouvrir directement et la possibilité de gérer leurs paramètres si vous en êtes le créateur.
- **Indicateurs d'activité (badges)** : Le sélecteur affiche un badge rouge indiquant le nombre total de messages non lus dans vos autres espaces de travail. Dans le menu déroulant, chaque espace affiche individuellement son propre compteur de messages non lus.
- **Persistance de l'espace actif** : L'application conserve l'identifiant du workspace actif en session. Même si vous naviguez sur des routes globales (comme les DMs ou la recherche), vous restez rattaché au workspace précédemment sélectionné.

### 3.4 Paramètres et Avatar (Créateurs et Administrateurs)

Le créateur d'un espace de travail ou un administrateur système peut en modifier les paramètres :
1. Dans le sélecteur d'espace (en haut de la sidebar), cliquez sur l'icône d'engrenage (⚙️) puis sélectionnez **Paramètres de l'espace** (ou cliquez sur le bouton de configuration sur le tableau de bord des workspaces).
2. Vous pouvez alors modifier :
   - Le **Nom** de l'espace.
   - La **Description** de l'espace.
   - L'**Avatar** : Téléversez une image personnalisée pour identifier l'espace (formats acceptés: JPG, JPEG, PNG, GIF, WEBP, SVG ; taille maximale de 10 Mo). Vous pouvez également cocher "Supprimer l'avatar" pour restaurer l'avatar par défaut.
3. Si aucun avatar n'est configuré, Roquette génère automatiquement un avatar textuel avec les deux premières lettres du nom de l'espace sur un fond de couleur déterminé de manière déterministe par rapport au nom.

### 3.5 Gestion des membres et invitations

- **Membres de l'espace** : Cliquez sur l'engrenage (⚙️) du sélecteur d'espace puis sur **Membres de l'espace** pour afficher la liste des membres actuels.
- **Inviter des membres** : Pour les espaces de travail privés, l'accès se fait uniquement sur invitation. Le créateur de l'espace ou un administrateur peut inviter des utilisateurs en ouvrant la boîte de dialogue d'invitation, en saisissant leur nom d'utilisateur ou nom d'affichage, puis en cliquant sur **Inviter**.
- **Gestion des invitations** : Les invitations en attente s'affichent dans la barre latérale gauche (section "Invitations") ainsi que sur le tableau de bord des espaces (`/workspaces`). L'utilisateur invité peut accepter ou refuser l'invitation.

### 3.6 Quitter un workspace

Un membre peut décider de quitter un espace de travail privé en accédant aux paramètres de l'espace. 
> [!WARNING]
> Il est impossible de quitter l'espace de travail public par défaut.

### 3.7 Suppression d'un workspace

Le créateur de l'espace ou un administrateur global peut supprimer définitivement un espace de travail.
- Cette action supprime immédiatement l'espace, tous ses canaux associés, tous les messages, fichiers partagés, et redirige les utilisateurs connectés vers l'accueil.
- L'espace public par défaut ne peut pas être supprimé.

---

## 4. Gestion du compte et personnalisation

Accédez à **Mon compte** via le menu utilisateur (en haut à droite).

### 4.1 Informations de profil

| Champ | Contrainte | Description |
|---|---|---|
| **Nom d'utilisateur** | Lecture seule, défini à la création | Identifiant unique, utilisé pour les mentions `@username`. |
| **Adresse email** | Lecture seule | Adresse email du compte. Indique si l'adresse est vérifiée (✓ Vérifié) ou non vérifiée, avec un bouton pour **Renvoyer l'email** de validation le cas échéant. |
| **Nom d'affichage** | 30 caractères max | Nom visible par les autres utilisateurs. Si vide, le nom d'utilisateur est utilisé. |
| **Couleur du profil (teinte)** | 0-360 (HSL Hue) | Couleur de l'avatar et du pseudo. Se met à jour en temps réel. Curseur interactif. |
| **Langue** | `fr` ou `en` | Langue de l'interface. |
| **Statut de présence** | Voir section 4.2 | Surcharge manuelle du statut. |

### 4.2 Statut de présence

Cinq statuts disponibles :

| Statut | Comportement |
|---|---|
| **Automatique** (par défaut) | Ajusté selon l'activité : actif = "En ligne", inactif = "Absent". |
| **En ligne** | Visible comme disponible. |
| **Absent** | Marqué comme inactif. |
| **Occupé** | Suspend les notifications de bureau et le rafraîchissement live de l'interface. Une modale de confirmation s'affiche. |
| **Hors ligne** | Apparaît invisible aux autres utilisateurs. |

Le statut est visible via un point de couleur sur l'avatar dans la barre latérale, l'en-tête de canal, et chaque message. Le dernier instant d'activité est tracé (`lastActiveAt`). Un utilisateur inactif pendant une durée configurable passe automatiquement en "Absent".

### 4.3 Changement de mot de passe

1. Remplissez les trois champs : mot de passe actuel, nouveau mot de passe, confirmation.
2. Contraintes : le mot de passe actuel doit être valide, le nouveau mot de passe doit faire au moins 6 caractères, la confirmation doit correspondre.
3. Validation : le formulaire affiche une erreur si un champ est vide ou si les contraintes ne sont pas respectées.

### 4.4 Notifications de bureau

| Option | Description |
|---|---|
| **Notifications de bureau** | Active/désactive globalement les notifications du navigateur (push web). |
| **Notifications pour les mentions uniquement** | Si activé, les notifications de bureau ne sont émises que lorsque vous êtes mentionné (`@username`). |

La souscription aux notifications push est gérée dynamiquement côté navigateur (API Notification + Service Worker).

### 4.5 Thème clair / sombre

Basculez entre les thèmes depuis le menu utilisateur (icône soleil/lune) ou depuis le bouton dédié dans l'en-tête. Le choix est persistant et appliqué instantanément sans rechargement de page.

---

## 5. Interface utilisateur

### 5.1 Barre d'en-tête (Header)

Éléments présents dans l'en-tête :

- **Logo** : Icône fusée + titre "Roquette".
- **Bouton menu mobile** : hamburger pour afficher/masquer la barre latérale sur mobile.
- **Recherche globale** (`Ctrl+K`) : raccourci clavier pour ouvrir la recherche.
- **Statut Mercure** : indicateur visuel de connexion au serveur temps réel (connecté / déconnecté).
- **Bascule thème** : icône soleil/lune.
- **Menu utilisateur** : avatar, nom, sélecteur de statut, lien "Mon compte", lien "Administration" (admin uniquement), déconnexion.

### 5.2 Barre latérale (Sidebar)

La barre latérale gauche est organisée en sections verticales :

1. **Sélecteur de Workspace** : Permet de basculer de workspace, de configurer ou d'inviter des membres sur l'espace actif.
2. **Raccourcis** :
   - Messages enregistrés
   - Mes réactions
   - Canal Assistant (🤖)
3. **Favoris** : Canaux marqués comme favoris (★). Les sous-canaux sont listés avec leur compteur de messages non lus.
4. **Todo lists** : Canaux de type todo list. Bouton "+" pour en créer un nouveau. Les sous-canaux sont affichés avec un badge `↳ #parent`.
5. **Canaux** : Tous les canaux de l'espace (hors DM, todo, favoris). Les sous-canaux sont imbriqués sous leur parent.
6. **Messages directs** : Tous les DM de l'espace (hors canal Assistant). Un point de statut coloré indique la présence du destinataire.
7. **Invitations** : Invitations en attente pour les canaux privés de l'espace, avec boutons Accepter / Refuser.

**Actions sur la barre latérale** :

- **Créer un canal** : Depuis le menu d'options (⋮) en haut de la sidebar.
- **Filtrer par non lus** : N'affiche que les canaux de l'espace actif avec des messages non lus.
- **Réouvrir / Réorganiser** : Active le mode glisser-déposer pour réordonner les canaux. Cliquez sur "Terminé" pour sauvegarder.
- **Parcourir** : Ouvre l'annuaire des canaux publics du workspace actif.

### 5.3 En-tête de canal

Affiché en haut de la fenêtre de discussion, il contient :

- **Nom du canal** : avec icône représentative (`#` public, 🔒 privé, point de statut pour les DM).
- **Bouton favori** : ★ / ☆ pour ajouter/retirer des favoris.
- **Description du canal** (si définie).
- **Recherche dans le canal** : champ de recherche avec debounce 400ms et case à cocher "Non lus uniquement".
- **Bascule de vue Kanban / Liste** (`📋 Kanban` / `📝 Liste`, canaux todo uniquement) : permet de basculer instantanément entre la vue classique de messages et le tableau Kanban interactif.
- **Masquer les tâches terminées** (canaux todo en vue liste uniquement).
- **Bouton Résumé IA** (`✨ Résumer`, canaux publics/privés hors DM) : ouvre une modale affichant une synthèse générée en streaming par l'Assistant IA.
- **Filtre non lus** : bascule pour n'afficher que les messages non lus, avec compteur.
- **Notifications** : 🔔 (actif) / 🔕 (muet).
- **Médiathèque** : ouvre le panneau latéral des fichiers.
- **Menu d'actions** (⋮) :
  - Membres (liste des membres)
  - Discussions (affiche/masque le panneau des sous-canaux)
  - Médiathèque
  - Inviter (canaux privés, créateur uniquement)
  - Paramètres (administrateurs uniquement)
  - Exporter (administrateurs uniquement)
  - Quitter le canal
  - Supprimer le canal (administrateurs uniquement)

### 5.4 Panneau des discussions (latéral droit)

Lorsqu'un canal possède des sous-canaux (discussions), un panneau latéral droit s'affiche. Il liste :

- Le nom de chaque sous-canal (tronqué à 40 caractères depuis le message source).
- La description du sous-canal.
- Le compteur de messages non lus.
- Un bouton de paramètres pour le créateur du sous-canal.

### 5.5 Panneau des fichiers (latéral droit)

Onglets : **Tous** / **Images** / **Documents** / **Média**.

Chaque fichier affiche :

- Icône de type (image, audio, document).
- Nom du fichier.
- Taille.
- Auteur et date.
- Bouton "Aller au message" pour contextualiser.
- Aperçu : miniature cliquable pour les images, lecteur audio/video intégré pour les médias.

---

## 6. Canaux de discussion

### 6.1 Types de canaux

| Type | Description |
|---|---|
| **Canal public** | Visible et accessible par tous les membres du workspace. N'importe qui peut le rejoindre ou le quitter. |
| **Canal privé** | Invisible pour les non-membres. L'accès nécessite une invitation par un membre existant ou l'appartenance à un groupe synchronisé. |
| **Message direct (DM)** | Canal privé entre deux utilisateurs. S'ouvre via l'annuaire ou en cliquant sur un utilisateur. |
| **Canal Assistant** | DM dédié avec l'assistant IA (🤖). Automatiquement lié dans les raccourcis. |
| **Canal Todo list** | Canal dont chaque message est une tâche (voir section 8). |
| **Discussion (sous-canal)** | Sous-canal rattaché à un message parent (voir section 7). |

### 6.2 Créer un canal

1. Cliquez sur le menu d'options (⋮) dans la barre latérale, puis **Créer un canal**.
2. Remplissez les champs :

| Champ | Contrainte | Description |
|---|---|---|
| **Nom** | 20 caractères max | Nom du canal visible dans la sidebar. |
| **Description** | 50 caractères max | Texte d'aide affiché dans l'en-tête. |
| **Rétention des messages** | 1, 3, 6, 12 mois ou Illimité | Durée de conservation avant purge automatique. |
| **Type de canal** | Discussion ou Todo list | Définit le comportement du canal. |
| **Canal privé** | Oui/Non | Restreint l'accès aux membres invités. |
| **Abonnement de groupe** | Optionnel | Permet d'abonner automatiquement tous les membres d'un groupe d'utilisateurs. Peut être défini comme "Canal officiel" du groupe. |

3. Confirmez la création. Les membres voient apparaître le canal dans leur barre latérale en temps réel.

### 6.3 Rejoindre un canal public

Depuis l'annuaire (`/channels/directory`) ou le bouton **Parcourir** dans la sidebar, cliquez sur **Rejoindre** à côté du canal souhaité.

### 6.4 Quitter un canal

Depuis le menu d'actions (⋮) de l'en-tête du canal, sélectionnez **Quitter le canal**. Vous ne recevrez plus les messages de ce canal.

### 6.5 Inviter des membres (canaux privés)

1. Depuis le menu d'actions (⋮) de l'en-tête, sélectionnez **Inviter**.
2. Recherchez un utilisateur par son nom.
3. Cliquez sur **Inviter**. L'utilisateur recevra une invitation dans sa barre latérale.
4. L'utilisateur invité peut **Accepter** ou **Refuser** l'invitation.

### 6.6 Paramètres du canal (administrateurs)

Depuis **Paramètres** dans le menu d'actions (⋮) :

- Modifier le nom (20 caractères max).
- Modifier la description (50 caractères max).
- Modifier la période de rétention des messages.
- **Gestion des abonnements de groupe** :
  - **Lier un groupe** : Saisissez l'identifiant du groupe à abonner. Les utilisateurs du groupe sont synchronisés en temps réel.
  - **Canal officiel** : Déterminez si ce canal est le canal officiel du groupe d'utilisateurs (un seul canal officiel autorisé par groupe).
  - **Désabonner un groupe** : Retirer la liaison avec un groupe d'utilisateurs.
- Gérer les administrateurs du canal :
  - Rechercher un utilisateur pour l'ajouter comme administrateur.
  - Retirer un administrateur (sauf le créateur).
  - Le créateur est listé séparément et ne peut pas être retiré.
- Supprimer le canal.

### 6.7 Favoris

Cliquez sur l'étoile (★) à côté du nom d'un canal (dans l'en-tête ou la barre latérale) pour l'ajouter aux favoris. Les favoris apparaissent en haut de la barre latérale dans une section dédiée.

### 6.8 Réorganisation des canaux

1. Cliquez sur le bouton d'organisation (⇅ ou ✔️) dans la sidebar.
2. Activez le mode réorganisation : les canaux deviennent glissables.
3. Glissez-déposez les canaux pour changer leur ordre.
4. Cliquez sur **Terminé** pour sauvegarder l'ordre.

### 6.9 Rétention des messages

Les administrateurs peuvent configurer une politique de rétention :
- **1 mois** : les messages de plus d'un mois sont automatiquement supprimés.
- **3 mois**, **6 mois**, **12 mois** : variantes.
- **Illimité** : aucun message n'est purgé.

La purge est automatique et s'exécute côté serveur.

### 6.10 Annuaire des canaux

Accessible depuis :
- Le bouton **Parcourir** dans la sidebar.
- L'URL `/channels/directory`.

L'annuaire présente deux onglets (filtrés sur le workspace actif) :
1. **Canaux publics** : liste de tous les canaux publics avec nom, description, nombre de membres, politique de rétention, boutons Rejoindre/Quitter/Ouvrir.
2. **Membres** : liste de tous les utilisateurs du workspace avec avatar, nom, statut, bouton pour ouvrir un DM.

La recherche filtre les résultats en temps réel au fur et à mesure de la saisie.

---

## 7. Discussions (sous-canaux)

### 7.1 Qu'est-ce qu'une discussion ?

Une **discussion** (ou sous-canal) est un canal secondaire rattaché à un message parent. Elle permet d'approfondir un sujet spécifique sans encombrer le flux principal.

### 7.2 Créer une discussion

Depuis un message :

1. Survolez le message.
2. Cliquez sur le menu d'actions (•••).
3. Sélectionnez **Discussion** (ou **Discussion Todo** pour créer une todo list).
4. La discussion est automatiquement créée avec :
   - **Nom** : le début du message source (40 caractères max).
   - **Membres** : copie de la liste des membres du canal parent.
   - **Visibilité** : hérite du niveau de confidentialité du parent (public/privé).
   - **Rétention** : hérite de la politique du parent.

### 7.3 Navigation

- Les sous-canaux du canal actif sont listés dans le panneau latéral droit.
- Cliquez sur un sous-canal pour y accéder.
- Depuis un sous-canal, un en-tête spécifique affiche :
  - Le message parent (avec son contenu complet).
  - Un bouton **Retour au canal parent**.
- Les sous-canaux apparaissent dans la barre latérale, imbriqués sous leur parent.

### 7.4 Supprimer une discussion

Depuis le panneau des discussions, le créateur peut supprimer le sous-canal via le bouton de paramètres.

---

## 8. Todo lists et Tableaux Kanban

### 8.1 Présentation des canaux Todo et modes d'affichage

Un **canal Todo** est un canal de gestion de tâches collaboratif. Chaque message posté dans le canal représente une tâche qui peut être suivie, qualifiée, assignée et complétée.

Roquette propose deux modes d'affichage interchangeables à tout moment via l'en-tête du canal :
- **Vue Liste (📝 Liste)** : affichage chronologique sous forme de flux de messages interactifs.
- **Vue Tableau Kanban (📋 Kanban)** : organisation visuelle par colonnes thématiques ou étapes d'avancement avec cartes déplaçables par glisser-déposer.

### 8.2 Créer et configurer un canal todo

Vous pouvez créer ou convertir un canal Todo selon deux méthodes :
1. **À la création d'un canal** : cochez l'option **Canal todo list** dans la boîte de dialogue de création (ou utilisez le bouton `+` dans la section "Todo" de la barre latérale).
2. **Depuis un canal existant** : dans les **Paramètres** du canal, cochez **Transformer en todo list**.

### 8.3 Gestion des tâches en Vue Liste

- **Création de tâche** : tapez votre texte dans le champ de saisie et envoyez-le. Le message apparaît avec une mise en forme spécifique à la todo list.
- **Valider une tâche** :
  - Survolez la tâche et réagissez avec l'émoji ✅ (coche verte) dans le sélecteur rapide.
  - La tâche s'affiche immédiatement **barrée** pour indiquer son achèvement.
  - Réagir à nouveau avec ✅ retire la complétion.
- **Masquer les tâches terminées** : cliquez sur le bouton **Masquer les tâches terminées** dans l'en-tête pour épurer la vue et ne conserver que les tâches en cours.
- **Discussion liée** : ouvrez le menu d'actions (•••) de la tâche → **Discussion Todo** pour créer un sous-canal dédié aux échanges sur cette tâche spécifique.

### 8.4 Vue Tableau Kanban (`/channels/{slug}/kanban`)

En cliquant sur le bouton **📋 Kanban** dans l'en-tête du canal, vous accédez au tableau Kanban complet.

Le tableau est composé de :
- **Colonnes personnalisées** : créées par les administrateurs du canal.
- **Zone "Non trié"** : colonne par défaut regroupant toutes les tâches qui n'ont pas encore été assignées à une colonne spécifique.
- **Bouton d'ajout de colonne** : disponible pour les administrateurs et le créateur du canal.

### 8.5 Gestion des colonnes Kanban

Les administrateurs du canal ou administrateurs système peuvent gérer la structure du tableau :
- **Créer une colonne** : cliquez sur **+ Colonne** à droite du tableau, renseignez le nom et choisissez une couleur d'accentuation via le sélecteur chromatique, puis validez.
- **Renommer une colonne** : double-cliquez ou éditez le nom dans l'en-tête de colonne.
- **Supprimer une colonne** : cliquez sur l'icône de corbeille dans l'en-tête de colonne. Les cartes contenues sont automatiquement replacées dans la colonne "Non trié".
- **Réorganiser les colonnes** : l'ordre des colonnes est conservé et peut être réordonné.

### 8.6 Cartes Kanban et glisser-déposer

- **Déplacement libre** : glissez-déposez n'importe quelle carte d'une colonne à une autre (y compris vers ou depuis la zone "Non trié"). La mise à jour est enregistrée instantanément côté serveur.
- **Affichage synthétique de la carte** :
  - **Titre** : extrait du texte de la tâche (ou nom de fichier joint si aucun texte).
  - **Lien contextuel** : cliquez sur le titre de la carte pour ouvrir directement le message dans le flux du canal (`jumpTo`).
  - **Pièce jointe** : mention `📎 nom-du-fichier` si une pièce jointe est liée.
  - **Auteur et Assigné** : avatars miniatures avec initiales et couleur HSL de l'auteur et de la personne assignée.
  - **Nombre de réponses** : badge `💬 N` indiquant le nombre de commentaires dans le fil.
  - **Badge d'achèvement** : pastille verte `✅` lorsque la tâche est terminée.

### 8.7 Propriétés avancées d'une tâche (Menu Actions •••)

Sur chaque carte Kanban, cliquez sur le bouton de menu (•••) pour ouvrir le panneau d'édition rapide :

| Propriété | Description |
|---|---|
| **Assigner à** | Menu déroulant listant tous les membres du canal. Permet de confier la tâche à un membre spécifique (son avatar s'affiche sur la carte). |
| **Échéance (Due date)** | Sélecteur de date calendaire. Un badge `📅 jj/mm` s'affiche sur la carte : en orange si l'échéance approche (< 24h), en rouge si la date est dépassée. |
| **Priorité** | Définissez l'urgence parmi 4 niveaux : **Basse** (low), **Moyenne** (medium), **Haute** (high), **Urgente** (urgent). Une barre de couleur distinctive s'affiche sur la carte. |
| **Étiquettes (Labels)** | Saisissez une ou plusieurs étiquettes séparées par des virgules (ex: `frontend, bug, v2`). Elles s'affichent sous forme de pastilles colorées sur la carte. |
| **Bascule d'achèvement** | Bouton interactif pour marquer la tâche comme terminée ou non terminée. |

Toutes les modifications apportées depuis le menu d'une carte sont enregistrées à la volée sans rechargement de page.

### 8.8 Discussion dédiée à une tâche

Chaque carte ou tâche peut disposer d'un sous-canal de discussion :
- Cliquez sur le lien du fil ou le menu d'actions → **Discussion Todo**.
- Ce sous-canal hérite des membres et de la visibilité du canal parent et permet de débattre des détails de mise en œuvre de la tâche.

---

## 9. Messagerie

### 9.1 Envoyer un message

1. Saisissez votre texte dans le champ de saisie en bas de la fenêtre.
2. Appuyez sur **Entrée** pour envoyer.
3. Utilisez **Shift + Entrée** pour insérer un saut de ligne.
4. Vous pouvez aussi cliquer sur le bouton d'envoi (avion en papier).

### 9.2 Modifier un message

1. Survolez votre message.
2. Cliquez sur le menu d'actions (•••).
3. Sélectionnez **Modifier**.
4. Un champ d'édition s'ouvre. Modifiez le contenu.
5. Confirmez avec **Enregistrer** ou annulez avec **Annuler**.

La mention `(modifié)` / `(modified)` apparaît à côté de l'horodatage.

### 9.3 Supprimer un message

1. Survolez votre message.
2. Cliquez sur le menu d'actions (•••).
3. Sélectionnez **Supprimer**.
4. Confirmez la suppression dans la boîte de dialogue.

La suppression est définitive.

### 9.4 Répondre à un message (citation)

1. Survolez le message auquel vous voulez répondre.
2. Cliquez sur le bouton **Répondre** (icône de réponse).
3. Une bannière de contexte s'affiche au-dessus du champ de saisie : `↩ @utilisateur`.
4. Saisissez votre message et envoyez-le.
5. Votre message sera lié au message parent.

### 9.5 Indicateur de saisie

Lorsqu'un membre est en train d'écrire dans le canal actif, un indicateur discret s'affiche en bas du flux :

- `X écrit...` (un utilisateur)
- `X et Y écrivent...` (deux utilisateurs)
- `Plusieurs personnes écrivent...` (trois utilisateurs ou plus)

L'indicateur disparaît automatiquement après quelques secondes d'inactivité.

### 9.6 Messages d'action (/me)

Les messages commençant par `/me` sont affichés différemment :

- Saisie : `/me prend un café`
- Affichage : `* Jean prend un café *` (italique, sans avatar ni nom d'utilisateur).

Ils sont traités comme des actions et non comme des messages de dialogue.

---

## 10. Formatage des messages (Markdown)

Roquette supporte le **Markdown standard** et le **GitHub Flavored Markdown (GFM)**.

### 10.1 Syntaxe de formatage

| Style | Syntaxe | Résultat |
|---|---|---|
| Gras | `**texte**` ou `__texte__` | **texte** |
| Italique | `*texte*` ou `_texte_` | *texte* |
| Barré | `~~texte~~` | ~~texte~~ |
| Code en ligne | \`code\` | `code` |
| Bloc de code | \`\`\`langage ... \`\`\` | Bloc avec coloration syntaxique |
| Citation | `> texte` | Texte cité en bloc |
| Liste non ordonnée | `- item` ou `* item` | Liste à puces |
| Liste ordonnée | `1. item` | Liste numérotée |
| Titre | `# Titre` (1-6 `#`) | En-tête de section |
| Lien | `[texte](url)` | Lien cliquable |
| Image | `![alt](url)` | Image intégrée |

### 10.2 Blocs de code

Utilisez trois accents graves ouvrants et fermants avec le nom du langage pour la coloration syntaxique :

```php
function hello(): string {
    return 'Hello World';
}
```

Langages supportés : php, js, python, html, css, sql, bash, json, yaml, etc. (via highlight.js).

### 10.3 Aperçu en direct

Dans le champ de saisie, cliquez sur l'onglet **Aperçu** pour voir le rendu Markdown de votre message avant de l'envoyer. L'aperçu est généré côté serveur.

### 10.4 Barre d'outils de formatage

Le champ de saisie dispose d'une barre d'outils avec des boutons pour insérer rapidement :

- **Gras** (Ctrl+B)
- **Italique** (Ctrl+I)
- **Barré**
- **Citation**
- **Code en ligne**
- **Bloc de code**
- **Lien**
- **Sondage** (bascule vers le composeur de sondage)
- **Aperçu** (bascule entre édition et prévisualisation)

---

## 11. Mentions et références

### 11.1 Mentionner un utilisateur

Tapez `@` suivi du nom d'utilisateur :

- `@jean` : mentionne l'utilisateur "jean".
- Le message est surligné en bleu dans l'interface de l'utilisateur mentionné.
- Une notification de bureau est envoyée (si configurée).

**Autocomplétion** : en tapant `@`, une liste de suggestions d'utilisateurs apparaît.

### 11.2 Référencer un canal

Tapez `#` suivi du slug d'un canal :

- `#general` : lien cliquable vers le canal "general" (si vous y avez accès).
- La référence se transforme automatiquement en lien après envoi.

### 11.3 Autocomplétion avancée

Le système d'autocomplétion supporte trois types :

| Type | Déclencheur | Résultat |
|---|---|---|
| Utilisateurs | `@` | Suggestions d'utilisateurs (avatar + nom + @username). |
| Émojis personnalisés | `[:` | Suggestions d'émojis personnalisés (image + code `[:name]`). |
| Canaux | `#` | Suggestions de canaux (# + nom + slug). |

---

## 12. Émojis et réactions

### 12.1 Émojis dans les messages

- **Codes courts** : `:rocket:` devient 🚀, `:fire:` devient 🔥.
- **Émoticones textuelles** : conversion automatique :
  - `:)` → 🙂
  - `<3` → ❤️
  - `:D` → 😀
  - `;)` → 😉
  - `:TargetContent` → 🙁
  - `:/` → 😐
  - `:p` → 😋
  - `;D` → 😉
- **Émojis personnalisés** : `[:nom_emoji]` (si configurés sur le serveur et importés par l'administrateur).

### 12.2 Réagir à un message

1. Survolez un message.
2. Cliquez sur le sélecteur d'émojis (icône de smiley).
3. Choisissez une réaction rapide : 👍, ❤️, 😂, 😮, 😢, 🎉.
4. Dans un canal todo, ✅ est également disponible.
5. Vous pouvez aussi sélectionner n'importe quel émoji dans le sélecteur complet.

### 12.3 Ajouter son vote à une réaction existante

Cliquez sur une réaction déjà présente sous un message pour ajouter votre propre vote (+1).

### 12.4 Voir qui a réagi

Survolez une réaction avec la souris : une infobulle liste les utilisateurs qui ont ajouté cette réaction.

### 12.5 Retirer sa réaction

Cliquez à nouveau sur une réaction que vous avez déjà sélectionnée pour retirer votre vote.

---

## 13. Fils de discussion (Threads)

### 13.1 Créer un fil de discussion

1. Survolez un message.
2. Cliquez sur le bouton **Répondre** (ou menu d'actions → **Répondre**).
3. Saisissez votre réponse dans le champ qui s'affiche (bannière `↩ @utilisateur`).
4. Envoyez. Votre message est lié comme réponse au message parent.

### 13.2 Consulter un fil

- Sous un message ayant des réponses, un lien s'affiche : `💬 Voir les réponses (N)`.
- Cliquez dessus pour charger l'intégralité du fil dans le flux principal.
- Le fil s'affiche avec :
  - Le message parent en haut.
  - Toutes les réponses dans l'ordre chronologique.
  - Un bouton **Retour au direct** pour revenir à l'affichage normal du canal.

### 13.3 Comportement temps réel

- Lorsque vous consultez un fil, les nouveaux messages du canal principal ne s'affichent pas (pour éviter les distractions).
- Un badge de messages non lus s'affiche sur le canal pour signaler l'activité.

---

## 14. Épinglage de messages

### 14.1 Prérequis

Seuls le **créateur du canal** et les **administrateurs** peuvent épingler/désépingler des messages.

### 14.2 Épingler un message

1. Survolez le message.
2. Menu d'actions (•••) → **Épingler**.
3. Une bannière apparaît en haut du canal avec le contenu du message épinglé.

### 14.3 Voir le message épinglé

- La bannière en haut du canal affiche le message épinglé actuel.
- Cliquez sur **Voir** pour faire défiler automatiquement jusqu'au message d'origine dans le flux.

### 14.4 Désépingler un message

- Cliquez sur la croix (✕) de la bannière d'épinglage.
- Ou menu d'actions (•••) du message → **Désépingler**.

### 14.5 Limitation

Un seul message peut être épinglé à la fois dans un canal. Épingler un nouveau message remplace le précédent.

---

## 15. Sondages

### 15.1 Créer un sondage

1. Cliquez sur l'icône **Sondage** dans la barre d'outils de formatage.
2. Le composeur de sondage s'ouvre dans le champ de saisie.
3. Saisissez la **question** du sondage.
4. Ajoutez au **moins deux options** de réponse (bouton "+" pour ajouter, "✕" pour supprimer).
5. Activez éventuellement **Autoriser les choix multiples**.
6. Cliquez sur **Publier**.

### 15.2 Voter

- Les options de réponse s'affichent avec :
  - Un diagramme à barres proportionnel (largeur relative au nombre de votes).
  - Le nombre de votes par option.
  - Les avatars des votants (jusqu'à 5 affichés, avec "..." au-delà).
  - L'option la plus votée est mise en évidence.
- Cliquez sur une option pour voter.
- Si les choix multiples sont activés, vous pouvez sélectionner plusieurs options.

### 15.3 Modifier un sondage

1. Survolez le sondage.
2. Menu d'actions (•••) → **Modifier**.
3. Vous pouvez modifier la question, les options, et le type (choix unique/multiple).

### 15.4 Temps réel

Les votes s'actualisent en temps réel pour tous les utilisateurs via Mercure SSE.

---

## 16. Fichiers et médias

### 16.1 Envoyer un fichier

Deux méthodes :

1. **Glisser-déposer** : faites glisser un fichier depuis votre explorateur vers la fenêtre de discussion.
2. **Bouton trombone** : cliquez sur le bouton de jointure dans le champ de saisie pour sélectionner un fichier.

### 16.2 Limites

- Taille maximale : **10 Mo** par fichier.
- Types acceptés : tous types de fichiers (images, documents, vidéos, audio, archives, etc.).

### 16.3 Scan antivirus (ClamAV)

Tous les fichiers téléversés sont analysés par **ClamAV** :

| Statut | Affichage |
|---|---|
| **Analyse en cours** | Icône de chargement (spinner). |
| **Fichier sain** | Lien de téléchargement disponible. |
| **Fichier infecté** | Message "Fichier bloqué" — le téléchargement est impossible. |
| **Erreur d'analyse** | Message "Analyse impossible" — le fichier est accessible mais l'analyse a échoué. |

### 16.4 Prévisualisations

| Type | Comportement |
|---|---|
| **Image** | Affichée directement dans le flux. Cliquez dessus pour l'ouvrir en **lightbox** (pleine taille). |
| **Audio** | Lecteur audio intégré (`.mp3`, `.wav`, `.ogg`, `.flac`, etc.). |
| **Vidéo** | Lecteur vidéo intégré (`.mp4`, `.webm`, `.ogg`, etc.). |
| **PDF** | Lien de visualisation directe (ouvre dans un nouvel onglet). |
| **Fichier texte** | Bouton **Aperçu texte** qui charge le contenu avec coloration syntaxique. |

### 16.5 Médiathèque (bibliothèque de fichiers)

Le panneau latéral des fichiers liste tous les fichiers partagés dans le canal actif, organisé par onglets :

- **Tous** : tous les fichiers.
- **Images** : uniquement les images.
- **Documents** : PDF, texte, code, etc.
- **Média** : fichiers audio et vidéo.

Chaque fichier peut être téléchargé ou contextualisé ("Aller au message").

---

## 17. Aperçus de liens

### 17.1 Fonctionnement

Lorsque vous partagez une URL dans un message, Roquette tente de générer automatiquement un **aperçu enrichi** :

- Titre de la page.
- Description.
- Image de couverture (Open Graph).
- Nom du site.

### 17.2 Délai d'affichage

L'aperçu est chargé de manière asynchrone après l'envoi du message, avec un délai (lazy loading via Intersection Observer).

### 17.3 Masquer un aperçu

Si vous êtes l'auteur du message, vous pouvez masquer l'aperçu en cliquant sur la croix (✕) de la carte d'aperçu.

### 17.4 Images distantes

Les URLs pointant directement vers des images (`.jpg`, `.png`, `.gif`, `.webp`, etc.) sont rendues inline dans le flux, sans carte d'aperçu. Cliquez dessus pour les ouvrir en lightbox.

---

## 18. Webhooks entrants

### 18.1 Présentation

Les webhooks entrants permettent à des applications externes (GitHub, GitLab, serveurs de monitoring, scripts CI/CD, etc.) de publier automatiquement des messages dans un canal Roquette via une requête HTTP POST.

### 18.2 Configuration (administrateurs du canal)

1. Ouvrez le menu de configuration du canal (Paramètres).
2. Sélectionnez l'onglet **Webhooks entrants**.
3. Saisissez un nom descriptif (ex: "Alertes Production").
4. Cliquez sur **Créer**.
5. Copiez l'URL générée contenant un jeton de sécurité unique.
6. Collez cette URL dans l'application externe.

### 18.3 Gestion des webhooks

| Action | Description |
|---|---|
| **Activer/Désactiver** | Bascule le webhook sans le supprimer. |
| **Supprimer** | Supprime définitivement le webhook. Le jeton n'est plus valide. |
| **Copier l'URL** | Permet de récupérer l'URL du webhook. |

### 18.4 Format du payload (JSON)

URL d'appel : `POST /api/webhooks/incoming/{token}`

Corps de la requête (Content-Type: `application/json`) :

```json
{
    "text": "Le déploiement de la version 2.4.0 est réussi ! 🚀",
    "username": "Robot Déploiement",
    "avatar_url": "https://example.com/avatar.png"
}
```

**Attributs acceptés** :

| Attribut | Alias | Requis | Description |
|---|---|---|---|
| `text` | `content` | Oui | Contenu textuel du message (supporte le Markdown). |
| `username` | `customAuthorName` | Non | Nom d'affichage personnalisé de l'émetteur. |
| `avatar_url` | `customAuthorAvatar` | Non | URL de l'avatar personnalisé de l'émetteur. |

### 18.5 Exemple avec cURL

```bash
curl -X POST "https://roquette.exemple.com/api/webhooks/incoming/abc123token" \
  -H "Content-Type: application/json" \
  -d '{
    "text": "**Nouveau commit** : mise à jour de la documentation",
    "username": "GitHub Bot",
    "avatar_url": "https://github.githubassets.com/images/modules/logos_page/GitHub-Mark.png"
  }'
```

---

## 19. Commandes slash

Les commandes slash s'utilisent en début de message dans le champ de saisie.

### 19.1 `/me [action]`

Affiche un message d'action à la troisième personne.

**Exemple** :
```
/me prend une pause café
```
**Résultat** : `* Jean prend une pause café *` (affiché en italique)

### 19.2 `/color [teinte]`

Modifie instantanément la couleur de votre avatar et de votre pseudo.

- `teinte` : valeur de 0 à 360 (teinte HSL).
- Sans argument : une teinte aléatoire est choisie.
- La nouvelle couleur est persistante (identique à la section "Mon compte").

**Exemples** :
```
/color 200   → teinte bleue
/color       → teinte aléatoire
```

### 19.3 `/shrug [texte]`

Ajoute l'émoji `¯\_(ツ)_/¯` à la fin de votre texte.

**Exemple** :
```
/shrug je ne sais pas
```
**Résultat** : `je ne sais pas ¯\_(ツ)_/¯`

### 19.4 `/help [question]`

Pose une question à l'Assistant IA sur l'utilisation de Roquette.

- La réponse s'affiche de manière privée, visible uniquement par vous.
- Utilise la documentation de l'application pour répondre.

**Exemple** :
```
/help Comment créer un sondage ?
```

### 19.5 `/poll [question et options]`

Demande à l'Assistant IA de générer automatiquement un sondage interactif dans le canal actif à partir d'une formulation en langage naturel.

- L'IA extrait la question et la liste des choix proposés.
- Une confirmation interactive est demandée avant publication effective.

**Exemples** :
```
/poll Quel créneau préférez-vous pour la réunion : 10h, 14h ou 16h ?
/poll Êtes-vous favorable au passage en semaine de 4 jours ?
```

Voir aussi la [section 24](#24-assistant-virtuel-et-outils-ia) pour plus de détails sur les interactions avec l'Assistant.

---

## 20. Recherche

### 20.1 Recherche globale (Ctrl+K)

Accessible depuis :
- Le raccourci clavier **Ctrl+K** (ou **Cmd+K** sur macOS).
- Le bouton de recherche dans l'en-tête.

**Fonctionnalités** :

- Recherche plein texte dans tous les canaux accessibles.
- Filtres avancés avec syntaxe :
  - `from:jean` — messages de l'utilisateur "jean".
  - `in:general` — messages dans le canal "general".
  - `has:file` — messages contenant un fichier.
  - `has:image` — messages contenant une image.
- **Filtre visuel** : un constructeur de filtre avec menus déroulants (auteur, canal, type de pièce jointe, mots-clés).
- **Résultats** organisés en trois catégories :
  - **Canaux** : nom et description.
  - **Utilisateurs** : avatar, nom, lien DM.
  - **Messages** : extrait du contenu, nom du canal, lien "Aller au message", indicateur de fichier joint.

### 20.2 Recherche par canal

- Champ de recherche dans l'en-tête du canal.
- Résultats filtrés dans le canal actif uniquement.
- Option **Non lus uniquement** pour limiter aux messages non lus.
- Debounce de 400ms pour éviter les appels inutiles.

### 20.3 Filtre "Non lus"

Depuis l'en-tête du canal, activez le filtre **Non lus** pour n'afficher que les messages que vous n'avez pas encore vus. Un compteur indique le nombre de messages non lus. Un bouton **Retour au direct** permet de revenir à l'affichage normal.

---

## 21. Notifications et mise en sourdine

### 21.1 Notifications de bureau

Configurables depuis **Mon compte** :
- **Activation globale** : active/désactive toutes les notifications de bureau.
- **Mentions uniquement** : ne notifier que lorsque vous êtes mentionné (`@username`).

### 21.2 Mettre en sourdine un canal (Mute)

Si un canal est trop actif :

1. Cliquez sur l'icône de cloche (🔔) dans l'en-tête du canal.
2. La cloche devient barrée (🔕) : le canal est en sourdine.
3. Les indicateurs de messages non lus n'apparaissent plus.
4. Exception : vous serez toujours notifié si vous êtes directement mentionné.

Pour réactiver, cliquez à nouveau sur l'icône.

### 21.3 Mode "Occupé"

Lorsque vous passez votre statut en **Occupé** :
- Les notifications de bureau sont suspendues.
- Le rafraîchissement automatique de l'interface est suspendu.
- Une modale de confirmation s'affiche pour vous prévenir.

### 21.4 Heartbeat (ping)

Un ping périodique (toutes les 60 secondes) est envoyé au serveur pour maintenir votre session active et mettre à jour votre statut de présence.

---

## 22. Messages enregistrés

### 22.1 Enregistrer un message

1. Survolez un message.
2. Cliquez sur l'étoile (⭐) dans la barre d'actions.
3. L'étoile se remplit : le message est enregistré.

### 22.2 Consulter ses messages enregistrés

- Depuis la barre latérale : cliquez sur **Messages enregistrés** dans la section **Raccourcis**.
- URL : `/saved-messages`.
- La page affiche la liste chronologique inversée de tous vos messages enregistrés, avec le nom du canal source.
- Cliquez sur un message pour accéder à son contexte dans le canal d'origine.

### 22.3 Retirer un message

- Cliquez à nouveau sur l'étoile (⭐) d'un message enregistré.
- Ou depuis la page "Messages enregistrés", cliquez sur l'étoile pour le retirer.

---

## 23. Mes réactions

### 23.1 Consulter ses réactions

- Depuis la barre latérale : cliquez sur **Mes réactions** dans la section **Raccourcis**.
- URL : `/my-reactions`.
- La page affiche tous les messages sur lesquels vous avez ajouté une réaction, classés chronologiquement.

### 23.2 Filtrer par émoji

- Une barre de filtrage en haut de la page liste tous les émojis que vous avez utilisés.
- Cliquez sur un émoji pour filtrer : seuls les messages avec cet émoji spécifique sont affichés.
- URL avec filtre : `/my-reactions/{emoji}` (ex: `/my-reactions/❤️`).

### 23.3 Utilité

Cette fonctionnalité vous permet de retrouver facilement les discussions auxquelles vous avez participé activement, sans avoir à parcourir l'historique complet.

---

## 24. Assistant virtuel et outils IA

### 24.1 Présentation

L'Assistant virtuel Roquette est propulsé par un modèle de langage (LLM) via Ollama et `symfony/ai-bundle`. Grâce à une boucle d'outils autonomes (`ToolRunner`), il peut exécuter des actions concrètes dans l'application et répondre à vos besoins contextuels :

- **Répondre à vos questions** sur le fonctionnement de l'application (RAG basé sur la documentation).
- **Créer des sondages** interactifs dans vos canaux.
- **Programmer des rappels** différés et vous notifier au moment voulu.
- **Rechercher intelligemment des messages** dans l'historique de vos canaux.
- **Résumer des canaux** de discussion (messages non lus ou récents).

### 24.2 Outils autonomes disponibles

L'Assistant dispose d'outils (`AiToolInterface`) qu'il invoque de manière autonome en fonction de vos instructions :

| Outil | Description | Exemples d'instructions |
|---|---|---|
| **Création de sondage** (`create_poll`) | Génère un sondage interactif (choix unique ou multiple) dans le canal spécifié. | *"Crée un sondage sur la date du sprint dans #general avec les options Lundi et Mardi"* |
| **Programmation de rappel** (`schedule_reminder`) | Enregistre un rappel différé (relatif ou date exacte) et envoie une notification asynchrone dans le canal Assistant. | *"Rappelle-moi de vérifier la prod dans 2 heures"*, *"Rappelle-moi demain à 14h d'envoyer le rapport"* |
| **Recherche de messages** (`search_messages`) | Effectue une recherche plein texte dans l'historique des messages d'un canal ou du workspace pour apporter une réponse synthétique et sourcée. | *"Qu'est-ce qui a été dit au sujet du problème d'export ?"*, *"Cherche ce qu'on a dit sur la migration dans #dev"* |
| **Résumé de canal** (`summarize_channel`) | Analyse les messages non lus ou les 100 derniers messages d'un canal pour en produire une synthèse claire. | *"Fais-moi un résumé du canal #projet-x"*, *"Résume les derniers messages du canal général"* |

### 24.3 Commande `/help`

Depuis **n'importe quel canal**, saisissez :

```
/help Comment configurer un webhook ?
```
ou
```
/help Rappelle-moi de relancer la CI dans 30 minutes
```

Fonctionnement :
1. L'Assistant analyse votre requête et détermine s'il faut consulter la documentation ou exécuter un outil.
2. Si un outil est nécessaire (ex: rappel, recherche, sondage), il l'exécute automatiquement.
3. La réponse s'affiche de manière privée (visible uniquement par vous) directement dans le flux du canal actif.

### 24.4 Canal privé Assistant (🤖)

Accédez au canal privé de l'Assistant depuis la barre latérale (section **Raccourcis**).

Ce canal vous permet d'échanger en continu avec l'IA pour :
- Poser des questions ouvertes.
- Programmer des rappels.
- Lancer des recherches dans l'historique.
- Obtenir des résumés de canaux.

### 24.5 Validation interactive des actions sensibles (Human-in-the-Loop)

Pour toute action ayant un impact réel dans l'application (création d'un sondage dans un canal, programmation d'un rappel), l'Assistant applique un mécanisme de sécurité demandant une **confirmation explicite** :

- **Bouton d'action interactif** : un bouton de validation ("Confirmer l'action") s'affiche directement sous la réponse de l'Assistant. Un simple clic déclenche l'exécution sécurisée.
- **Confirmation en langage naturel** : vous pouvez également valider simplement en envoyant une phrase affirmative dans le chat (ex: `"oui"`, `"ok"`, `"confirme"`, `"je valide"`, `"vas-y"`, `"go"`, `"d'accord"`, `"parfait"`). L'Assistant reconnaît automatiquement votre confirmation et exécute l'action demandée.
- **Sécurité** : chaque demande d'action génère un jeton signé temporaire d'une durée de validité de 15 minutes.

### 24.6 Synthèse de canal en temps réel (Bouton ✨ Résumer)

Depuis n'importe quel canal (hors DM), un bouton **✨ Résumer** est disponible dans l'en-tête :

1. Cliquez sur **✨ Résumer** dans la barre supérieure du canal.
2. Une fenêtre modale s'ouvre et commence immédiatement à diffuser la synthèse en direct (streaming SSE).
3. L'Assistant analyse prioritairement vos **messages non lus**, ou se rabat sur l'historique récent (jusqu'aux 100 derniers messages, traités par lots pour les échanges volumineux).
4. Le résultat met en exergue les sujets clés, les décisions prises et les questions en suspens sans paraphraser les messages un par un.

### 24.7 Retour en temps réel (streaming)

Lors d'une requête complexe ou comportant des appels d'outils, l'Assistant affiche des étapes de progression :

1. `Analyse de la demande... 🔍`
2. `Recherche dans l'historique / Exécution de l'action... ⏳`
3. `Génération de la réponse... ⏳`
4. La réponse définitive ou le résultat de l'action s'affiche.

### 24.8 Navigation pendant la génération

Si vous changez de canal pendant que l'Assistant génère une réponse :

- La réponse ne perturbe pas votre lecture actuelle.
- Un badge de message non lu apparaît sur le lien `🤖 Assistant` dans la barre latérale.
- La réponse est disponible dès votre retour dans le canal Assistant.

### 24.9 Configuration (administrateur)

Le modèle LLM est configurable dans `.env.local` :

```env
LLM_MODEL=qwen2.5:3b
LLM_ENDPOINT=http://ollama:11434
LLM_SYSTEM_PROMPT="Tu es l'Assistant Roquette, un assistant virtuel d'aide pour l'application Roquette."
LLM_MODERATION_ENABLED=true
LLM_MAX_SUMMARY_MESSAGES=100
LLM_MAX_SUMMARY_BATCHES=5
```

---

## 25. Administration

### 25.1 Accès

Accessible depuis le menu utilisateur → **Administration**, ou via l'URL `/admin/users`. Cette section est réservée aux utilisateurs ayant le rôle `ROLE_ADMIN` ou, pour la section groupes, aux gestionnaires de groupes.

### 25.2 Gestion des utilisateurs

**URL** : `/admin/users`

Tableau listant tous les utilisateurs avec :

| Colonne | Détail |
|---|---|
| Avatar | Image du profil |
| Nom | Nom d'affichage |
| @username | Identifiant unique |
| Rôle | **Admin** ou **Utilisateur** |
| Statut | **Actif** ou **Banni** (avec motif) |

Actions disponibles :
- **Bannir** : bloque l'accès à l'application. Un motif est requis.
- **Débannir** : rétablit l'accès.

Pagination : 25 utilisateurs par page.

### 25.3 Gestion des groupes

**URL** : `/admin/groups`

Permet de gérer des groupes d'utilisateurs (utiles pour l'abonnement automatique aux canaux officiels).
> [!NOTE]
> Les utilisateurs nommés administrateurs d'un groupe spécifique (sans être administrateurs globaux de l'application) peuvent également accéder à ce panel pour gérer uniquement les membres des groupes qu'ils administrent.

Fonctionnalités :

- **Créer un groupe local** : nom + identifiant unique. Un canal privé officiel est automatiquement créé en association.
- **Rechercher dans l'annuaire** (LDAP/externe) : si configuré.
- **Importer des groupes** depuis l'annuaire externe.
- Lister les groupes administrés avec :
  - Nom et identifiant (DN pour LDAP).
  - Canal officiel lié (le cas échéant).
  - Administrateurs du groupe.
  - Nombre de membres.
  - Actions : gérer les membres (ajouter, supprimer ou nommer administrateur de groupe), modifier, supprimer.

### 25.4 Gestion des exports

**URL** : `/admin/exports`

Tableau listant tous les exports d'historique de canaux :

- Canal concerné.
- Date d'export.
- Utilisateur ayant exporté.
- Nom du fichier et taille.
- Actions : télécharger, supprimer.

### 25.5 Journaux d'audit

**URL** : `/admin/audit-logs`

Consigne toutes les actions critiques des administrateurs :

| Colonne | Détail |
|---|---|
| Date/heure | Horodatage de l'action |
| Administrateur | Utilisateur ayant effectué l'action |
| Type d'action | Bannissement, débannissement, création/suppression/export de canal, téléchargement/suppression d'export, création/suppression de groupe. Chaque type est coloré (pastille) pour identification rapide. |
| Détails | Informations contextuelles. |
| Adresse IP | IP de l'administrateur au moment de l'action. |

Pagination : 25 entrées par page.

### 25.6 Gestion des émojis personnalisés

**URL** : `/admin/emojis`

Permet aux administrateurs globaux de téléverser et de gérer la bibliothèque d'émojis personnalisés mis à disposition des utilisateurs.
- **Ajouter un émoji** : Renseignez un code d'appel (par exemple `smile` pour l'appeler via `[:smile]`) et téléversez un fichier image (le format GIF est supporté pour les émojis animés). Des étiquettes (tags) séparées par des virgules peuvent être renseignées pour faciliter la recherche.
- **Rechercher** : Un champ permet de chercher un émoji par son code ou ses tags.
- **Gérer les tags** : Modifiez à tout moment les étiquettes de recherche associées à un émoji ou utilisez les boutons rapides d'ajout/suppression directement sur les badges.
- **Supprimer** : Supprime définitivement l'émoji du serveur et de son stockage.

### 25.7 Gestion des espaces de travail (Workspaces)

**URL** : `/admin/workspaces`

Permet aux administrateurs globaux de visualiser l'ensemble des espaces de travail actifs sur l'application.
- Une liste répertorie tous les espaces, leur type (public/privé), leur créateur et leurs statistiques.
- Les administrateurs peuvent forcer la suppression d'un espace de travail (à l'exception de l'espace public par défaut).

### 25.8 Modération du contenu et alertes de sécurité

**URL** : `/admin/moderation`

Roquette intègre un double dispositif de protection et de modération automatique :

1. **Masquage préventif des secrets et identifiants** :
   Lorsqu'un utilisateur poste un message contenant des clés ou secrets sensibles (clés d'API OpenAI/Anthropic, clés AWS, Personal Access Tokens GitHub, jetons Slack, tokens JWT, identifiants de bases de données, clés privées SSH/RSA), ces données sont automatiquement masquées sous la forme `[SECRET MASQUÉ]` avant enregistrement et diffusion.

2. **Modération de toxicité par IA** :
   Les messages identifiés comme inappropriés, haineux ou insultants par l'analyse LLM sont placés en file de modération administrative.

3. **Interface de modération (`/admin/moderation`)** :
   - Présente tous les messages modérés avec le texte original, l'auteur, le canal et le motif de détection.
   - **Approuver** : rétablit le message dans son intégralité et actualise le canal en temps réel.
   - **Supprimer** : supprime définitivement le message litigieux.
   - Toutes les décisions sont automatiquement consignées dans les journaux d'audit (`MESSAGE_MODERATED`).

---

## 26. Export de l'historique

### 26.1 Fonctionnalité

Les **administrateurs du canal** peuvent exporter l'historique complet des messages d'un canal sous forme de page HTML standalone.

### 26.2 Procédure

1. Ouvrez le canal souhaité.
2. Menu d'actions (⋮) → **Exporter**.
3. Un fichier HTML est généré, contenant :
   - Tous les messages avec avatars, horodatages, noms d'affichage.
   - Pièces jointes (images, fichiers).
   - Code formaté avec coloration syntaxique.
4. Le fichier est téléchargeable et auto-suffisant (ne nécessite pas de connexion pour être consulté).

### 26.3 Accès aux exports (administration)

Les administrateurs système peuvent consulter, télécharger et supprimer tous les exports depuis la page **Administration → Exports**.

---

## 27. Limitations et contraintes techniques

| Élément | Limite |
|---|---|
| **Taille maximale d'un fichier** | 10 Mo |
| **Taille maximale d'un avatar (profil / workspace)** | 10 Mo |
| **Longueur du nom d'affichage** | 30 caractères |
| **Longueur du nom d'un canal** | 20 caractères |
| **Longueur du nom d'un workspace** | Non vide, 50 caractères max recommandés |
| **Longueur de la description d'un canal** | 50 caractères |
| **Longueur du nom d'une discussion** | 40 caractères (troncature du message source) |
| **Longueur minimale du mot de passe** | 6 caractères |
| **Teinte HSL du profil** | 0–360 |
| **Rétention des messages** | 1, 3, 6, 12 mois ou illimitée |
| **Options d'un sondage** | Minimum 2 |
| **Niveaux de priorité Kanban** | 4 (Basse, Moyenne, Haute, Urgente) |
| **Taille de pagination (administration)** | 25 éléments par page |
| **Nombre de messages pour résumé IA** | 100 derniers messages par lot (jusqu'à 5 lots) |
| **Validité d'une confirmation d'action IA** | 15 minutes |
| **Ping de session** | Toutes les 60 secondes |
| **Notification de bureau** | Nécessite une permission navigateur (API Notification) |
| **Connexion temps réel** | Nécessite Mercure (SSE). Un indicateur de connexion est affiché dans l'en-tête. |

---

## 28. Dépannage et FAQ

### 28.1 Je ne reçois pas de messages en temps réel

- Vérifiez l'indicateur de connexion Mercure dans l'en-tête (vert = connecté, rouge = déconnecté).
- Vérifiez que votre navigateur supporte les Server-Sent Events (tous les navigateurs modernes).
- Si vous êtes en mode **Occupé**, le rafraîchissement est suspendu.

### 28.2 Un fichier est bloqué

Le fichier a été détecté comme potentiellement malveillant par ClamAV. Contactez votre administrateur si vous pensez qu'il s'agit d'un faux positif.

### 28.3 Je n'arrive pas à modifier un message

Vous ne pouvez modifier que vos propres messages. Les messages des autres utilisateurs ne sont pas modifiables.

### 28.4 Je ne vois pas le bouton "Épingler"

Seuls le créateur du canal et les administrateurs peuvent épingler des messages.

### 28.5 Comment retrouver un message que j'ai vu récemment ?

Utilisez la **Recherche globale** (Ctrl+K) avec des mots-clés, ou la **Recherche par canal** dans l'en-tête.

### 28.6 Comment être alerté quand quelqu'un me mentionne ?

1. Allez dans **Mon compte**.
2. Activez les **notifications de bureau** et l'option **Notifications pour les mentions uniquement**.
3. Assurez-vous que votre navigateur autorise les notifications.

### 28.7 Les notifications de bureau ne fonctionnent pas

- Vérifiez les permissions de notification dans votre navigateur.
- Vérifiez que les notifications ne sont pas en sourdine au niveau du système d'exploitation.
- Vérifiez que le canal n'est pas en sourdine (🔕 dans l'en-tête).

### 28.8 L'Assistant IA ne répond pas

- Vérifiez que le service Ollama est en cours d'exécution (`docker compose ps`).
- Vérifiez la configuration dans `.env.local` (modèle, endpoint).
- L'Assistant peut prendre quelques instants pour répondre aux requêtes complexes.

### 28.9 Comment supprimer mon compte ?

La suppression de compte n'est pas disponible depuis l'interface utilisateur. Contactez un administrateur.

### 28.10 Erreur 403 / 404 / 500

Des pages d'erreur personnalisées sont affichées selon le type d'erreur :
- **403** : accès refusé (vous n'avez pas les permissions nécessaires).
- **404** : page ou canal introuvable.
- **500** : erreur interne du serveur (contactez un administrateur).

### 28.11 Comment rejoindre un espace de travail privé ?

Vous devez y être invité par le créateur du workspace ou par un administrateur global. Une fois l'invitation envoyée, elle s'affiche dans votre section "Invitations" de la barre latérale ainsi que sur votre tableau de bord des espaces (`/workspaces`).

### 28.12 Je ne trouve pas d'option pour quitter ou supprimer le workspace "Public"

L'espace de travail public est l'espace communautaire permanent par défaut de Roquette. Aucun utilisateur ne peut le quitter, et il ne peut pas être supprimé par les administrateurs afin de garantir une base de discussion commune permanente.

### 28.13 Comment basculer entre la vue Liste et la vue Tableau Kanban ?

Dans tout canal configuré en **Todo list**, cliquez simplement sur le bouton **📋 Kanban** situé dans l'en-tête du canal. Pour revenir au flux de discussion traditionnel, cliquez sur **📝 Liste**.

### 28.14 Pourquoi certains secrets ou clés d'API apparaissent comme `[SECRET MASQUÉ]` ?

Roquette intègre un mécanisme automatique de détection et protection contre la fuite d'identifiants sensibles (clés OpenAI, tokens GitHub/Slack, identifiants de bases de données, clés SSH, etc.). Tout secret détecté dans un message est masqué de manière irréversible avant sa diffusion.

### 28.15 Comment confirmer une action proposée par l'Assistant IA ?

Lorsque l'Assistant s'apprête à créer un sondage ou programmer un rappel, il vous demande confirmation. Vous pouvez valider soit en cliquant sur le bouton de confirmation sous son message, soit en lui répondant naturellement dans le chat par *"oui"*, *"ok"*, *"confirme"*, *"je valide"* ou *"go"*.

### 28.16 Comment générer une synthèse rapide d'un canal ?

Cliquez sur le bouton **✨ Résumer** dans l'en-tête du canal souhaité. Une fenêtre s'ouvre et affiche en streaming le résumé généré par l'IA des messages non lus ou des discussions récentes.

---

*Document mis à jour le 17 août 2026. Pour toute question, contactez l'équipe technique.*
