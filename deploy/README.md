# Pipeline CI/CD LoxyStore

Déploiement automatique via **GitHub Actions** (SSH + rsync) sur `web02ls.loxys.fr`.

## Architecture de production

| Composant | URL publique | Docroot serveur |
|---|---|---|
| SPA React (front) | `https://loxystore.fr/synchro/` | `/home/loxystore.fr/public_html/synchro` |
| API Laravel (back) | `https://backend.loxystore.fr` | `/home/loxystore.fr/backend/public` |

> ⚠️ `https://loxystore.fr` (racine) est un **PrestaShop** : le déploiement front ne touche
> QUE le sous-dossier `/synchro`. Le vhost `backend.loxystore.fr` doit pointer sur
> `.../backend/**public**` (piège classique : pointer sur la racine du projet → 404).

## Comment ça marche

```
push sur main ─► GitHub Actions (deploy.yml)
                  ├ composer install --no-dev
                  ├ build SPA React (Vite, base /synchro/, VITE_API_URL=https://backend.loxystore.fr)
                  ├ rsync backend  --delete -> /home/loxystore.fr/backend   (sauf .env, storage, caches)
                  ├ rsync dist     --delete -> /home/loxystore.fr/public_html/synchro
                  ├ release distante (remote-release.sh : migrate + config:cache + queue:restart)
                  └ smoke tests /api/health + /synchro/
```

Un **`workflow_dispatch`** manuel propose une **Simulation** (`--dry-run`, aucune écriture) :
onglet *Actions* → *Deploy (production)* → *Run workflow* (Simulation cochée par défaut).
Vérifie surtout les lignes `*deleting*` avant un vrai déploiement.

## Secrets & variables GitHub

`Settings ▸ Secrets and variables ▸ Actions`

### Secrets (chiffrés)
| Nom | Valeur |
|---|---|
| `DEPLOY_SSH_KEY` | Clé **privée** de déploiement (OpenSSH, avec les lignes `BEGIN/END`). |
| `DEPLOY_HOST` | `web02ls.loxys.fr` |
| `DEPLOY_USER` | `loxys6360` |
| `DEPLOY_PATH` | `/home/loxystore.fr` |
| `DEPLOY_KNOWN_HOSTS` | Empreinte SSH du serveur (`ssh-keyscan web02ls.loxys.fr`). |

### Variable (optionnelle)
| Nom | Valeur |
|---|---|
| `DEPLOY_PORT` | Port SSH, défaut `22`. |

La clé publique correspondante est installée dans `/home/loxystore.fr/.ssh/authorized_keys`
(compte `loxys6360`). Pour révoquer : retirer la ligne `github-deploy-loxystore` de ce fichier.

## Relancer la release à la main (SSH)

```bash
ssh loxys6360@web02ls.loxys.fr
cd /home/loxystore.fr/backend && bash deploy/remote-release.sh
```

## Points d'attention

- **`--delete`** : le rsync supprime sur le serveur ce qui n'existe plus dans le repo,
  SAUF les chemins de [`rsync-exclude.txt`](rsync-exclude.txt) (`.env`, `storage/`, `bootstrap/cache/`).
- **`migrate --force`** joue les migrations *en attente* (jamais `migrate:fresh`).
  Sauvegarder la base avant un déploiement à migration sensible.
- **Maintenance** : `php artisan down` est activé pendant la release puis relevé auto (trap).
- **vendor/** est buildé sur le runner (`composer --no-dev`) puis envoyé : le serveur n'a pas besoin de Composer.
- **Sécurité recommandée** : dans `Settings ▸ Environments ▸ production`, activer *Required reviewers*
  et limiter la *Deployment branch* à `main`.
