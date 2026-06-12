# Guide de l'utilisateur - Roquette

Bienvenue dans le guide de l'utilisateur de **Roquette**, votre plateforme de messagerie collaborative en temps réel (
alternative moderne à Slack et Discord). Ce guide détaille l'ensemble des fonctionnalités de l'application et comment
les exploiter au quotidien.

---

## Table des matières

1. [Prise en main](#1-prise-en-main)
2. [Gestion du profil et personnalisation](#2-gestion-du-profil-et-personnalisation)
3. [Canaux de discussion, discussions, messages directs et todo lists](#3-canaux-de-discussion-discussions-messages-directs-et-todo-lists)
4. [Messagerie, réactions, formatage et fils de discussion (Threads)](#4-messagerie-réactions-formatage-et-fils-de-discussion-threads)
5. [Épinglage de messages](#5-épinglage-de-messages)
6. [Sondages, fichiers et aperçus](#6-sondages-fichiers-et-aperçus)
7. [Webhooks entrants](#7-webhooks-entrants)
8. [Commandes de chat intégrées (Slash Commands)](#8-commandes-de-chat-intégrées-slash-commands)
9. [Notifications, mise en sourdine et recherche](#9-notifications-mise-en-sourdine-et-recherche)
10. [Assistant virtuel & Synthèse (IA)](#10-assistant-virtuel--synthèse-ia)
11. [Messages enregistrés](#11-messages-enregistrés)
12. [Mes réactions](#12-mes-réactions)

---

## 1. Prise en main

Pour commencer à utiliser Roquette :

* **Connexion / Inscription** : Connectez-vous avec vos identifiants ou créez un compte. Si cela a été configuré, vous
  pouvez utiliser la connexion externe via OAuth2.
* **Interface générale** : L'écran se divise en zones principales :
    * La **barre latérale (Sidebar)** à gauche : elle regroupe vos messages enregistrés, l'accès direct à l'Assistant,
      la liste des canaux, les messages directs et vos invitations.
    * La **fenêtre de discussion** au centre : elle affiche les messages du canal actif, le champ de saisie et les
      options de partage.
    * Le **panneau des discussions** à droite (si le canal actif possède des discussions) : affiche la liste des
      discussions associées au canal actuel.

---

## 2. Gestion du profil et personnalisation

Pour modifier vos paramètres de compte, accédez à la page Mon Compte. Vous y trouverez plusieurs options :

### Informations de profil

* **Nom d'affichage** : Modifiez le nom affiché pour vos collaborateurs (30 caractères max).
* **Couleur du profil** : Personnalisez la couleur de votre avatar et de votre pseudo à l'aide du curseur de teinte (
  Teinte HSL, de 0 à 360). La couleur se met à jour en temps réel sur l'interface.
* **Langue** : Basculez l'interface entre le Français (`fr`) et l'Anglais (`en`).

### Statut de présence

Indiquez votre disponibilité. Choisissez parmi :

* **Automatique** : Ajuste votre statut en ligne/absent en fonction de votre activité sur l'application.
* **En ligne** (Online).
* **Absent** (Away).
* **Occupé** (Busy) : Idéal pour travailler sans être interrompu.
* **Hors ligne** (Offline) : Vous apparaissez invisible pour les autres utilisateurs.

### Sécurité et notifications de bureau

* **Changement de mot de passe** : Mettez à jour votre mot de passe en saisissant votre mot de passe actuel et le
  nouveau (minimum 6 caractères).
* **Notifications de bureau** : Activez ou désactivez les notifications système de bureau globales ou spécifiquement
  pour les mentions.
* **Thème Clair / Sombre** : Basculez entre le thème sombre et clair à tout moment depuis les boutons de l'interface ou
  du menu utilisateur.

---

## 3. Canaux de discussion, discussions, messages directs et todo lists

Les discussions sont organisées en canaux de communication :

* **Canaux publics** : Accessibles et visibles par tous. Tout utilisateur peut les rejoindre ou les quitter librement.
* **Canaux privés** : Restreints et invisibles pour les non-membres. L'accès requiert une invitation par un membre
  existant.
* **Messages directs (DM)** : Discussions privées en tête-à-tête avec un autre utilisateur.
* **Canal Assistant** : Un canal de discussion privé avec l'**Assistant** (indiqué par un emoji 🤖 dans la barre latérale)
  est disponible pour poser des questions ou demander des synthèses de canaux.

### Les canaux Todo list (Tâches)

Vous pouvez créer des canaux dédiés à la gestion de tâches collaboratives :
* **Création & Édition** : Cochez l'option **Canal todo list** lors de la création d'un canal, ou cochez **Transformer en todo list** dans les paramètres d'une discussion pour en faire un gestionnaire de tâches.
* **Gestion des tâches** : Chaque message envoyé dans ce canal devient une tâche.
* **Validation d'une tâche** : Cliquez sur le menu d'actions (•••) du message/tâche et réagissez avec l'émoji coche verte `✅` (disponible dans le sélecteur rapide). Le message s'affichera alors barré pour signaler sa réalisation.
* **Discussion associée** : Vous pouvez cliquer sur le bouton **Discussion** associé à chaque tâche pour ouvrir une discussion dédiée.

### Les Discussions

Pour approfondir un sujet particulier mentionné dans un message sans encombrer le canal principal, vous pouvez créer une
**discussion** dédiée :

* **Création** : Survolez un message, cliquez sur le menu d'actions (•••) et sélectionnez **Discussion**.
* **Propriétés** : La discussion prend automatiquement pour nom le début du message source (jusqu'à 40 caractères) et
  copie la liste des membres, le niveau de confidentialité (public/privé) et la politique de rétention du canal parent.
* **Navigation** : Les discussions actives du canal en cours apparaissent dans le panneau latéral droit pour une
  navigation rapide.

### Actions et configuration des canaux :

* **Favoris (Étoile)** : Cliquez sur l'étoile à côté du nom d'un canal pour l'épingler dans la section "Favoris" en haut
  de la barre latérale.
* **Réorganisation** : Vous pouvez réorganiser vos canaux dans la barre latérale en cliquant sur le bouton d'organisation (`⇅` ou `✔️`) à côté de la section des canaux dans la barre latérale. Une fois le mode activé, glissez-déposez les canaux pour changer leur ordre, puis recliquez sur le bouton pour enregistrer.
* **Administrateurs du canal** : Les administrateurs (le créateur d'origine ainsi que tous les membres désignés comme tels) peuvent modifier les paramètres du canal (nom, description, politique de rétention, type de canal) et désigner d'autres membres comme administrateurs via l'interface de modification.
* **Rétention des messages** : Si vous êtes administrateur du canal, vous pouvez configurer une politique de rétention (de 1, 3, 6, 12 mois ou sans limite) pour purger automatiquement les anciens messages.
* **Invitations** : Invitez d'autres membres à rejoindre un canal privé depuis le bouton d'invitation.

---

## 4. Messagerie, réactions et fils de discussion (Threads)

### Écrire et formater des messages

Saisissez votre texte dans la barre de message et appuyez sur **Entrée** (ou utilisez le bouton d'envoi). Utilisez **Shift + Entrée** pour aller à la ligne.

* **Formatage (Markdown)** : L'application supporte le formatage standard Markdown et GitHub Flavored Markdown (GFM). Vous pouvez ajouter :
  * Du code en ligne en entourant le texte d'accents graves (ex: `` `code` ``).
  * Des blocs de code multilignes avec triple accent grave (ex: ` ```php ` ... ` ``` `).
  * Du texte en gras (`**texte**`), en italique (`*texte*`), des listes à puces (`-` ou `*`), des listes ordonnées (`1.`) et des citations (`>`).
* **Mentions et Références** :
  * `@nom_utilisateur` : Permet de mentionner un utilisateur. Le message sera surligné en bleu pour l'intéressé et des notifications spécifiques lui seront envoyées.
  * `#nom_canal` : Si vous tapez le slug d'un canal précédé de `#`, cela se transformera automatiquement en un lien cliquable redirigeant vers ce canal (si vous y avez accès).
* **Conversion d'émoticones et codes d'émojis** :
  * Les émoticones textuelles simples sont automatiquement converties en émojis (ex: `:)` devient 🙂, `<3` devient ❤️, `:D` devient 😀, `;)` devient 😉, etc.).
  * Les codes d'émojis standard (ex: `:rocket:`, `:fire:`, `:chat:`) sont traduits en émojis correspondants.
  * Les émojis animés/spécifiques sont supportés via la syntaxe `[:code]` (ex: `[:commence-a-plaire]`), s'ils sont configurés sur le serveur d'émojis.
* **Aperçu des images (Lightbox)** : Cliquez sur n'importe quelle image insérée ou téléversée dans la discussion pour l'ouvrir dans un aperçu en grand (Lightbox). Vous pouvez le refermer en recliquant à côté ou sur le bouton de fermeture.
* **Modification et suppression** : Modifiez ou supprimez vos propres messages depuis le menu d'actions (•••) du message.
* **Indicateur de saisie** : Lorsqu'un membre écrit, un indicateur discret s'affiche en bas du flux de discussion.

### Fils de discussion (Threads)

Pour répondre de manière ciblée à un message et suivre une discussion spécifique :

* **Répondre à un message** : Survolez le message en question, cliquez sur le menu d'actions (•••) et sélectionnez **Répondre** (ou cliquez sur le bouton de réponse rapide). Une bannière de contexte s'affiche au-dessus de votre champ de saisie (`↩ nom_utilisateur`). Saisissez votre texte et envoyez : votre message sera enregistré comme une réponse à ce message parent.
* **Consulter les réponses** : Sous un message ayant reçu des réponses, un lien apparaît (ex: `💬 Voir les réponses (3)`). Cliquez dessus pour charger l'intégralité du fil de discussion directement au centre de l'interface, dans le flux de messages principal.
* **Retourner au canal** : Cliquez sur le bouton **Retour au direct** en haut du fil de discussion pour revenir à l'affichage normal de tous les messages du canal.

### Réactions (Émojis)

* Survolez un message, cliquez sur le sélecteur d'émojis (icône de smiley) et choisissez une réaction rapide (👍, ❤️, 😂, 😮, 😢, 🎉) ou tout autre emoji. Dans un canal de type *Todo list*, l'émoji `✅` est également disponible en tête de liste.
* Cliquez sur une réaction existante sous un message pour ajouter votre vote (+1).
* Survolez une réaction pour voir la liste des utilisateurs qui l'ont ajoutée.

---

## 5. Épinglage de messages

Si vous êtes le créateur du canal (ou administrateur), vous pouvez mettre en avant des messages importants :

* **Épingler** : Dans le menu d'actions (•••) d'un message, cliquez sur **Épingler**.
* **Bannière d'épinglage** : Une bannière apparaît en haut du canal affichant le message épinglé actuel. Tous les
  membres peuvent cliquer sur **Voir** pour faire défiler automatiquement le chat jusqu'au message d'origine.
* **Désépingler** : Cliquez sur la croix (✕) de la bannière ou sélectionnez **Désépingler** dans le menu d'actions du
  message.

---

## 6. Sondages, fichiers et aperçus

### Créer un sondage
1. Cliquez sur l'icône de **Sondage** (ou utilisez l'option dans le formulaire).
2. Saisissez votre question et ajoutez au moins deux options de réponse.
3. Cochez "Autoriser les choix multiples" si nécessaire, puis publiez. Les votes s'actualisent en temps réel pour tous
   les utilisateurs.

### Partager des fichiers et des images

* Glissez-déposez un fichier sur l'interface ou utilisez le bouton trombone pour sélectionner un document (limite de 10
  Mo).
* **Sécurité** : Les fichiers téléversés sont analysés par l'antivirus ClamAV. Tout fichier détecté comme malveillant
  est bloqué pour protéger les utilisateurs.

### Prévisualisation de liens

Le partage d'une URL génère automatiquement un aperçu enrichi (titre, description, image) sous le message. Vous pouvez
masquer cet aperçu en cliquant sur la croix si vous en êtes l'auteur.

---

## 7. Webhooks entrants

Les webhooks permettent à des applications externes (GitHub, GitLab, serveurs de monitoring, etc.) de publier
automatiquement des messages dans vos canaux.

### Configuration (Administrateurs & Créateurs de canaux)

1. Ouvrez le menu de configuration du canal et allez dans la section **Webhooks entrants**.
2. Saisissez un nom descriptif pour votre webhook (ex: "Alertes Production") et cliquez sur **Créer**.
3. Copiez l'URL générée contenant un jeton de sécurité unique.
4. Vous pouvez activer/désactiver temporairement un webhook à l'aide du bouton **Actif/Inactif** ou le supprimer
   définitivement.

### Format du Payload (JSON)

Pour envoyer un message via le webhook, effectuez une requête HTTP **POST** sur l'URL du webhook avec un corps JSON
contenant au minimum l'attribut `text` ou `content` :

```json
{
    "text": "Le déploiement de la version 2.4.0 est réussi ! 🚀",
    "username": "Robot Déploiement",
    "avatar_url": "https://example.com/avatar.png"
}
```

* **Attributs acceptés** :
    * `text` ou `content` (requis) : Le contenu textuel du message (supporte la syntaxe Markdown standard).
    * `username` ou `customAuthorName` (optionnel) : Personnalise le nom d'affichage de l'émetteur du message.
    * `avatar_url` ou `customAuthorAvatar` (optionnel) : Personnalise l'avatar de l'émetteur du message.

---

## 8. Commandes de chat intégrées (Slash Commands)

Saisissez ces commandes au début de votre champ de saisie de message :

* `/me [action]` : Affiche un message d'action à la troisième personne (ex. : `/me prend une pause café` affichera
  `* Jean prend une pause café *` en italique).
* `/color [0-360]` : Modifie instantanément la couleur de votre avatar avec la teinte spécifiée. Sans argument, une
  teinte aléatoire est choisie.
* `/giphy [recherche]` : Recherche un GIF animé sur Tenor et affiche des suggestions. Cliquez sur le GIF de votre choix
  pour l'envoyer.
* `/shrug [texte]` : Ajoute l'émoji `¯\_(ツ)_/¯` à la fin de votre texte.
* `/help [votre question]` : Pose une question à l'Assistant virtuel sur l'utilisation de Roquette. La réponse s'affiche
  de manière privée, visible uniquement par vous.

---

## 9. Notifications, mise en sourdine et recherche

### Mettre en sourdine un canal (Mute)

Si un canal est trop actif :

* Cliquez sur l'icône de cloche / option de notification dans l'en-tête du canal.
* Basculez le canal en sourdine. Les indicateurs de messages non lus n'apparaîtront plus pour ce canal, sauf si vous y
  êtes directement mentionné.

### Outils de recherche

* **Recherche par canal** : Filtrez les messages du canal en cours en saisissant un mot-clé dans la barre de recherche
  située dans l'en-tête du canal.
* **Recherche globale** : Utilisez la barre de recherche principale en haut de l'interface. Vous pouvez appliquer des
  filtres avancés (recherche par auteur, par canal, présence de fichiers ou types de fichiers).

---

## 10. Assistant virtuel & Synthèse (IA)

L'Assistant virtuel est propulsé par l'intelligence artificielle pour vous aider à utiliser la plateforme et à rester
informé.

### A. La commande `/help`

Depuis n'importe quel canal, saisissez `/help <votre question>`. L'Assistant analyse la documentation et vous renvoie
une réponse privée instantanée.

### B. Le canal privé "Assistant" (🤖)

Accédez au canal privé de l'Assistant depuis la barre latérale pour dialoguer librement ou demander des résumés de
canaux :

* **Aide à l'utilisation** : Posez des questions sur l'application (ex: *"Comment configurer un webhook ?"*).
* **Résumer un canal** : Demandez un compte-rendu des échanges récents d'un canal (ex: *"Résume le canal général"*, *"Fais-moi un résumé du canal #projet-x"*).
  * *Fonctionnement du résumé* : L'Assistant résume en priorité les messages non lus dans le canal désigné pour vous permettre de rattraper votre retard. S'il n'y a aucun message non lu, il génère une synthèse thématique des derniers messages échangés (jusqu'à 100 messages).

*Note : Lors d'une demande complexe, l'Assistant affiche des étapes de feedback en temps réel ("Analyse de la demande...
🔍", puis "Recherche dans la documentation... ⏳" ou "Résumé du canal... ⏳") avant d'afficher sa réponse définitive. Si
vous changez de canal pendant que l'assistant génère sa réponse, celle-ci ne viendra pas perturber votre lecture
actuelle. À la place, un badge de message non lu apparaîtra sur le lien `🤖 Assistant` de la barre latérale pour vous
signaler que la réponse est disponible.*

---

## 11. Messages enregistrés

Sauvegardez des messages importants pour les consulter plus tard :

* **Enregistrer un message** : Survolez un message et cliquez sur l'étoile (⭐) dans la barre d'actions.
* **Consulter vos messages** : Cliquez sur **Messages enregistrés** sous la section **Raccourcis** tout en haut de la barre latérale pour afficher la liste de vos messages sauvegardés.
* **Retirer des favoris** : Cliquez de nouveau sur l'étoile (⭐) d'un message enregistré pour le retirer de votre liste.

---

## 12. Mes réactions

Retrouvez facilement les discussions auxquelles vous avez participé activement :

* **Consulter vos réactions** : Cliquez sur **Mes réactions** sous la section **Raccourcis** en haut de la barre latérale pour afficher tous les messages sur lesquels vous avez ajouté une réaction.
* **Filtrer par émoji** : Une fois sur la page de vos réactions, utilisez la liste d'émojis en haut pour filtrer les messages contenant un type de réaction spécifique (ex: afficher uniquement les messages où vous avez réagi avec `✅`).
