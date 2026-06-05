# Guide de l'utilisateur - Roquette

Bienvenue dans le guide de l'utilisateur de **Roquette**, votre plateforme de messagerie collaborative en temps réel (
alternative moderne à Slack et Discord). Ce guide détaille les fonctionnalités disponibles et comment les utiliser au
quotidien.

---

## Table des matières

1. [Prise en main](#1-prise-en-main)
2. [Gestion du profil et personnalisation](#2-gestion-du-profil-et-personnalisation)
3. [Canaux de discussion et messages directs](#3-canaux-de-discussion-et-messages-directs)
4. [Messagerie, réactions et fils de discussion (Threads)](#4-messagerie-réactions-et-fils-de-discussion-threads)
5. [Sondages, fichiers et aperçus](#5-sondages-fichiers-et-aperçus)
6. [Commandes de chat intégrées (Slash Commands)](#6-commandes-de-chat-intégrées-slash-commands)
7. [Notifications et recherche](#7-notifications-et-recherche)

---

## 1. Prise en main

Pour commencer à utiliser Roquette :

* **Connexion / Inscription** : Connectez-vous avec vos identifiants ou créez un compte. Si activé par votre
  administrateur, vous pouvez également vous connecter via un compte externe (OAuth2).
* **Interface générale** : L'écran se divise en deux zones principales :
    * La **barre latérale (Sidebar)** à gauche : elle regroupe votre profil, la liste des canaux, les messages directs
      et vos invitations.
    * La **fenêtre de discussion** au centre : elle affiche les messages du canal actif, le champ de saisie et les
      options de partage.

---

## 2. Gestion du profil et personnalisation

### Statut de présence

Indiquez votre disponibilité à vos collaborateurs. Cliquez sur l'indicateur de présence dans la barre latérale pour
choisir parmi :

* **Automatique** (Auto) : Ajuste votre statut en fonction de votre activité.
* **En ligne** (Online).
* **Absent** (Away).
* **Occupé** (Busy) : Idéal pour ne pas être dérangé.
* **Hors ligne** (Offline).

### Thème et Couleurs

Adaptez Roquette à vos préférences visuelles :

* **Mode Sombre / Clair** : Basculez entre le thème sombre et le thème clair depuis les paramètres de votre compte ou de
  l'interface utilisateur.
* **Couleur personnalisée** : Personnalisez la couleur de votre avatar et certains éléments de l'interface en modifiant
  la teinte (Hue, de 0 à 360). Vous pouvez le faire depuis les paramètres ou via la commande `/color` directement dans
  le chat.

---

## 3. Canaux de discussion et messages directs

Les discussions sont organisées en canaux de communication :

* **Canaux publics** : Accessibles à tous les membres de l'espace de travail. Tout utilisateur peut les rejoindre ou les
  quitter librement.
* **Canaux privés** : Restreints et invisibles pour les non-membres. Pour y accéder, vous devez y être invité par le
  créateur du canal.
* **Messages directs (DM)** : Discussions privées en tête-à-tête avec un collaborateur spécifique. Pour démarrer un DM,
  cliquez sur le nom d'un utilisateur ou utilisez le bouton de message direct dans la barre latérale.

### Actions sur les canaux :

* **Favoris (Étoile)** : Cliquez sur l'étoile à côté du nom du canal pour l'épingler tout en haut de votre barre
  latérale dans la section "Favoris".
* **Réorganisation** : Vous pouvez glisser-déposer ou réordonner vos canaux pour organiser votre barre latérale comme
  vous le souhaitez.
* **Rétention des messages** : Si vous êtes le créateur d'un canal, vous pouvez définir une politique de rétention pour
  supprimer automatiquement les messages après un certain nombre de jours.
* **Invitations** : Invitez d'autres membres à rejoindre un canal privé via le menu d'options du canal.

---

## 4. Messagerie, réactions et fils de discussion (Threads)

### Écrire des messages

Saisissez votre texte dans la barre de message en bas et appuyez sur **Entrée** (ou cliquez sur le bouton d'envoi).

* **Modification/Suppression** : Vous pouvez modifier ou supprimer vos propres messages en survolant le message concerné
  et en sélectionnant l'action appropriée.
* **Indicateur de saisie** : Lorsqu'un utilisateur commence à écrire, un indicateur discret apparaît en bas du chat pour
  vous informer qu'un message arrive.

### Fils de discussion (Threads)

Pour répondre de manière ciblée à un message sans polluer le canal principal, utilisez les fils de discussion :

* Survolez un message et cliquez sur l'icône de **Fil de discussion** (bulle de dialogue).
* Une barre latérale dédiée au fil de discussion s'ouvre sur la droite.
* Toutes les réponses restent groupées sous le message d'origine.

### Réactions (Émojis)

Exprimez-vous rapidement sans écrire de message :

* Survolez un message et cliquez sur le sélecteur d'émojis.
* Cliquez sur un émoji existant sous le message pour ajouter votre vote (+1).

---

## 5. Sondages, fichiers et aperçus

### Créer un sondage

Besoin de prendre une décision d'équipe ?

1. Cliquez sur l'icône de **Sondage** (ou utilisez l'option dans le formulaire).
2. Saisissez votre question.
3. Ajoutez au moins deux options de réponse.
4. Cochez "Autoriser les choix multiples" si les utilisateurs peuvent voter pour plusieurs options.
5. Envoyez pour publier le sondage interactif en temps réel.

### Partager des fichiers et des images

* Glissez-déposez un fichier dans la zone de saisie ou cliquez sur le trombone pour sélectionner un fichier.
* **Sécurité renforcée** : Chaque fichier téléversé est automatiquement analysé par l'antivirus ClamAV. Si un fichier
  est infecté, il est immédiatement bloqué pour protéger vos collaborateurs.

### Prévisualisation automatique des liens

Lorsque vous partagez un lien URL (comme une vidéo YouTube ou un article de blog), Roquette génère automatiquement un
aperçu enrichi (titre, description et image) directement sous votre message.

---

## 6. Commandes de chat intégrées (Slash Commands)

Saisissez ces commandes directement au début de votre champ de saisie de message :

* `/me [action]` : Affiche un message d'action à la troisième personne (ex. : `/me prend une pause café` affichera
  `* Jean prend une pause café *` avec un style distinct).
* `/color [0-360]` : Modifie instantanément la couleur de votre avatar avec la teinte spécifiée. Si aucun chiffre n'est
  fourni, une couleur aléatoire sera choisie.
* `/giphy [recherche]` : Recherche un GIF animé sur Tenor et affiche des suggestions. Cliquez sur le GIF de votre choix
  pour l'envoyer.
* `/shrug [texte]` : Ajoute automatiquement le célèbre émoji haussant les épaules `¯\_(ツ)_/¯` à la fin de votre texte.

---

## 7. Notifications et recherche

### Gérer les notifications

Si un canal est trop actif, vous pouvez le mettre en sourdine :

* Cliquez sur le nom du canal actif ou ouvrez son menu d'options.
* Sélectionnez **Désactiver les notifications** (Mute). Vous ne recevrez plus de alertes visuelles de message non lu
  pour ce canal, sauf si vous y êtes mentionné.

### Outils de recherche

Retrouvez facilement vos anciens échanges :

* **Recherche locale** : Filtrez les messages du canal en cours à l'aide de la barre de recherche du canal.
* **Recherche globale** : Utilisez la barre de recherche principale en haut de l'interface pour chercher des mots-clés
  dans l'ensemble des canaux auxquels vous avez accès.
