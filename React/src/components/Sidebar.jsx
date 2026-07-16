// sidebar component
const iconMap = {
  grid: '▦',
  hierarchy: '◇',
  store: '⬜',
  cube: '⊞',
  dollar: '⊕',
  filter: '⋮',
  clock: '◯',
  settings: '⚙',
  users: '☺',
}

const navigationGroups = [
  {
    title: 'Overview',
    items: [{ label: 'Dashboard', icon: 'grid', route: '/' }],
  },
  {
    title: 'Catalogue',
    items: [
      { label: 'Catégories & Mapping', icon: 'hierarchy', route: '/categories' },
      { label: 'Marques & Fabricants', icon: 'store', route: '/brands' },
      { label: 'Produits importés', icon: 'cube', route: '/products' },
    ],
  },
  {
    title: 'Règles métier',
    items: [
      { label: 'Règles de marge', icon: 'dollar', route: '/margin-rules' },
      { label: "Filtres d'import", icon: 'filter', route: '/import-filters' },
    ],
  },
  {
    title: 'Synchronisation',
    items: [
      { label: 'Configuration cron', icon: 'clock', route: '/cron' },
      { label: 'Historique des syncs', icon: 'clock', route: '/sync-history' },
    ],
  },
  {
    title: 'Paramètres',
    items: [
      { label: 'Configuration API', icon: 'settings', route: '/settings' },
      { label: 'Utilisateurs', icon: 'users', route: '/users' },
    ],
  },
]

function initials(name) {
  if (!name) return 'AD'
  return name.trim().split(/\s+/).slice(0, 2).map((w) => w[0]?.toUpperCase() ?? '').join('') || 'AD'
}

export default function Sidebar({ route, navigate, user, onLogout }) {
  return (
    <aside className="sidebar">
      <div className="brand">
        <div className="brand-mark">⬢</div>
        <div>
          <div className="brand-title">LoxyStore Admin API</div>
        </div>
      </div>

      <nav className="sidebar-nav" aria-label="Navigation principale">
        {navigationGroups.map((group) => (
          <section className="nav-group" key={group.title}>
            <h2>{group.title}</h2>
            <ul>
              {group.items.map((item) => {
                const isActive = route === item.route

                return (
                  <li key={item.label}>
                    <button
                      className={isActive ? 'nav-item active' : 'nav-item'}
                      type="button"
                      onClick={() => navigate(item.route)}
                    >
                      <span className="nav-icon">{iconMap[item.icon]}</span>
                      <span>{item.label}</span>
                    </button>
                  </li>
                )
              })}
            </ul>
          </section>
        ))}
      </nav>

      <div className="sidebar-profile">
        <div className="avatar">{initials(user?.name)}</div>
        <div className="profile-info">
          <div className="profile-name">{user?.name ?? 'Utilisateur'}</div>
          <div className="profile-email">{user?.email ?? ''}</div>
        </div>
        <button
          className="logout-button"
          type="button"
          onClick={onLogout}
          aria-label="Se déconnecter"
          title="Se déconnecter"
        >
          ⏻
        </button>
      </div>
    </aside>
  )
}
