import { useEffect, useMemo, useState } from 'react'
import './App.css'
import Sidebar from './components/Sidebar'
import Topbar from './components/Topbar'
import Dashboard from './pages/Dashboard'
import Categories from './pages/Categories'
import Brands from './pages/Brands'
import Products from './pages/Products'
import MarginRules from './pages/MarginRules'
import Placeholder from './pages/Placeholder'
import ImportFilters from './pages/ImportFilters'
import ApiConfiguration from './pages/ApiConfiguration'
import SyncHistory from './pages/SyncHistory'
import CronConfiguration from './pages/CronConfiguration'
import Users from './pages/Users'
import Login from './pages/Login'
import { clearAuth, getToken, getUser, requestJson, setAuth } from './lib/auth'

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

// Vite injects the configured `base` here: '/synchro/' in prod, '/' in dev.
const BASE_PATH = import.meta.env.BASE_URL.replace(/\/+$/, '')

function getRouteFromPath() {
  let path = window.location.pathname || '/'
  if (BASE_PATH && (path === BASE_PATH || path.startsWith(BASE_PATH + '/'))) {
    path = path.slice(BASE_PATH.length) || '/'
  }
  return path || '/'
}

function useHistoryRoute() {
  const [route, setRoute] = useState(getRouteFromPath)

  useEffect(() => {
    const handlePopState = () => setRoute(getRouteFromPath())
    window.addEventListener('popstate', handlePopState)
    return () => window.removeEventListener('popstate', handlePopState)
  }, [])

  useEffect(() => {
    document.title = `LoxYStore Admin · ${routeMeta[route]?.title ?? 'Page'}`
  }, [route])

  return [route, (nextRoute) => {
    if (nextRoute !== route) {
      const url = BASE_PATH + (nextRoute === '/' ? '/' : nextRoute)
      window.history.pushState(null, '', url)
      setRoute(nextRoute)
    }
  }]
}

// États d'authentification : on vérifie le token au démarrage avant d'afficher
// quoi que ce soit du back-office.
//   'checking'      → token présent, validation via /me en cours
//   'authenticated' → connecté
//   'guest'         → pas de token (ou 401) → écran de connexion
export default function App() {
  const [route, navigate] = useHistoryRoute()
  const [authState, setAuthState] = useState(() => (getToken() ? 'checking' : 'guest'))
  const [user, setUser] = useState(getUser)

  // Au montage : si un token existe, on le valide côté serveur.
  useEffect(() => {
    if (!getToken()) return
    let active = true
    requestJson('/api/me?refresh=1')
      .then((data) => {
        if (!active) return
        setUser(data.user)
        setAuth(null, data.user)
        setAuthState('authenticated')
      })
      .catch(() => {
        if (!active) return
        clearAuth()
        setAuthState('guest')
      })
    return () => { active = false }
  }, [])

  // L'intercepteur fetch émet cet évènement sur toute réponse 401.
  useEffect(() => {
    const onUnauthorized = () => {
      setUser(null)
      setAuthState('guest')
    }
    window.addEventListener('loxy:unauthorized', onUnauthorized)
    return () => window.removeEventListener('loxy:unauthorized', onUnauthorized)
  }, [])

  function handleAuthenticated(loggedUser) {
    setUser(loggedUser)
    setAuthState('authenticated')
    navigate('/')
  }

  async function handleLogout() {
    try {
      await requestJson('/api/logout', { method: 'POST' })
    } catch {
      // Peu importe : on purge le token localement de toute façon.
    }
    clearAuth()
    setUser(null)
    setAuthState('guest')
  }

  const activePage = useMemo(() => {
    if (route === '/categories') return 'categories'
    if (route === '/brands') return 'brands'
    if (route === '/margin-rules') return 'margin-rules'
    if (route === '/products') return 'products'
    if (route === '/import-filters') return 'import-filters'
    if (route === '/sync-history') return 'sync-history'
    if (route === '/cron') return 'cron'
    if (route === '/settings') return 'settings'
    if (route === '/users') return 'users'
    if (route === '/') return 'dashboard'
    return 'placeholder'
  }, [route])

  if (authState === 'checking') {
    return <div className="app-loading">Chargement…</div>
  }

  if (authState === 'guest') {
    return <Login onAuthenticated={handleAuthenticated} />
  }

  return (
    <div className="dashboard-shell">
      <Sidebar route={route} navigate={navigate} user={user} onLogout={handleLogout} />
      <div className="dashboard-main">
        <Topbar route={route} />
        {activePage === 'dashboard' && <Dashboard />}
        {activePage === 'categories' && <Categories />}
        {activePage === 'brands' && <Brands />}
        {activePage === 'products' && <Products />}
        {activePage === 'margin-rules' && <MarginRules />}
        {activePage === 'import-filters' && <ImportFilters />}
        {activePage === 'sync-history' && <SyncHistory />}
        {activePage === 'cron' && <CronConfiguration />}
        {activePage === 'settings' && <ApiConfiguration />}
        {activePage === 'users' && <Users currentUser={user} />}
        {activePage === 'placeholder' && <Placeholder route={route} />}
      </div>
    </div>
  )
}
