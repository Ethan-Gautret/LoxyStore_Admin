import { useState } from 'react'
import { requestJson, setAuth } from '../lib/auth'

export default function Login({ onAuthenticated }) {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')

  async function handleSubmit(event) {
    event.preventDefault()
    setError('')

    if (!email || !password) {
      setError('Renseignez votre email et votre mot de passe.')
      return
    }

    setSubmitting(true)
    try {
      const data = await requestJson('/api/login', {
        method: 'POST',
        body: JSON.stringify({ email, password }),
      })
      setAuth(data.token, data.user)
      onAuthenticated(data.user)
    } catch (err) {
      const validation = err.payload?.errors ? Object.values(err.payload.errors).flat().join(' ') : ''
      setError(validation || err.message || 'Connexion impossible.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="login-shell">
      <form className="login-card" onSubmit={handleSubmit}>
        <div className="login-brand">
          <div className="brand-mark">⬢</div>
          <div className="brand-title">LoxyStore_Admin_API</div>
        </div>

        <h1 className="login-title">Connexion</h1>
        <p className="login-subtitle">Accès réservé à l'administration du catalogue.</p>

        <label className="login-field">
          <span>Email</span>
          <input
            type="email"
            autoComplete="username"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            placeholder="vous@loxystore.fr"
            autoFocus
          />
        </label>

        <label className="login-field secret-field">
          <span>Mot de passe</span>
          <input
            type={showPassword ? 'text' : 'password'}
            autoComplete="current-password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            placeholder="••••••••"
          />
          <button
            className="eye-icon"
            type="button"
            aria-label={showPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe'}
            onClick={() => setShowPassword((v) => !v)}
          />
        </label>

        {error && <p className="login-error">{error}</p>}

        <button className="login-button" type="submit" disabled={submitting}>
          {submitting ? 'Connexion…' : 'Se connecter'}
        </button>
      </form>
    </div>
  )
}
