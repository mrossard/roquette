# Roquette

Roquette est une application de messagerie et de collaboration en temps réel (alternative à Slack ou Discord) développée avec Symfony 8.0, Twig, AssetMapper et Mercure Hub.

## Fonctionnalités

- **Messagerie en temps réel** : Envoi de messages, fils de discussion (threads), et notifications instantanées grâce à Symfony Mercure.
- **Assistant virtuel & Synthèse (IA)** : Intégration de l'**Assistant Roquette** propulsé par un modèle LLM (via
  Ollama). Vous pouvez lui poser des questions privées avec la commande `/help` ou dialoguer directement dans un canal
  privé dédié (DM) pour lui demander de résumer n'importe quel canal auquel vous avez accès.
- **Messages enregistrés** : Marquez des messages importants avec une étoile pour les retrouver instantanément dans la
  section "Messages enregistrés" en haut de la barre latérale.
- **Canaux de discussion** : Création, gestion et favoris pour les canaux.
- **Réactions** : Ajout et gestion de réactions (emojis) sur les messages avec affichage au survol des utilisateurs
  ayant réagi.
- **Gestion de fichiers** : Importation de fichiers avec analyse antivirus intégrée via ClamAV.
- **Aperçus de liens** : Génération automatique de prévisualisations riches pour les URLs partagées.
- **Authentification** : Authentification classique et support OAuth2.
- **Personnalisation** : Thèmes et couleurs personnalisés par utilisateur.

## Prérequis

- **PHP 8.4** ou supérieur
- **Docker** & **Docker Compose**
- **Composer**

## Installation

1. **Cloner le projet** (une fois le dépôt configuré) et se placer dans le répertoire :
   ```bash
   git clone <repository_url>
   cd roquette
   ```

2. **Installer les dépendances PHP** :
   ```bash
   composer install
   ```

3. **Configurer les variables d'environnement** :
   Copier le fichier `.env` en `.env.local` et ajuster les variables nécessaires (identifiants de base de données, clés
   d'API, configurations LLM, etc.) :
   ```bash
   cp .env .env.local
   ```

   Pour générer les clés VAPID requises pour les notifications push :
   ```bash
   composer generate-vapid-keys
   ```
   La commande vous proposera de les écrire automatiquement dans `.env.local`.

4. **Démarrer les services Docker** (PostgreSQL, Mercure Hub, ClamAV, MinIO, Ollama) :
   ```bash
   docker compose up -d
   ```
   *Note : Au premier démarrage, le conteneur `ollama-pull-model` télécharge automatiquement le modèle de langage
   configuré (par défaut `qwen2.5:3b`) dans le conteneur Ollama.*

5. **Exécuter les migrations** de base de données :
   ```bash
   bin/console doctrine:migrations:migrate
   ```

6. **Installer les assets JavaScript (AssetMapper)** :
   ```bash
   bin/console importmap:install
   ```

7. **Lancer le serveur de développement** Symfony (si vous utilisez la CLI Symfony) :
   ```bash
   symfony server:start -d
   ```
   Ou accédez-y directement sur le port exposé par Docker (configuré par défaut sur le port 80 dans `compose.override.yaml`).

## Configuration de l'Assistant LLM (Ollama)

L'assistant virtuel utilise le bundle Symfony AI. Vous pouvez personnaliser la configuration dans votre fichier
`.env.local` :

```env
LLM_MODEL=qwen2.5:3b
LLM_ENDPOINT=http://ollama:11434
LLM_SYSTEM_PROMPT="Tu es l'Assistant Roquette, un assistant virtuel d'aide pour l'application Roquette."
```

## Tests

L'application contient une suite de tests unitaires et fonctionnels. Pour les exécuter :

```bash
bin/phpunit
```

## Outils d'aide au développement (AI)

Ce projet intègre **AI Mate** pour faciliter l'assistance au code et l'automatisation de tâches à l'aide d'agents IA. La configuration et les instructions des agents se trouvent dans le répertoire `mate/`.
