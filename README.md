# Roquette

Roquette est une application de messagerie et de collaboration en temps réel (alternative à Slack ou Discord) développée avec Symfony 8.0, Twig, AssetMapper et Mercure Hub.

## Fonctionnalités

- **Messagerie en temps réel** : Envoi de messages, fils de discussion (threads), et notifications instantanées grâce à Symfony Mercure.
- **Canaux de discussion** : Création, gestion et favoris pour les canaux.
- **Réactions** : Ajout et gestion de réactions (emojis) sur les messages.
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
   Copier le fichier `.env` en `.env.local` et ajuster les variables nécessaires (identifiants de base de données, clés d'API, etc.) :
   ```bash
   cp .env .env.local
   ```

4. **Démarrer les services Docker** (PostgreSQL, Mercure Hub, ClamAV) :
   ```bash
   docker compose up -d
   ```

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

## Tests

L'application contient une suite de tests unitaires et fonctionnels. Pour les exécuter :

```bash
bin/phpunit
```

## Outils d'aide au développement (AI)

Ce projet intègre **AI Mate** pour faciliter l'assistance au code et l'automatisation de tâches à l'aide d'agents IA. La configuration et les instructions des agents se trouvent dans le répertoire `mate/`.
