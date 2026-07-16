// Authentification côté front (SPA).
//
// Le token Sanctum est stocké en localStorage et injecté automatiquement dans
// toutes les requêtes vers l'API via un intercepteur `fetch` global (voir
// installFetchInterceptor). Ainsi les pages existantes n'ont rien à modifier :
// leurs appels `fetch(...)` reçoivent le header Authorization tout seuls.

export const API_BASE_URL = (import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000').replace(/\/$/, '')

const TOKEN_KEY = 'loxy_token'
const USER_KEY = 'loxy_user'

export function getToken() {
  return localStorage.getItem(TOKEN_KEY) || null
}

export function getUser() {
  try {
    const raw = localStorage.getItem(USER_KEY)
    return raw ? JSON.parse(raw) : null
  } catch {
    return null
  }
}

export function setAuth(token, user) {
  if (token) localStorage.setItem(TOKEN_KEY, token)
  if (user) localStorage.setItem(USER_KEY, JSON.stringify(user))
}

export function clearAuth() {
  localStorage.removeItem(TOKEN_KEY)
  localStorage.removeItem(USER_KEY)
}

// True si l'URL vise notre API (et non une ressource externe).
function isApiRequest(url) {
  if (typeof url !== 'string') return false
  return url.startsWith(API_BASE_URL) || url.startsWith('/api')
}

// Petit helper JSON pour les nouvelles pages (Login, Users). Le token est ajouté
// par l'intercepteur, mais on garde ce helper pour un code de page plus lisible.
export async function requestJson(path, options = {}) {
  const response = await fetch(`${API_BASE_URL}${path}`, {
    ...options,
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      ...(options.headers || {}),
    },
  })
  const payload = await response.json().catch(() => ({}))
  if (!response.ok) {
    const error = new Error(payload.message || 'Une erreur est survenue.')
    error.status = response.status
    error.payload = payload
    throw error
  }
  return payload
}

let installed = false

// Enrobe window.fetch une seule fois :
//  - ajoute Authorization: Bearer <token> sur les requêtes API,
//  - sur une réponse 401, purge le token et prévient l'app (retour au login).
export function installFetchInterceptor() {
  if (installed) return
  installed = true

  const originalFetch = window.fetch.bind(window)

  window.fetch = async (input, init = {}) => {
    const url = typeof input === 'string' ? input : input?.url
    const token = getToken()

    let nextInit = init
    if (token && isApiRequest(url)) {
      const headers = new Headers(init.headers || (typeof input !== 'string' ? input.headers : undefined) || {})
      if (!headers.has('Authorization')) {
        headers.set('Authorization', `Bearer ${token}`)
      }
      nextInit = { ...init, headers }
    }

    const response = await originalFetch(input, nextInit)

    // 401 sur une requête API (hors /login) = token invalide/expiré.
    if (response.status === 401 && isApiRequest(url) && !String(url).includes('/api/login')) {
      clearAuth()
      window.dispatchEvent(new CustomEvent('loxy:unauthorized'))
    }

    return response
  }
}
