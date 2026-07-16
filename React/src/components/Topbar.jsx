import { useEffect, useState } from 'react'
import { requestJson } from '../lib/auth'

const routeMeta = {
  '/': { title: 'Dashboard' },
  '/categories': { title: 'Catégories & Mapping' },
  '/brands': { title: 'Marques & Fabricants' },
  '/products': { title: 'Produits importés' },
  '/margin-rules': { title: 'Règles de marge' },
  '/import-filters': { title: "Filtres d'import" },
  '/sync-history': { title: 'Historique des syncs' },
  '/cron': { title: 'Configuration cron' },
  '/settings': { title: 'Configuration API' },
  '/users': { title: 'Utilisateurs' },
}

// "il y a X min/h/j" à partir d'un ISO8601 ; null si trop ancien (>30j) → on
// retombe sur la date absolue.
function relativeFromNow(iso) {
  const then = new Date(iso).getTime()
  if (Number.isNaN(then)) return null
  const min = Math.floor((Date.now() - then) / 60000)
  if (min < 0) return "à l'instant"
  if (min < 1) return "à l'instant"
  if (min < 60) return `il y a ${min} min`
  const h = Math.floor(min / 60)
  if (h < 24) return `il y a ${h} h`
  const d = Math.floor(h / 24)
  if (d < 30) return `il y a ${d} j`
  return null
}

// tone renvoyé par l'API (success/warning/danger/info) -> classe CSS de la pill.
function toneClass(tone) {
  if (tone === 'warning') return 'warning'
  if (tone === 'danger') return 'danger'
  if (tone === 'success') return ''
  return 'neutral'
}

export default function Topbar({ route }) {
  const title = routeMeta[route]?.title ?? 'Page'
  const [lastSync, setLastSync] = useState(undefined) // undefined=chargement, null=aucune

  useEffect(() => {
    let active = true
    const load = () => {
      // refresh=1 : contourne le cache API 5 min pour un affichage à jour.
      requestJson('/api/sync-logs?limit=1&refresh=1')
        .then((data) => { if (active) setLastSync(data?.data?.[0] ?? null) })
        .catch(() => { /* silencieux : on garde la dernière valeur connue */ })
    }
    load()
    const id = setInterval(load, 60000) // rafraîchit chaque minute
    return () => { active = false; clearInterval(id) }
  }, [])

  let pillText
  let pillTitle = ''
  let pillTone = 'neutral'

  if (lastSync === undefined) {
    pillText = 'Dernière sync : …'
  } else if (lastSync === null) {
    pillText = 'Aucune synchronisation'
  } else {
    const rel = relativeFromNow(lastSync.started_at)
    pillText = `Dernière sync : ${rel ?? lastSync.date ?? '—'}`
    pillTone = toneClass(lastSync.tone)
    pillTitle = [lastSync.date, lastSync.trigger_label, lastSync.status]
      .filter(Boolean)
      .join(' · ')
  }

  return (
    <header className="topbar">
      <div>
        <h1>{title}</h1>
      </div>

      <div className="topbar-actions">
        <span className={`sync-pill ${pillTone}`} title={pillTitle}>
          <span className="sync-dot" aria-hidden="true" />
          {pillText}
        </span>
        <button className="icon-button" type="button" aria-label="Notifications">
          <span className="bell-icon" aria-hidden="true" />
          <span className="notification-dot" aria-hidden="true" />
        </button>
      </div>
    </header>
  )
}
