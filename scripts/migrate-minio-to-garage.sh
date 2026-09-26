#!/usr/bin/env bash
set -euo pipefail

# ==============================================================================
# Script de migration automatique de MinIO vers Garage pour Roquette
# ==============================================================================
# Ce script :
# 1. Sauvegarde tous les fichiers existants depuis le bucket MinIO.
# 2. Arrête les anciens conteneurs MinIO.
# 3. Met à jour les variables d'environnement dans .env et .env.local.
# 4. Construit et démarre le service Garage via Docker Compose.
# 5. Attend que Garage soit prêt et initialisé (layout, clé, bucket).
# 6. Réinjecte les fichiers sauvegardés dans le bucket Garage.
# 7. Redémarre le conteneur PHP.
# ==============================================================================

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_DIR"

BACKUP_DIR="$PROJECT_DIR/var/s3_export"
BUCKET_NAME="${AWS_BUCKET:-roquette-uploads}"
S3_KEY="${AWS_KEY:-minioadmin}"
S3_SECRET="${AWS_SECRET:-minioadminpassword}"

# Couleurs d'affichage
C_RESET="\033[0m"
C_GREEN="\033[1;32m"
C_YELLOW="\033[1;33m"
C_BLUE="\033[1;34m"
C_RED="\033[1;31m"

info() {
    echo -e "${C_BLUE}ℹ  $1${C_RESET}"
}

success() {
    echo -e "${C_GREEN}✔  $1${C_RESET}"
}

warn() {
    echo -e "${C_YELLOW}⚠  $1${C_RESET}"
}

error() {
    echo -e "${C_RED}✖  $1${C_RESET}" >&2
}

echo -e "${C_BLUE}======================================================${C_RESET}"
echo -e "${C_BLUE}   Migration de stockage Roquette : MinIO -> Garage   ${C_RESET}"
echo -e "${C_BLUE}======================================================${C_RESET}\n"

# 1. Détection du réseau Docker Compose
info "Détection du réseau Docker Compose..."
DOCKER_NETWORK="$(docker network ls --filter name=roquette --format '{{.Name}}' | grep -E 'default|roquette' | head -n 1 || true)"
if [ -z "$DOCKER_NETWORK" ]; then
    DOCKER_NETWORK="roquette_default"
fi
info "Réseau Docker utilisé : $DOCKER_NETWORK"

# 2. Détection de l'image client S3 (mc)
info "Sélection de l'image client S3..."
if docker image inspect minio/mc:latest >/dev/null 2>&1; then
    MC_IMAGE="minio/mc:latest"
    info "Utilisation de l'image locale minio/mc:latest"
else
    MC_IMAGE="cgr.dev/chainguard/minio-client:latest-dev"
    info "Téléchargement / utilisation de $MC_IMAGE..."
    docker pull "$MC_IMAGE"
fi

# 3. Sauvegarde des données MinIO (si MinIO est présent)
MINIO_CONTAINER="$(docker ps -a --filter "name=minio" --filter "status=running" --format '{{.Names}}' | grep -v 'init' | head -n 1 || true)"
if [ -z "$MINIO_CONTAINER" ]; then
    # Vérifier si un conteneur MinIO arrêté existe
    STOPPED_MINIO="$(docker ps -a --filter "name=minio" --format '{{.Names}}' | grep -v 'init' | head -n 1 || true)"
    if [ -n "$STOPPED_MINIO" ]; then
        info "Conteneur MinIO arrêté détecté ($STOPPED_MINIO). Démarrage temporaire pour export..."
        docker start "$STOPPED_MINIO" >/dev/null
        MINIO_CONTAINER="$STOPPED_MINIO"
        sleep 2
    fi
fi

mkdir -p "$BACKUP_DIR"

if [ -n "$MINIO_CONTAINER" ]; then
    info "Export des fichiers depuis MinIO ($MINIO_CONTAINER) vers $BACKUP_DIR..."
    docker run --rm --entrypoint /bin/sh \
        --network "$DOCKER_NETWORK" \
        -v "$BACKUP_DIR":/export \
        "$MC_IMAGE" -c "
            mc alias set src http://minio:9000 \"$S3_KEY\" \"$S3_SECRET\" &&
            mc mirror src/\"$BUCKET_NAME\" /export
        "
    success "Export terminé avec succès !"
else
    if [ -d "$BACKUP_DIR" ] && [ "$(ls -A "$BACKUP_DIR" 2>/dev/null)" ]; then
        warn "Aucun conteneur MinIO actif trouvé, mais des fichiers sont déjà présents dans $BACKUP_DIR. Ils seront utilisés pour la réinjection."
    else
        warn "Aucun conteneur MinIO actif ni export existant détecté. Poursuite avec un bucket Garage vide."
    fi
fi

# 4. Arrêt et suppression des conteneurs MinIO obsolètes
info "Nettoyage des anciens conteneurs MinIO..."
OLD_CONTAINERS="$(docker ps -a --filter "name=minio" --format '{{.Names}}' || true)"
if [ -n "$OLD_CONTAINERS" ]; then
    echo "$OLD_CONTAINERS" | xargs -r docker stop >/dev/null 2>&1 || true
    echo "$OLD_CONTAINERS" | xargs -r docker rm >/dev/null 2>&1 || true
    success "Anciens conteneurs MinIO arrêtés et supprimés."
fi

# 5. Mise à jour des variables d'environnement dans .env et .env.local
info "Mise à jour des variables d'environnement..."
for env_file in .env .env.local .env.test.local; do
    if [ -f "$env_file" ]; then
        if grep -q "minio:9000" "$env_file"; then
            sed -i 's|http://minio:9000|http://garage:3900|g' "$env_file"
            info "Mis à jour http://minio:9000 -> http://garage:3900 dans $env_file"
        fi
        if grep -q "localhost:9000" "$env_file"; then
            sed -i 's|http://localhost:9000|http://localhost:3900|g' "$env_file"
            info "Mis à jour http://localhost:9000 -> http://localhost:3900 dans $env_file"
        fi
        if grep -q "127.0.0.1:9000" "$env_file"; then
            sed -i 's|http://127.0.0.1:9000|http://127.0.0.1:3900|g' "$env_file"
            info "Mis à jour http://127.0.0.1:9000 -> http://127.0.0.1:3900 dans $env_file"
        fi
        if ! grep -q "AWS_REGION=" "$env_file"; then
            echo "AWS_REGION=garage" >> "$env_file"
            info "Ajouté AWS_REGION=garage dans $env_file"
        fi
    fi
done
success "Fichiers d'environnement à jour."

# 6. Démarrage et initialisation de Garage
info "Démarrage du service Garage via Docker Compose..."
docker compose up -d --build garage

info "Attente de l'état 'healthy' pour Garage..."
RETRIES=30
until [ "$RETRIES" -le 0 ] || [ "$(docker inspect "$(docker compose ps -q garage)" --format '{{.State.Health.Status}}' 2>/dev/null)" = "healthy" ]; do
    sleep 1
    RETRIES=$((RETRIES - 1))
done

if [ "$RETRIES" -le 0 ]; then
    error "Garage n'a pas atteint l'état 'healthy' à temps. Vérifiez les logs avec 'docker compose logs garage'."
    exit 1
fi
success "Garage est actif et en bonne santé !"

# 7. Réinjection des fichiers sauvegardés dans Garage
if [ -d "$BACKUP_DIR" ] && [ "$(ls -A "$BACKUP_DIR" 2>/dev/null)" ]; then
    info "Réinjection des fichiers depuis $BACKUP_DIR vers Garage..."
    docker run --rm --entrypoint /bin/sh \
        --network "$DOCKER_NETWORK" \
        -v "$BACKUP_DIR":/export \
        "$MC_IMAGE" -c "
            mc alias set dst http://garage:3900 \"$S3_KEY\" \"$S3_SECRET\" &&
            mc mirror /export dst/\"$BUCKET_NAME\"
        "
    success "Réinjection terminée avec succès !"
fi

# 8. Redémarrage du service PHP
info "Redémarrage du conteneur applicatif PHP..."
docker compose up -d php
success "Service PHP prêt !"

# 9. Affichage du statut du bucket
echo -e "\n${C_GREEN}======================================================${C_RESET}"
echo -e "${C_GREEN}   Migration vers Garage terminée avec succès !      ${C_RESET}"
echo -e "${C_GREEN}======================================================${C_RESET}\n"

info "État actuel du bucket Garage :"
docker compose exec -T garage /usr/local/bin/garage bucket info "$BUCKET_NAME" || true

echo -e "\n${C_YELLOW}Note importante :${C_RESET}"
echo -e "Les fichiers sauvegardés sont conservés dans : ${C_BLUE}$BACKUP_DIR${C_RESET}"
echo -e "L'ancien volume MinIO (s'il existait) est toujours présent sur la machine."
echo -e "Une fois vos vérifications terminées, vous pourrez supprimer la sauvegarde avec :"
echo -e "  rm -rf $BACKUP_DIR"
echo -e "  docker volume rm roquette_minio_data (si applicable)\n"
