import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App.jsx'
import { installFetchInterceptor } from './lib/auth'

// Injecte le token Sanctum sur toutes les requêtes API et gère les 401.
installFetchInterceptor()

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
