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
}

export default function Topbar({ route }) {
  const title = routeMeta[route]?.title ?? 'Page'

  return (
    <header className="topbar">
      <div>
        <h1>{title}</h1>
      </div>

      <div className="topbar-actions">
        <span className="sync-pill">
          <span className="sync-dot" aria-hidden="true" />
          Dernière sync: il y a 43 min
        </span>
        <button className="icon-button" type="button" aria-label="Notifications">
          <span className="bell-icon" aria-hidden="true" />
          <span className="notification-dot" aria-hidden="true" />
        </button>
      </div>
    </header>
  )
}
