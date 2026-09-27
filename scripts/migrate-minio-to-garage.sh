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

ORIGINAL_PWD="$(pwd)"
PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_DIR"

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

show_help() {
    cat << EOF
Usage: $0 [OPTIONS] [ENV_FILE]

Migration automatique de stockage MinIO vers Garage pour Roquette.

Options:
  -e, --env-file <FICHIER>   Chemin vers le fichier d'environnement (.env, .env.local, etc.)
  -h, --help                 Affiche cette aide

Arguments:
  ENV_FILE                   Chemin vers le fichier d'environnement (optionnel)

Si aucun fichier d'environnement n'est spécifié, le script utilise automatiquement :
1. .env.local (si présent)
2. .env (sinon)
EOF
}

# Analyse des arguments CLI
ENV_FILE=""
while [ $# -gt 0 ]; do
    case "$1" in
        -e|--env-file)
            if [ -z "${2:-}" ]; then
                error "L'option $1 requiert un chemin de fichier."
                exit 1
            fi
            ENV_FILE="$2"
            shift 2
            ;;
        --env-file=*)
            ENV_FILE="${1#*=}"
            shift
            ;;
        -h|--help)
            show_help
            exit 0
            ;;
        -*)
            error "Option inconnue : $1"
            show_help
            exit 1
            ;;
        *)
            if [ -z "$ENV_FILE" ]; then
                ENV_FILE="$1"
                shift
            else
                error "Argument inattendu : $1"
                show_help
                exit 1
            fi
            ;;
    esac
done

# Résolution du fichier d'environnement
if [ -n "$ENV_FILE" ]; then
    if [[ "$ENV_FILE" != /* ]]; then
        if [ -f "$ORIGINAL_PWD/$ENV_FILE" ]; then
            ENV_FILE="$ORIGINAL_PWD/$ENV_FILE"
        elif [ -f "$PROJECT_DIR/$ENV_FILE" ]; then
            ENV_FILE="$PROJECT_DIR/$ENV_FILE"
        fi
    fi
    if [ ! -f "$ENV_FILE" ]; then
        error "Le fichier d'environnement spécifié n'existe pas : $ENV_FILE"
        exit 1
    fi
else
    if [ -f "$PROJECT_DIR/.env.local" ]; then
        ENV_FILE="$PROJECT_DIR/.env.local"
    elif [ -f "$PROJECT_DIR/.env" ]; then
        ENV_FILE="$PROJECT_DIR/.env"
    fi
fi

# Fonction pour extraire une variable d'un fichier .env
parse_env_var() {
    local file="$1"
    local var_name="$2"
    if [ -f "$file" ]; then
        local line
        line="$(grep -E "^[[:space:]]*${var_name}[[:space:]]*=" "$file" | tail -n 1 || true)"
        if [ -n "$line" ]; then
            local val="${line#*=}"
            val="$(echo "$val" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
            if [[ "$val" =~ ^\"(.*)\"$ ]]; then
                val="${BASH_REMATCH[1]}"
            elif [[ "$val" =~ ^\'(.*)\'$ ]]; then
                val="${BASH_REMATCH[1]}"
            else
                val="$(echo "$val" | sed -e 's/[[:space:]]*#.*$//')"
            fi
            echo "$val"
            return 0
        fi
    fi
    return 1
}

# Arguments Docker Compose pour les fichiers d'environnement
COMPOSE_ENV_ARGS=()
if [ -n "$ENV_FILE" ]; then
    if [ -f "$PROJECT_DIR/.env" ] && [ "$(realpath "$ENV_FILE")" != "$(realpath "$PROJECT_DIR/.env")" ]; then
        COMPOSE_ENV_ARGS+=(--env-file "$PROJECT_DIR/.env")
    fi
    COMPOSE_ENV_ARGS+=(--env-file "$ENV_FILE")
fi

# Récupération des variables S3 (shell > ENV_FILE > .env > défaut)
get_s3_var() {
    local var_name="$1"
    local default_val="$2"
    local val=""

    if [ -n "$ENV_FILE" ]; then
        val="$(parse_env_var "$ENV_FILE" "$var_name" || true)"
    fi
    if [ -z "$val" ] && [ -f "$PROJECT_DIR/.env" ]; then
        val="$(parse_env_var "$PROJECT_DIR/.env" "$var_name" || true)"
    fi
    if [ -z "$val" ]; then
        val="$default_val"
    fi
    echo "$val"
}

BACKUP_DIR="$PROJECT_DIR/var/s3_export"
BUCKET_NAME="${AWS_BUCKET:-$(get_s3_var AWS_BUCKET roquette-uploads)}"
S3_KEY="${AWS_KEY:-$(get_s3_var AWS_KEY minioadmin)}"
S3_SECRET="${AWS_SECRET:-$(get_s3_var AWS_SECRET minioadminpassword)}"

export AWS_BUCKET="$BUCKET_NAME"
export AWS_KEY="$S3_KEY"
export AWS_SECRET="$S3_SECRET"

echo -e "${C_BLUE}======================================================${C_RESET}"
echo -e "${C_BLUE}   Migration de stockage Roquette : MinIO -> Garage   ${C_RESET}"
echo -e "${C_BLUE}======================================================${C_RESET}\n"

if [ -n "$ENV_FILE" ]; then
    info "Fichier d'environnement utilisé : $ENV_FILE"
fi

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

# 3. Sauvegarde des données MinIO (si pas déjà exporté)
mkdir -p "$BACKUP_DIR"

if [ -d "$BACKUP_DIR" ] && [ "$(ls -A "$BACKUP_DIR" 2>/dev/null)" ]; then
    success "Des fichiers sauvegardés sont déjà présents dans $BACKUP_DIR. Export MinIO ignoré."
else
    IS_TEMP_MINIO=0
    MINIO_CONTAINER="$(docker ps -a --filter "name=minio" --filter "status=running" --format '{{.Names}}' | grep -v 'init' | head -n 1 || true)"
    if [ -z "$MINIO_CONTAINER" ]; then
        # 1. Vérifier si un conteneur MinIO arrêté existe
        STOPPED_MINIO="$(docker ps -a --filter "name=minio" --format '{{.Names}}' | grep -v 'init' | head -n 1 || true)"
        if [ -n "$STOPPED_MINIO" ]; then
            info "Conteneur MinIO arrêté détecté ($STOPPED_MINIO). Tentative de démarrage temporaire pour export..."
            if docker start "$STOPPED_MINIO" >/dev/null 2>&1; then
                MINIO_CONTAINER="$STOPPED_MINIO"
                sleep 2
            fi
        fi
        # 2. Si le conteneur n'a pas pu démarrer ou n'existe pas, chercher le volume Docker minio_data
        if [ -z "$MINIO_CONTAINER" ]; then
            MINIO_VOLUME="$(docker volume ls --format '{{.Name}}' | grep -E 'roquette.*minio_data|minio_data' | head -n 1 || true)"
            if [ -n "$MINIO_VOLUME" ]; then
                info "Volume Docker MinIO détecté ($MINIO_VOLUME). Lancement d'un conteneur temporaire pour extraction..."
                TEMP_MINIO="roquette_temp_minio_export"
                docker rm -f "$TEMP_MINIO" >/dev/null 2>&1 || true
                docker run -d --name "$TEMP_MINIO" \
                    --network "$DOCKER_NETWORK" \
                    --network-alias minio \
                    -v "$MINIO_VOLUME":/data \
                    -e MINIO_ROOT_USER="$S3_KEY" \
                    -e MINIO_ROOT_PASSWORD="$S3_SECRET" \
                    minio/minio:latest server /data >/dev/null 2>&1 || true
                sleep 3
                MINIO_CONTAINER="$TEMP_MINIO"
                IS_TEMP_MINIO=1
            fi
        fi
    fi

    if [ -n "$MINIO_CONTAINER" ]; then
        info "Export des fichiers depuis MinIO ($MINIO_CONTAINER) vers $BACKUP_DIR..."

        CONTAINER_MINIO_USER="$(docker inspect "$MINIO_CONTAINER" --format '{{range .Config.Env}}{{println .}}{{end}}' 2>/dev/null | grep -E '^(MINIO_ROOT_USER|MINIO_ACCESS_KEY)=' | cut -d= -f2- | tail -n 1 || true)"
        CONTAINER_MINIO_SECRET="$(docker inspect "$MINIO_CONTAINER" --format '{{range .Config.Env}}{{println .}}{{end}}' 2>/dev/null | grep -E '^(MINIO_ROOT_PASSWORD|MINIO_SECRET_KEY)=' | cut -d= -f2- | tail -n 1 || true)"

        docker run --rm --entrypoint /bin/sh \
            --network "$DOCKER_NETWORK" \
            -v "$BACKUP_DIR":/export \
            "$MC_IMAGE" -c "
                set -e
                CONNECTED=0
                for pair in \"$S3_KEY:$S3_SECRET\" \"${CONTAINER_MINIO_USER:-}:${CONTAINER_MINIO_SECRET:-}\" \"minioadmin:minioadminpassword\"; do
                    k=\"\${pair%%:*}\"
                    s=\"\${pair#*:}\"
                    [ -z \"\$k\" ] && continue
                    if mc alias set src http://minio:9000 \"\$k\" \"\$s\" >/dev/null 2>&1; then
                        echo \"Authentification MinIO réussie avec la clé : \$k\"
                        CONNECTED=1
                        break
                    fi
                done

                if [ \"\$CONNECTED\" -ne 1 ]; then
                    echo \"Erreur : Impossible de s'authentifier auprès de MinIO (http://minio:9000).\" >&2
                    echo \"Vérifiez vos identifiants S3 ou passez votre fichier d'environnement avec -e / --env-file.\" >&2
                    exit 1
                fi

                if ! mc ls src/\"$BUCKET_NAME\" >/dev/null 2>&1; then
                    echo \"Attention : le bucket '$BUCKET_NAME' n'a pas été trouvé dans MinIO.\" >&2
                    echo \"Buckets existants sur MinIO :\" >&2
                    mc ls src/ >&2 || true
                fi

                mc mirror src/\"$BUCKET_NAME\" /export
            "
        success "Export terminé avec succès !"

        if [ "$IS_TEMP_MINIO" -eq 1 ]; then
            docker rm -f "$MINIO_CONTAINER" >/dev/null 2>&1 || true
        fi
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
FILES_TO_UPDATE=(".env" ".env.local" ".env.test.local")
if [ -n "$ENV_FILE" ]; then
    FILES_TO_UPDATE+=("$ENV_FILE")
fi

PROCESSED_FILES=()
for env_file in "${FILES_TO_UPDATE[@]}"; do
    [ -f "$env_file" ] || continue
    REAL_PATH="$(realpath "$env_file")"
    if [[ " ${PROCESSED_FILES[*]:-} " =~ " ${REAL_PATH} " ]]; then
        continue
    fi
    PROCESSED_FILES+=("$REAL_PATH")

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
done
success "Fichiers d'environnement à jour."

# 6. Démarrage et initialisation de Garage
info "Démarrage du service Garage via Docker Compose..."
docker compose "${COMPOSE_ENV_ARGS[@]}" up -d --build garage

info "Attente de l'état 'healthy' pour Garage..."
RETRIES=30
until [ "$RETRIES" -le 0 ] || [ "$(docker inspect "$(docker compose "${COMPOSE_ENV_ARGS[@]}" ps -q garage)" --format '{{.State.Health.Status}}' 2>/dev/null)" = "healthy" ]; do
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
docker compose "${COMPOSE_ENV_ARGS[@]}" up -d php
success "Service PHP prêt !"

# 9. Affichage du statut du bucket
echo -e "\n${C_GREEN}======================================================${C_RESET}"
echo -e "${C_GREEN}   Migration vers Garage terminée avec succès !      ${C_RESET}"
echo -e "${C_GREEN}======================================================${C_RESET}\n"

info "État actuel du bucket Garage :"
docker compose "${COMPOSE_ENV_ARGS[@]}" exec -T garage /usr/local/bin/garage bucket info "$BUCKET_NAME" || true

echo -e "\n${C_YELLOW}Note importante :${C_RESET}"
echo -e "Les fichiers sauvegardés sont conservés dans : ${C_BLUE}$BACKUP_DIR${C_RESET}"
echo -e "L'ancien volume MinIO (s'il existait) est toujours présent sur la machine."
echo -e "Une fois vos vérifications terminées, vous pourrez supprimer la sauvegarde avec :"
echo -e "  rm -rf $BACKUP_DIR"
echo -e "  docker volume rm roquette_minio_data (si applicable)\n"
