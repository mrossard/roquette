# Guide de l'utilisateur — Roquette

Bienvenue dans le guide de l'utilisateur de **Roquette**, plateforme de messagerie collaborative en temps réel (alternative moderne à Slack et Discord). Ce guide couvre l'intégralité des fonctionnalités de l'application, organisé par domaine fonctionnel.

---

## Table des matières

1. [Présentation générale](#1-présentation-générale)
2. [Prise en main](#2-prise-en-main)
3. [Gestion du compte et personnalisation](#3-gestion-du-compte-et-personnalisation)
4. [Interface utilisateur](#4-interface-utilisateur)
5. [Canaux de discussion](#5-canaux-de-discussion)
6. [Discussions (sous-canaux)](#6-discussions-sous-canaux)
7. [Todo lists (listes de tâches)](#7-todo-lists-listes-de-tâches)
8. [Messagerie](#8-messagerie)
9. [Formatage des messages (Markdown)](#9-formatage-des-messages-markdown)
10. [Mentions et références](#10-mentions-et-références)
11. [Émojis et réactions](#11-émojis-et-réactions)
12. [Fils de discussion (Threads)](#12-fils-de-discussion-threads)
13. [Épinglage de messages](#13-épinglage-de-messages)
14. [Sondages](#14-sondages)
15. [Fichiers et médias](#15-fichiers-et-médias)
16. [Aperçus de liens](#16-aperçus-de-liens)
17. [Webhooks entrants](#17-webhooks-entrants)
18. [Commandes slash](#18-commandes-slash)
19. [Recherche](#19-recherche)
20. [Notifications et mise en sourdine](#20-notifications-et-mise-en-sourdine)
21. [Messages enregistrés](#21-messages-enregistrés)
22. [Mes réactions](#22-mes-réactions)
23. [Assistant virtuel et synthèse IA](#23-assistant-virtuel-et-synthèse-ia)
24. [Administration](#24-administration)
25. [Export de l'historique](#25-export-de-lhistorique)
26. [Limitations et contraintes techniques](#26-limitations-et-contraintes-techniques)
27. [Dépannage et FAQ](#27-dépannage-et-faq)

---

## 1. Présentation générale

### 1.1 Qu'est-ce que Roquette ?

Roquette est une application web de messagerie instantanée et de collaboration en équipe. Elle permet à des groupes d'utilisateurs de communiquer en temps réel via des canaux de discussion publics ou privés, d'échanger des fichiers, de créer des sondages, de organiser des tâches, et de bénéficier d'un assistant IA.

### 1.2 Technologies utilisées

- **Temps réel** : Les messages et événements sont diffusés instantanément via Mercure (Server-Sent Events, SSE). Pas de WebSocket ni de polling.
- **Interface** : Rendu côté serveur avec Symfony Twig + HTMX. Pas de framework JavaScript (React, Vue, etc.). Les mises à jour DOM utilisent Idiomorph (morphing) pour des transitions fluides.
- **Base de données** : PostgreSQL 16.
- **IA** : Modèle de langage (LLM) via Ollama, intégré avec `symfony/ai-bundle`.

### 1.3 Concepts clés

| Concept | Description |
|---|---|
| **Canal** | Espace de discussion thématique. Peut être public (visible par tous) ou privé (visible uniquement sur invitation). |
| **Message direct (DM)** | Canal privé entre deux utilisateurs. |
| **Sous-canal / Discussion** | Canal fils rattaché à un message parent d'un canal principal, permettant de creuser un sujet sans polluer le flux principal. |
| **Todo list** | Canal dont chaque message est une tâche. |
| **Assistant** | Canal DM privé avec l'agent IA intégré. |
| **Favori** | Canal épinglé en haut de la barre latérale pour un accès rapide. |
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

- **Barre latérale (gauche)** : Raccourcis, favoris, canaux todo, canaux, messages directs, invitations.
- **Fenêtre centrale** : Messages du canal actif + champ de saisie.
- **Panneau droit** : Liste des sous-canaux du canal actif (visible uniquement si le canal a des discussions).
- **Panneau fichiers** : Bibliothèque média du canal actif (fichiers, images, documents).

---

## 3. Gestion du compte et personnalisation

Accédez à **Mon compte** via le menu utilisateur (en haut à droite).

### 3.1 Informations de profil

| Champ | Contrainte | Description |
|---|---|---|
| **Nom d'utilisateur** | Lecture seule, défini à la création | Identifiant unique, utilisé pour les mentions `@username`. |
| **Nom d'affichage** | 30 caractères max | Nom visible par les autres utilisateurs. Si vide, le nom d'utilisateur est utilisé. |
| **Couleur du profil (teinte)** | 0-360 (HSL Hue) | Couleur de l'avatar et du pseudo. Se met à jour en temps réel. Curseur interactif. |
| **Langue** | `fr` ou `en` | Langue de l'interface. |
| **Statut de présence** | Voir section 3.2 | Surcharge manuelle du statut. |

### 3.2 Statut de présence

Cinq statuts disponibles :

| Statut | Comportement |
|---|---|
| **Automatique** (par défaut) | Ajusté selon l'activité : actif = "En ligne", inactif = "Absent". |
| **En ligne** | Visible comme disponible. |
| **Absent** | Marqué comme inactif. |
| **Occupé** | Suspend les notifications de bureau et le rafraîchissement live de l'interface. Une modale de confirmation s'affiche. |
| **Hors ligne** | Apparaît invisible aux autres utilisateurs. |

Le statut est visible via un point de couleur sur l'avatar dans la barre latérale, l'en-tête de canal, et chaque message. Le dernier instant d'activité est tracé (`lastActiveAt`). Un utilisateur inactif pendant une durée configurable passe automatiquement en "Absent".

### 3.3 Changement de mot de passe

1. Remplissez les trois champs : mot de passe actuel, nouveau mot de passe, confirmation.
2. Contraintes : le mot de passe actuel doit être valide, le nouveau mot de passe doit faire au moins 6 caractères, la confirmation doit correspondre.
3. Validation : le formulaire affiche une erreur si un champ est vide ou si les contraintes ne sont pas respectées.

### 3.4 Notifications de bureau

| Option | Description |
|---|---|
| **Notifications des notifications** | Active/désactive globalement les notifications de bureau. |
| **Notifications pour les mentions uniquement** | Si activé, les notifications de bureau ne sont émises que lorsque vous êtes mentionné (`@username`). |

La souscription aux notifications push est gérée dynamiquement côté navigateur (API Notification + Service Worker).

### 3.5 Thème clair / sombre

Basculez entre les thèmes depuis le menu utilisateur (icône soleil/lune) ou depuis le bouton dédié dans l'en-tête. Le choix est persistant et appliqué instantanément sans rechargement de page.

---

## 4. Interface utilisateur

### 4.1 Barre d'en-tête (Header)

Éléments présents dans l'en-tête :

- **Logo** : Icône fusée + titre "Roquette".
- **Bouton menu mobile** : hamburger pour afficher/masquer la barre latérale sur mobile.
- **Recherche globale** (`Ctrl+K`) : raccourci clavier pour ouvrir la recherche.
- **Statut Mercure** : indicateur visuel de connexion au serveur temps réel (connecté / déconnecté).
- **Bascule thème** : icône soleil/lune.
- **Menu utilisateur** : avatar, nom, sélecteur de statut, lien "Mon compte", lien "Administration" (admin uniquement), déconnexion.

### 4.2 Barre latérale (Sidebar)

La barre latérale gauche est organisée en sections verticales :

1. **Raccourcis** :
   - Messages enregistrés
   - Mes réactions
   - Canal Assistant (🤖)

2. **Favoris** : Canaux marqués comme favoris (★). Les sous-canaux sont listés avec leur compteur de messages non lus.

3. **Todo lists** : Canaux de type todo list. Bouton "+" pour en créer un nouveau. Les sous-canaux sont affichés avec un badge `↳ #parent`.

4. **Canaux** : Tous les canaux (hors DM, todo, favoris). Les sous-canaux sont imbriqués sous leur parent.

5. **Messages directs** : Tous les DM (hors canal Assistant). Un point de statut coloré indique la présence du destinataire.

6. **Invitations** : Invitations en attente pour les canaux privés, avec boutons Accepter / Refuser.

**Actions sur la barre latérale** :

- **Créer un canal** : Depuis le menu d'options (⋮) en haut de la sidebar.
- **Filtrer par non lus** : N'affiche que les canaux avec des messages non lus.
- **Réorganiser** : Active le mode glisser-déposer pour réordonner les canaux. Cliquez sur "Terminé" pour sauvegarder.
- **Parcourir** : Ouvre l'annuaire des canaux publics.

### 4.3 En-tête de canal

Affiché en haut de la fenêtre de discussion, il contient :

- **Nom du canal** : avec icône représentative (`#` public, 🔒 privé, point de statut pour les DM).
- **Bouton favori** : ★ / ☆ pour ajouter/retirer des favoris.
- **Description du canal** (si définie).
- **Recherche dans le canal** : champ de recherche avec debounce 400ms et case à cocher "Non lus uniquement".
- **Masquer les tâches terminées** (canaux todo uniquement).
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

### 4.4 Panneau des discussions (latéral droit)

Lorsqu'un canal possède des sous-canaux (discussions), un panneau latéral droit s'affiche. Il liste :

- Le nom de chaque sous-canal (tronqué à 40 caractères depuis le message source).
- La description du sous-canal.
- Le compteur de messages non lus.
- Un bouton de paramètres pour le créateur du sous-canal.

### 4.5 Panneau des fichiers (latéral droit)

Onglets : **Tous** / **Images** / **Documents** / **Média**.

Chaque fichier affiche :

- Icône de type (image, audio, document).
- Nom du fichier.
- Taille.
- Auteur et date.
- Bouton "Aller au message" pour contextualiser.
- Aperçu : miniature cliquable pour les images, lecteur audio/video intégré pour les médias.

---

## 5. Canaux de discussion

### 5.1 Types de canaux

| Type | Description |
|---|---|
| **Canal public** | Visible et accessible par tous les utilisateurs. N'importe qui peut le rejoindre ou le quitter. |
| **Canal privé** | Invisible pour les non-membres. L'accès nécessite une invitation par un membre existant. |
| **Message direct (DM)** | Canal privé entre deux utilisateurs. S'ouvre via l'annuaire ou en cliquant sur un utilisateur. Automatiquement créé au premier message. |
| **Canal Assistant** | DM dédié avec l'assistant IA (🤖). Automatiquement lié dans les raccourcis. |
| **Canal Todo list** | Canal dont chaque message est une tâche (voir section 7). |
| **Discussion (sous-canal)** | Sous-canal rattaché à un message parent (voir section 6). |

### 5.2 Créer un canal

1. Cliquez sur le menu d'options (⋮) dans la barre latérale, puis **Créer un canal**.
2. Remplissez les champs :

| Champ | Contrainte | Description |
|---|---|---|
| **Nom** | 20 caractères max | Nom du canal visible dans la sidebar. |
| **Description** | 50 caractères max | Texte d'aide affiché dans l'en-tête. |
| **Rétention des messages** | 1, 3, 6, 12 mois ou Illimité | Durée de conservation avant purge automatique. |
| **Type de canal** | Discussion ou Todo list | Définit le comportement du canal. |
| **Canal privé** | Oui/Non | Restreint l'accès aux membres invités. |
| **Abonnement de groupe** | Optionnel | Permet d'abonner automatiquement tous les membres d'un groupe. Peut être défini comme "Canal officiel" du groupe. |

3. Confirmez la création. Les membres voient apparaître le canal dans leur barre latérale en temps réel.

### 5.3 Rejoindre un canal public

Depuis l'annuaire (`/channels/directory`) ou le bouton **Parcourir** dans la sidebar, cliquez sur **Rejoindre** à côté du canal souhaité.

### 5.4 Quitter un canal

Depuis le menu d'actions (⋮) de l'en-tête du canal, sélectionnez **Quitter le canal**. Vous ne recevrez plus les messages de ce canal.

### 5.5 Inviter des membres (canaux privés)

1. Depuis le menu d'actions (⋮) de l'en-tête, sélectionnez **Inviter**.
2. Recherchez un utilisateur par son nom.
3. Cliquez sur **Inviter**. L'utilisateur recevra une invitation dans sa barre latérale.
4. L'utilisateur invité peut **Accepter** ou **Refuser** l'invitation.

### 5.6 Paramètres du canal (administrateurs)

Depuis **Paramètres** dans le menu d'actions (⋮) :

- Modifier le nom (20 caractères max).
- Modifier la description (50 caractères max).
- Modifier la période de rétention des messages.
- Gérer les abonnements de groupe (ajout/suppression, canal officiel).
- Gérer les administrateurs :
  - Rechercher un utilisateur pour l'ajouter comme administrateur.
  - Retirer un administrateur (sauf le créateur).
  - Le créateur est listé séparément et ne peut pas être retiré.
- Supprimer le canal.

### 5.7 Favoris

Cliquez sur l'étoile (★) à côté du nom d'un canal (dans l'en-tête ou la barre latérale) pour l'ajouter aux favoris. Les favoris apparaissent en haut de la barre latérale dans une section dédiée.

### 5.8 Réorganisation des canaux

1. Cliquez sur le bouton d'organisation (⇅ ou ✔️) dans la sidebar.
2. Activez le mode réorganisation : les canaux deviennent glissables.
3. Glissez-déposez les canaux pour changer leur ordre.
4. Cliquez sur **Terminé** pour sauvegarder l'ordre.

### 5.9 Rétention des messages

Les administrateurs peuvent configurer une politique de rétention :
- **1 mois** : les messages de plus d'un mois sont automatiquement supprimés.
- **3 mois**, **6 mois**, **12 mois** : variantes.
- **Illimité** : aucun message n'est purgé.

La purge est automatique et s'exécute côté serveur.

### 5.10 Annuaire des canaux

Accessible depuis :
- Le bouton **Parcourir** dans la sidebar.
- L'URL `/channels/directory`.

L'annuaire présente deux onglets :
1. **Canaux publics** : liste de tous les canaux publics avec nom, description, nombre de membres, politique de rétention, boutons Rejoindre/Quitter/Ouvrir.
2. **Membres** : liste de tous les utilisateurs avec avatar, nom, statut, bouton pour ouvrir un DM.

La recherche filtre les résultats en temps réel au fur et à mesure de la saisie.

---

## 6. Discussions (sous-canaux)

### 6.1 Qu'est-ce qu'une discussion ?

Une **discussion** (ou sous-canal) est un canal secondaire rattaché à un message parent. Elle permet d'approfondir un sujet spécifique sans encombrer le flux principal.

### 6.2 Créer une discussion

Depuis un message :

1. Survolez le message.
2. Cliquez sur le menu d'actions (•••).
3. Sélectionnez **Discussion** (ou **Discussion Todo** pour créer une todo list).
4. La discussion est automatiquement créée avec :
   - **Nom** : le début du message source (40 caractères max).
   - **Membres** : copie de la liste des membres du canal parent.
   - **Visibilité** : hérite du niveau de confidentialité du parent (public/privé).
   - **Rétention** : hérite de la politique du parent.

### 6.3 Navigation

- Les sous-canaux du canal actif sont listés dans le panneau latéral droit.
- Cliquez sur un sous-canal pour y accéder.
- Depuis un sous-canal, un en-tête spécifique affiche :
  - Le message parent (avec son contenu complet).
  - Un bouton **Retour au canal parent**.
- Les sous-canaux apparaissent dans la barre latérale, imbriqués sous leur parent.

### 6.4 Supprimer une discussion

Depuis le panneau des discussions, le créateur peut supprimer le sous-canal via le bouton de paramètres.

---

## 7. Todo lists (listes de tâches)

### 7.1 Créer un canal todo

Deux méthodes :

1. **Lors de la création** : cochez **Canal todo list** dans le formulaire de création.
2. **Depuis un canal existant** : cochez **Transformer en todo list** dans les paramètres du canal.

Depuis la sidebar, un bouton "+" dans la section **Todo** permet de créer directement un nouveau canal todo.

### 7.2 Gérer les tâches

- Chaque message envoyé dans un canal todo devient une **tâche**.
- La tâche s'affiche comme un message normal mais avec une case à cocher visuelle.

### 7.3 Marquer une tâche comme terminée

1. Survolez la tâche.
2. Ouvrez le menu d'actions (•••) ou le sélecteur rapide d'émojis.
3. Sélectionnez l'émoji ✅ (coche verte).
4. Le message s'affiche alors **barré** (rayé) pour indiquer qu'il est terminé.

### 7.4 Masquer les tâches terminées

Utilisez le bouton **Masquer les tâches terminées** dans l'en-tête du canal pour n'afficher que les tâches en cours.

### 7.5 Discussion associée à une tâche

Chaque tâche peut avoir une discussion dédiée pour échanger sur son avancement :

1. Survolez la tâche.
2. Menu d'actions → **Discussion Todo**.
3. Un sous-canal est créé, rattaché à cette tâche.

---

## 8. Messagerie

### 8.1 Envoyer un message

1. Saisissez votre texte dans le champ de saisie en bas de la fenêtre.
2. Appuyez sur **Entrée** pour envoyer.
3. Utilisez **Shift + Entrée** pour insérer un saut de ligne.
4. Vous pouvez aussi cliquer sur le bouton d'envoi (avion en papier).

### 8.2 Modifier un message

1. Survolez votre message.
2. Cliquez sur le menu d'actions (•••).
3. Sélectionnez **Modifier**.
4. Un champ d'édition s'ouvre. Modifiez le contenu.
5. Confirmez avec **Enregistrer** ou annulez avec **Annuler**.

La mention `(modifié)` / `(modified)` apparaît à côté de l'horodatage.

### 8.3 Supprimer un message

1. Survolez votre message.
2. Cliquez sur le menu d'actions (•••).
3. Sélectionnez **Supprimer**.
4. Confirmez la suppression dans la boîte de dialogue.

La suppression est définitive.

### 8.4 Répondre à un message (citation)

1. Survolez le message auquel vous voulez répondre.
2. Cliquez sur le bouton **Répondre** (icône de réponse).
3. Une bannière de contexte s'affiche au-dessus du champ de saisie : `↩ @utilisateur`.
4. Saisissez votre message et envoyez-le.
5. Votre message sera lié au message parent.

### 8.5 Indicateur de saisie

Lorsqu'un membre est en train d'écrire dans le canal actif, un indicateur discret s'affiche en bas du flux :

- `X écrit...` (un utilisateur)
- `X et Y écrivent...` (deux utilisateurs)
- `Plusieurs personnes écrivent...` (trois utilisateurs ou plus)

L'indicateur disparaît automatiquement après quelques secondes d'inactivité.

### 8.6 Messages d'action (/me)

Les messages commençant par `/me` sont affichés différemment :

- Saisie : `/me prend un café`
- Affichage : `* Jean prend un café *` (italique, sans avatar ni nom d'utilisateur).

Ils sont traités comme des actions et non comme des messages de dialogue.

---

## 9. Formatage des messages (Markdown)

Roquette supporte le **Markdown standard** et le **GitHub Flavored Markdown (GFM)**.

### 9.1 Syntaxe de formatage

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

### 9.2 Blocs de code

Utilisez trois accents graves ouvrants et fermants avec le nom du langage pour la coloration syntaxique :

```php
function hello(): string {
    return 'Hello World';
}
```

Langages supportés : php, js, python, html, css, sql, bash, json, yaml, etc. (via highlight.js).

### 9.3 Aperçu en direct

Dans le champ de saisie, cliquez sur l'onglet **Aperçu** pour voir le rendu Markdown de votre message avant de l'envoyer. L'aperçu est généré côté serveur.

### 9.4 Barre d'outils de formatage

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

## 10. Mentions et références

### 10.1 Mentionner un utilisateur

Tapez `@` suivi du nom d'utilisateur :

- `@jean` : mentionne l'utilisateur "jean".
- Le message est surligné en bleu dans l'interface de l'utilisateur mentionné.
- Une notification de bureau est envoyée (si configurée).

**Autocomplétion** : en tapant `@`, une liste de suggestions d'utilisateurs apparaît.

### 10.2 Référencer un canal

Tapez `#` suivi du slug d'un canal :

- `#general` : lien cliquable vers le canal "general" (si vous y avez accès).
- La référence se transforme automatiquement en lien après envoi.

### 10.3 Autocomplétion avancée

Le système d'autocomplétion supporte trois types :

| Type | Déclencheur | Résultat |
|---|---|---|
| Utilisateurs | `@` | Suggestions d'utilisateurs (avatar + nom + @username). |
| Émojis personnalisés | `[:` | Suggestions d'émojis personnalisés (image + code `[:name]`). |
| Canaux | `#` | Suggestions de canaux (# + nom + slug). |

---

## 11. Émojis et réactions

### 11.1 Émojis dans les messages

- **Codes courts** : `:rocket:` devient 🚀, `:fire:` devient 🔥.
- **Émoticones textuelles** : conversion automatique :
  - `:)` → 🙂
  - `<3` → ❤️
  - `:D` → 😀
  - `;)` → 😉
  - `:(` → 🙁
  - `:/` → 😐
  - `:p` → 😋
  - `;D` → 😉
- **Émojis personnalisés** : `[:nom_emoji]` (si configurés sur le serveur).

### 11.2 Réagir à un message

1. Survolez un message.
2. Cliquez sur le sélecteur d'émojis (icône de smiley).
3. Choisissez une réaction rapide : 👍, ❤️, 😂, 😮, 😢, 🎉.
4. Dans un canal todo, ✅ est également disponible.
5. Vous pouvez aussi sélectionner n'importe quel émoji dans le sélecteur complet.

### 11.3 Ajouter son vote à une réaction existante

Cliquez sur une réaction déjà présente sous un message pour ajouter votre propre vote (+1).

### 11.4 Voir qui a réagi

Survolez une réaction avec la souris : une infobulle liste les utilisateurs qui ont ajouté cette réaction.

### 11.5 Retirer sa réaction

Cliquez à nouveau sur une réaction que vous avez déjà sélectionnée pour retirer votre vote.

---

## 12. Fils de discussion (Threads)

### 12.1 Créer un fil de discussion

1. Survolez un message.
2. Cliquez sur le bouton **Répondre** (ou menu d'actions → **Répondre**).
3. Saisissez votre réponse dans le champ qui s'affiche (bannière `↩ @utilisateur`).
4. Envoyez. Votre message est lié comme réponse au message parent.

### 12.2 Consulter un fil

- Sous un message ayant des réponses, un lien s'affiche : `💬 Voir les réponses (N)`.
- Cliquez dessus pour charger l'intégralité du fil dans le flux principal.
- Le fil s'affiche avec :
  - Le message parent en haut.
  - Toutes les réponses dans l'ordre chronologique.
  - Un bouton **Retour au direct** pour revenir à l'affichage normal du canal.

### 12.3 Comportement temps réel

- Lorsque vous consultez un fil, les nouveaux messages du canal principal ne s'affichent pas (pour éviter les distractions).
- Un badge de messages non lus s'affiche sur le canal pour signaler l'activité.

---

## 13. Épinglage de messages

### 13.1 Prérequis

Seuls le **créateur du canal** et les **administrateurs** peuvent épingler/désépingler des messages.

### 13.2 Épingler un message

1. Survolez le message.
2. Menu d'actions (•••) → **Épingler**.
3. Une bannière apparaît en haut du canal avec le contenu du message épinglé.

### 13.3 Voir le message épinglé

- La bannière en haut du canal affiche le message épinglé actuel.
- Cliquez sur **Voir** pour faire défiler automatiquement jusqu'au message d'origine dans le flux.

### 13.4 Désépingler un message

- Cliquez sur la croix (✕) de la bannière d'épinglage.
- Ou menu d'actions (•••) du message → **Désépingler**.

### 13.5 Limitation

Un seul message peut être épinglé à la fois dans un canal. Épingler un nouveau message remplace le précédent.

---

## 14. Sondages

### 14.1 Créer un sondage

1. Cliquez sur l'icône **Sondage** dans la barre d'outils de formatage.
2. Le composeur de sondage s'ouvre dans le champ de saisie.
3. Saisissez la **question** du sondage.
4. Ajoutez au **moins deux options** de réponse (bouton "+" pour ajouter, "✕" pour supprimer).
5. Activez éventuellement **Autoriser les choix multiples**.
6. Cliquez sur **Publier**.

### 14.2 Voter

- Les options de réponse s'affichent avec :
  - Un diagramme à barres proportionnel (largeur relative au nombre de votes).
  - Le nombre de votes par option.
  - Les avatars des votants (jusqu'à 5 affichés, avec "..." au-delà).
  - L'option la plus votée est mise en évidence.
- Cliquez sur une option pour voter.
- Si les choix multiples sont activés, vous pouvez sélectionner plusieurs options.

### 14.3 Modifier un sondage

1. Survolez le sondage.
2. Menu d'actions (•••) → **Modifier**.
3. Vous pouvez modifier la question, les options, et le type (choix unique/multiple).

### 14.4 Temps réel

Les votes s'actualisent en temps réel pour tous les utilisateurs via Mercure SSE.

---

## 15. Fichiers et médias

### 15.1 Envoyer un fichier

Deux méthodes :

1. **Glisser-déposer** : faites glisser un fichier depuis votre explorateur vers la fenêtre de discussion.
2. **Bouton trombone** : cliquez sur le bouton de jointure dans le champ de saisie pour sélectionner un fichier.

### 15.2 Limites

- Taille maximale : **10 Mo** par fichier.
- Types acceptés : tous types de fichiers (images, documents, vidéos, audio, archives, etc.).

### 15.3 Scan antivirus (ClamAV)

Tous les fichiers téléversés sont analysés par **ClamAV** :

| Statut | Affichage |
|---|---|
| **Analyse en cours** | Icône de chargement (spinner). |
| **Fichier sain** | Lien de téléchargement disponible. |
| **Fichier infecté** | Message "Fichier bloqué" — le téléchargement est impossible. |
| **Erreur d'analyse** | Message "Analyse impossible" — le fichier est accessible mais l'analyse a échoué. |

### 15.4 Prévisualisations

| Type | Comportement |
|---|---|
| **Image** | Affichée directement dans le flux. Cliquez dessus pour l'ouvrir en **lightbox** (pleine taille). |
| **Audio** | Lecteur audio intégré (`.mp3`, `.wav`, `.ogg`, `.flac`, etc.). |
| **Vidéo** | Lecteur vidéo intégré (`.mp4`, `.webm`, `.ogg`, etc.). |
| **PDF** | Lien de visualisation directe (ouvre dans un nouvel onglet). |
| **Fichier texte** | Bouton **Aperçu texte** qui charge le contenu avec coloration syntaxique. |

### 15.5 Médiathèque (bibliothèque de fichiers)

Le panneau latéral des fichiers liste tous les fichiers partagés dans le canal actif, organisé par onglets :

- **Tous** : tous les fichiers.
- **Images** : uniquement les images.
- **Documents** : PDF, texte, code, etc.
- **Média** : fichiers audio et vidéo.

Chaque fichier peut être téléchargé ou contextualisé ("Aller au message").

---

## 16. Aperçus de liens

### 16.1 Fonctionnement

Lorsque vous partagez une URL dans un message, Roquette tente de générer automatiquement un **aperçu enrichi** :

- Titre de la page.
- Description.
- Image de couverture (Open Graph).
- Nom du site.

### 16.2 Délai d'affichage

L'aperçu est chargé de manière asynchrone après l'envoi du message, avec un délai (lazy loading via Intersection Observer).

### 16.3 Masquer un aperçu

Si vous êtes l'auteur du message, vous pouvez masquer l'aperçu en cliquant sur la croix (✕) de la carte d'aperçu.

### 16.4 Images distantes

Les URLs pointant directement vers des images (`.jpg`, `.png`, `.gif`, `.webp`, etc.) sont rendues inline dans le flux, sans carte d'aperçu. Cliquez dessus pour les ouvrir en lightbox.

---

## 17. Webhooks entrants

### 17.1 Présentation

Les webhooks entrants permettent à des applications externes (GitHub, GitLab, serveurs de monitoring, scripts CI/CD, etc.) de publier automatiquement des messages dans un canal Roquette via une requête HTTP POST.

### 17.2 Configuration (administrateurs du canal)

1. Ouvrez le menu de configuration du canal (Paramètres).
2. Sélectionnez l'onglet **Webhooks entrants**.
3. Saisissez un nom descriptif (ex: "Alertes Production").
4. Cliquez sur **Créer**.
5. Copiez l'URL générée contenant un jeton de sécurité unique.
6. Collez cette URL dans l'application externe.

### 17.3 Gestion des webhooks

| Action | Description |
|---|---|
| **Activer/Désactiver** | Bascule le webhook sans le supprimer. |
| **Supprimer** | Supprime définitivement le webhook. Le jeton n'est plus valide. |
| **Copier l'URL** | Permet de récupérer l'URL du webhook. |

### 17.4 Format du payload (JSON)

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

### 17.5 Exemple avec cURL

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

## 18. Commandes slash

Les commandes slash s'utilisent en début de message dans le champ de saisie.

### 18.1 `/me [action]`

Affiche un message d'action à la troisième personne.

**Exemple** :
```
/me prend une pause café
```
**Résultat** : `* Jean prend une pause café *` (affiché en italique)

### 18.2 `/color [teinte]`

Modifie instantanément la couleur de votre avatar et de votre pseudo.

- `teinte` : valeur de 0 à 360 (teinte HSL).
- Sans argument : une teinte aléatoire est choisie.
- La nouvelle couleur est persistante (identique à la section "Mon compte").

**Exemples** :
```
/color 200   → teinte bleue
/color       → teinte aléatoire
```

### 18.3 `/giphy [recherche]`

Recherche un GIF animé sur **Tenor** et affiche une grille de suggestions.

1. Tapez `/giphy chat` par exemple.
2. Une grille de GIFs s'affiche dans le champ de saisie.
3. Cliquez sur le GIF de votre choix.
4. Le GIF est envoyé comme message (lien markdown avec image).

Pour annuler, cliquez sur le bouton d'annulation.

### 18.4 `/shrug [texte]`

Ajoute l'émoji `¯\_(ツ)_/¯` à la fin de votre texte.

**Exemple** :
```
/shrug je ne sais pas
```
**Résultat** : `je ne sais pas ¯\_(ツ)_/¯`

### 18.5 `/help [question]`

Pose une question à l'Assistant IA sur l'utilisation de Roquette.

- La réponse s'affiche de manière privée, visible uniquement par vous.
- Utilise la documentation de l'application pour répondre.

**Exemple** :
```
/help Comment créer un sondage ?
```

Voir aussi la [section 23](#23-assistant-virtuel-et-synthèse-ia) pour plus d'options.

---

## 19. Recherche

### 19.1 Recherche globale (Ctrl+K)

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

### 19.2 Recherche par canal

- Champ de recherche dans l'en-tête du canal.
- Résultats filtrés dans le canal actif uniquement.
- Option **Non lus uniquement** pour limiter aux messages non lus.
- Debounce de 400ms pour éviter les appels inutiles.

### 19.3 Filtre "Non lus"

Depuis l'en-tête du canal, activez le filtre **Non lus** pour n'afficher que les messages que vous n'avez pas encore vus. Un compteur indique le nombre de messages non lus. Un bouton **Retour au direct** permet de revenir à l'affichage normal.

---

## 20. Notifications et mise en sourdine

### 20.1 Notifications de bureau

Configurables depuis **Mon compte** :
- **Activation globale** : active/désactive toutes les notifications de bureau.
- **Mentions uniquement** : ne notifier que lorsque vous êtes mentionné (`@username`).

### 20.2 Mettre en sourdine un canal (Mute)

Si un canal est trop actif :

1. Cliquez sur l'icône de cloche (🔔) dans l'en-tête du canal.
2. La cloche devient barrée (🔕) : le canal est en sourdine.
3. Les indicateurs de messages non lus n'apparaissent plus.
4. Exception : vous serez toujours notifié si vous êtes directement mentionné.

Pour réactiver, cliquez à nouveau sur l'icône.

### 20.3 Mode "Occupé"

Lorsque vous passez votre statut en **Occupé** :
- Les notifications de bureau sont suspendues.
- Le rafraîchissement automatique de l'interface est suspendu.
- Une modale de confirmation s'affiche pour vous prévenir.

### 20.4 Heartbeat (ping)

Un ping périodique (toutes les 60 secondes) est envoyé au serveur pour maintenir votre session active et mettre à jour votre statut de présence.

---

## 21. Messages enregistrés

### 21.1 Enregistrer un message

1. Survolez un message.
2. Cliquez sur l'étoile (⭐) dans la barre d'actions.
3. L'étoile se remplit : le message est enregistré.

### 21.2 Consulter ses messages enregistrés

- Depuis la barre latérale : cliquez sur **Messages enregistrés** dans la section **Raccourcis**.
- URL : `/saved-messages`.
- La page affiche la liste chronologique inversée de tous vos messages enregistrés, avec le nom du canal source.
- Cliquez sur un message pour accéder à son contexte dans le canal d'origine.

### 21.3 Retirer un message

- Cliquez à nouveau sur l'étoile (⭐) d'un message enregistré.
- Ou depuis la page "Messages enregistrés", cliquez sur l'étoile pour le retirer.

---

## 22. Mes réactions

### 22.1 Consulter ses réactions

- Depuis la barre latérale : cliquez sur **Mes réactions** dans la section **Raccourcis**.
- URL : `/my-reactions`.
- La page affiche tous les messages sur lesquels vous avez ajouté une réaction, classés chronologiquement.

### 22.2 Filtrer par émoji

- Une barre de filtrage en haut de la page liste tous les émojis que vous avez utilisés.
- Cliquez sur un émoji pour filtrer : seuls les messages avec cet émoji spécifique sont affichés.
- URL avec filtre : `/my-reactions/{emoji}` (ex: `/my-reactions/❤️`).

### 22.3 Utilité

Cette fonctionnalité vous permet de retrouver facilement les discussions auxquelles vous avez participé activement, sans avoir à parcourir l'historique complet.

---

## 23. Assistant virtuel et synthèse IA

### 23.1 Présentation

L'Assistant virtuel Roquette est propulsé par un modèle de langage (LLM) via Ollama. Il peut vous aider à :

- Comprendre comment utiliser Roquette.
- Résumer des canaux de discussion.
- Répondre à des questions générales.

### 23.2 Commande `/help`

Depuis **n'importe quel canal**, saisissez :

```
/help Comment configurer un webhook ?
```

Fonctionnement :
1. L'Assistant analyse la question.
2. Il recherche dans la documentation de l'application.
3. La réponse s'affiche de manière privée (visible uniquement par vous) directement dans le flux du canal actif.

### 23.3 Canal privé Assistant (🤖)

Accédez au canal privé de l'Assistant depuis la barre latérale (section **Raccourcis**).

Ce canal permet de :
- **Poser des questions** sur l'utilisation de l'application.
- **Demander un résumé de canal** : l'Assistant analyse les messages récents d'un canal et génère une synthèse.

**Utilisation pour un résumé** :
```
Résume le canal général
Fais-moi un résumé du canal #projet-x
```

Fonctionnement du résumé :
1. L'Assistant priorise les **messages non lus** du canal désigné.
2. S'il n'y a aucun message non lus, il génère une synthèse thématique des **100 derniers messages**.
3. Le résumé apparaît dans votre canal privé Assistant.

### 23.4 Retour en temps réel (streaming)

Lors d'une requête complexe, l'Assistant affiche des étapes de progression :

1. `Analyse de la demande... 🔍`
2. `Recherche dans la documentation... ⏳` (pour `/help`)
3. `Résumé du canal... ⏳` (pour les résumés)
4. La réponse définitive s'affiche.

### 23.5 Navigation pendant la génération

Si vous changez de canal pendant que l'Assistant génère une réponse :

- La réponse ne perturbe pas votre lecture actuelle.
- Un badge de message non lus apparaît sur le lien `🤖 Assistant` dans la barre latérale.
- La réponse est disponible quand vous revenez.

### 23.6 Configuration (administrateur)

Le modèle LLM est configurable dans `.env.local` :

```env
LLM_MODEL=qwen2.5:3b
LLM_ENDPOINT=http://ollama:11434
LLM_SYSTEM_PROMPT="Tu es l'Assistant Roquette, un assistant virtuel d'aide pour l'application Roquette."
```

---

## 24. Administration

### 24.1 Accès

Accessible depuis le menu utilisateur → **Administration**, ou via l'URL `/admin/users`. Cette section est réservée aux utilisateurs ayant le rôle `ROLE_ADMIN`.

### 24.2 Gestion des utilisateurs

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

### 24.3 Gestion des groupes

**URL** : `/admin/groups`

Permet de gérer des groupes d'utilisateurs (utiles pour l'abonnement automatique aux canaux).

Fonctionnalités :

- **Créer un groupe local** : nom + identifiant unique.
- **Rechercher dans l'annuaire** (LDAP/externe) : si configuré.
- **Importer des groupes** depuis l'annuaire externe.
- Lister les groupes locaux avec :
  - Nom et identifiant (DN pour LDAP).
  - Canal officiel lié (le cas échéant).
  - Administrateurs du groupe.
  - Nombre de membres.
  - Actions : gérer les membres, modifier, supprimer.

**Gestion des membres d'un groupe** :

- **Groupes locaux** :
  - Ajouter un membre via autocomplétion.
  - Nommer un administrateur du groupe.
  - Retirer un membre ou un administrateur.
- **Groupes externes (synchro)** :
  - Liste en lecture seule.
  - Les utilisateurs sans compte sont marqués "Non enregistré".

### 24.4 Gestion des exports

**URL** : `/admin/exports`

Tableau listant tous les exports d'historique de canaux :

- Canal concerné.
- Date d'export.
- Utilisateur ayant exporté.
- Nom du fichier et taille.
- Actions : télécharger, supprimer.

### 24.5 Journaux d'audit

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

---

## 25. Export de l'historique

### 25.1 Fonctionnalité

Les **administrateurs du canal** peuvent exporter l'historique complet des messages d'un canal sous forme de page HTML standalone.

### 25.2 Procédure

1. Ouvrez le canal souhaité.
2. Menu d'actions (⋮) → **Exporter**.
3. Un fichier HTML est généré, contenant :
   - Tous les messages avec avatars, horodatages, noms d'affichage.
   - Pièces jointes (images, fichiers).
   - Code formaté avec coloration syntaxique.
4. Le fichier est téléchargeable et auto-suffisant (ne nécessite pas de connexion pour être consulté).

### 25.3 Accès aux exports (administration)

Les administrateurs système peuvent consulter, télécharger et supprimer tous les exports depuis la page **Administration → Exports**.

---

## 26. Limitations et contraintes techniques

| Élément | Limite |
|---|---|
| **Taille maximale d'un fichier** | 10 Mo |
| **Longueur du nom d'affichage** | 30 caractères |
| **Longueur du nom d'un canal** | 20 caractères |
| **Longueur de la description d'un canal** | 50 caractères |
| **Longueur du nom d'une discussion** | 40 caractères (troncature du message source) |
| **Longueur minimale du mot de passe** | 6 caractères |
| **Teinte HSL du profil** | 0–360 |
| **Rétention des messages** | 1, 3, 6, 12 mois ou illimitée |
| **Options d'un sondage** | Minimum 2 |
| **Taille de pagination (administration)** | 25 éléments par page |
| **Nombre de messages pour résumé IA** | 100 derniers messages maximum |
| **Ping de session** | Toutes les 60 secondes |
| **Notification de bureau** | Nécessite une permission navigateur (API Notification) |
| **Connexion temps réel** | Nécessite Mercure (SSE). Un indicateur de connexion est affiché dans l'en-tête. |

---

## 27. Dépannage et FAQ

### 27.1 Je ne reçois pas de messages en temps réel

- Vérifiez l'indicateur de connexion Mercure dans l'en-tête (vert = connecté, rouge = déconnecté).
- Vérifiez que votre navigateur supporte les Server-Sent Events (tous les navigateurs modernes).
- Si vous êtes en mode **Occupé**, le rafraîchissement est suspendu.

### 27.2 Un fichier est bloqué

Le fichier a été détecté comme potentiellement malveillant par ClamAV. Contactez votre administrateur si vous pensez qu'il s'agit d'un faux positif.

### 27.3 Je n'arrive pas à modifier un message

Vous ne pouvez modifier que vos propres messages. Les messages des autres utilisateurs ne sont pas modifiables.

### 27.4 Je ne vois pas le bouton "Épingler"

Seuls le créateur du canal et les administrateurs peuvent épingler des messages.

### 27.5 Comment retrouver un message que j'ai vu récemment ?

Utilisez la **Recherche globale** (Ctrl+K) avec des mots-clés, ou la **Recherche par canal** dans l'en-tête.

### 27.6 Comment être alerté quand quelqu'un me mentionne ?

1. Allez dans **Mon compte**.
2. Activez les **notifications de bureau** et l'option **Notifications pour les mentions uniquement**.
3. Assurez-vous que votre navigateur autorise les notifications.

### 27.7 Les notifications de bureau ne fonctionnent pas

- Vérifiez les permissions de notification dans votre navigateur.
- Vérifiez que les notifications ne sont pas en sourdine au niveau du système d'exploitation.
- Vérifiez que le canal n'est pas en sourdine (🔕 dans l'en-tête).

### 27.8 L'Assistant IA ne répond pas

- Vérifiez que le service Ollama est en cours d'exécution (`docker compose ps`).
- Vérifiez la configuration dans `.env.local` (modèle, endpoint).
- L'Assistant peut prendre quelques instants pour répondre aux requêtes complexes.

### 27.9 Comment supprimer mon compte ?

La suppression de compte n'est pas disponible depuis l'interface utilisateur. Contactez un administrateur.

### 27.10 Erreur 403 / 404 / 500

Des pages d'erreur personnalisées sont affichées selon le type d'erreur :
- **403** : accès refusé (vous n'avez pas les permissions nécessaires).
- **404** : page ou canal introuvable.
- **500** : erreur interne du serveur (contactez un administrateur).

---

*Document généré le 14 juin 2026. Pour toute question, contactez l'équipe technique.*
