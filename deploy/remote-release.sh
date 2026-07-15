#!/usr/bin/env bash
#
# Release côté serveur, exécutée APRÈS le rsync du code backend.
# Lancée par .github/workflows/deploy.yml :
#     cd <DEPLOY_PATH>/backend && bash deploy/remote-release.sh
#
# Peut aussi être relancée manuellement en SSH (mêmes effets, idempotent) :
#     ssh loxys6360@web02ls.loxys.fr
#     cd /home/loxystore.fr/backend && bash deploy/remote-release.sh
#
# Hypothèses :
#   - on est dans le dossier backend/ (racine Laravel) ;
#   - .env de prod déjà présent (jamais écrasé par le rsync) ;
#   - vendor/ déjà déposé par le CI (composer --no-dev) ;
#   - PHP 8.3 CLI disponible dans le PATH.
#
# Aucun sudo requis.

set -euo pipefail

echo "==> Release LoxyStore backend dans : $(pwd)"

# Arborescence storage requise (idempotent) : /storage est exclu du rsync, donc
# sur un serveur neuf ces dossiers peuvent manquer -> view:cache / sessions KO.
mkdir -p \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/views \
  storage/framework/testing \
  storage/logs \
  storage/app/public \
  bootstrap/cache

# Mode maintenance pendant les migrations (évite les requêtes en plein schéma changeant).
php artisan down --retry=15 || true
trap 'php artisan up || true' EXIT  # on relève la maintenance quoi qu'il arrive

echo "==> Migrations (forcées, prod - joue uniquement les migrations en attente)"
php artisan migrate --force --no-interaction

echo "==> Reconstruction des caches (config / view / route)"
php artisan config:clear
php artisan config:cache
php artisan view:cache
# route:cache échoue si des routes utilisent des closures -> on retombe sur route:clear.
php artisan route:cache || php artisan route:clear
php artisan event:cache || true

echo "==> Lien symbolique storage (idempotent)"
php artisan storage:link || true

echo "==> Redémarrage gracieux des workers de queue (si Supervisor/queue configuré)"
php artisan queue:restart || true

# La maintenance est relevée par le trap EXIT.
echo "==> Release terminée."
