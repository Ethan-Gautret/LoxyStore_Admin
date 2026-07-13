import { useEffect, useState } from 'react'

const API_BASE_URL = (import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000').replace(/\/$/, '')

const emptySettings = {
  tdsynnex: {
    endpoint_url: 'https://api.tdsynnex.com/eu/auth/token',
    region: 'eu',
    client_id: '',
    client_secret: '',
    rate_limit: 10,
  },
  prestashop: {
    backoffice_url: '',
    table_prefix: 'ps_',
    webservice_key: '',
    write_mode: 'webservice',
  },
}

function buildHeaders() {
  return {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  }
}

async function requestJson(path, options = {}) {
  const response = await fetch(`${API_BASE_URL}${path}`, {
    ...options,
    headers: {
      ...buildHeaders(),
      ...(options.headers || {}),
    },
  })

  const payload = await response.json().catch(() => ({}))

  if (!response.ok) {
    const error = new Error(payload.message || 'Une erreur est survenue.')
    error.payload = payload
    throw error
  }

  return payload
}

function FieldMessage({ status, message }) {
  if (!message) return null

  return <p className={`api-status ${status}`}>{message}</p>
}

function formatAttemptDetails(attempts) {
  if (!Array.isArray(attempts) || attempts.length === 0) {
    return ''
  }

  return attempts.map((attempt) => `${attempt.url} → ${attempt.status}`).join(' | ')
}

export default function ApiConfiguration() {
  const [settings, setSettings] = useState(emptySettings)
  const [message, setMessage] = useState({ tdsynnex: '', prestashop: '' })
  const [status, setStatus] = useState({ tdsynnex: 'idle', prestashop: 'idle' })
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState({ tdsynnex: false, prestashop: false })
  const [visibleSecrets, setVisibleSecrets] = useState({ tdsynnex: false, prestashop: false })

  useEffect(() => {
    let active = true

    async function loadSettings() {
      try {
        const data = await requestJson('/api/integration-settings')

        if (!active) return

        setSettings({
          tdsynnex: { ...emptySettings.tdsynnex, ...data.tdsynnex },
          prestashop: { ...emptySettings.prestashop, ...data.prestashop },
        })
        setMessage({ tdsynnex: 'Réglages chargés.', prestashop: 'Réglages chargés.' })
        setStatus({ tdsynnex: 'success', prestashop: 'success' })
      } catch (error) {
        if (!active) return

        setMessage({ tdsynnex: error.message, prestashop: error.message })
        setStatus({ tdsynnex: 'error', prestashop: 'error' })
      } finally {
        if (active) setLoading(false)
      }
    }

    loadSettings()

    return () => {
      active = false
    }
  }, [])

  function updateSection(section, field, value) {
    setSettings((current) => ({
      ...current,
      [section]: {
        ...current[section],
        [field]: field === 'rate_limit' ? Number(value) : value,
      },
    }))
    setMessage((current) => ({ ...current, [section]: '' }))
    setStatus((current) => ({ ...current, [section]: 'idle' }))
  }

  function toggleSecretVisibility(section) {
    setVisibleSecrets((current) => ({
      ...current,
      [section]: !current[section],
    }))
  }

  async function handleSave(section) {
    setSaving((current) => ({ ...current, [section]: true }))
    setMessage((current) => ({ ...current, [section]: '' }))

    try {
      const response = await requestJson(`/api/integration-settings/${section}`, {
        method: 'PUT',
        body: JSON.stringify(settings[section]),
      })

      setSettings((current) => ({
        ...current,
        [section]: {
          ...current[section],
          ...response.payload,
        },
      }))
      setMessage((current) => ({ ...current, [section]: response.message }))
      setStatus((current) => ({ ...current, [section]: 'success' }))
    } catch (error) {
      const attemptDetails = formatAttemptDetails(error.payload?.attempts)
      setMessage((current) => ({
        ...current,
        [section]: [
          error.payload?.errors ? Object.values(error.payload.errors).flat().join(' ') : error.message,
          attemptDetails,
        ]
          .filter(Boolean)
          .join(' '),
      }))
      setStatus((current) => ({ ...current, [section]: 'error' }))
    } finally {
      setSaving((current) => ({ ...current, [section]: false }))
    }
  }

  async function handleTest(section) {
    setMessage((current) => ({ ...current, [section]: '' }))

    try {
      const response = await requestJson(`/api/integration-settings/${section}/test`, {
        method: 'POST',
        body: JSON.stringify(settings[section]),
      })

      await requestJson(`/api/integration-settings/${section}`, {
        method: 'PUT',
        body: JSON.stringify(settings[section]),
      })

      setMessage((current) => ({ ...current, [section]: response.message }))
      setStatus((current) => ({ ...current, [section]: 'success' }))
    } catch (error) {
      const attemptDetails = formatAttemptDetails(error.payload?.attempts)
      setMessage((current) => ({
        ...current,
        [section]: [
          error.payload?.errors ? Object.values(error.payload.errors).flat().join(' ') : error.message,
          attemptDetails,
        ]
          .filter(Boolean)
          .join(' '),
      }))
      setStatus((current) => ({ ...current, [section]: 'error' }))
    }
  }

  return (
    <main className="content-grid api-config-page">
      <section className="panel config-card">
        <div className="panel-header">
          <div>
            <h2>Configuration API TD SYNNEX</h2>
            <p>Paramètres de connexion au distributeur</p>
          </div>
        </div>

        <div className="config-grid">
          <label className="config-field config-field-wide">
            <span>Endpoint URL</span>
            <input
              type="text"
              value={settings.tdsynnex.endpoint_url}
              onChange={(event) => updateSection('tdsynnex', 'endpoint_url', event.target.value)}
            />
          </label>

          <label className="config-field">
            <span>Région</span>
            <select
              value={settings.tdsynnex.region}
              onChange={(event) => updateSection('tdsynnex', 'region', event.target.value)}
            >
              <option value="eu">Europe (EU)</option>
              <option value="na">North America (NA)</option>
            </select>
          </label>

          <label className="config-field config-field-wide">
            <span>Client ID</span>
            <input
              type="text"
              value={settings.tdsynnex.client_id}
              onChange={(event) => updateSection('tdsynnex', 'client_id', event.target.value)}
            />
          </label>

          <label className="config-field secret-field">
            <span>Client Secret</span>
            <input
              type={visibleSecrets.tdsynnex ? 'text' : 'password'}
              value={settings.tdsynnex.client_secret}
              onChange={(event) => updateSection('tdsynnex', 'client_secret', event.target.value)}
            />
            <button
              className="eye-icon"
              type="button"
              aria-label={visibleSecrets.tdsynnex ? 'Masquer la clé API' : 'Afficher la clé API'}
              onClick={() => toggleSecretVisibility('tdsynnex')}
            />
          </label>

          <label className="config-field config-field-small">
            <span>Rate Limit (requêtes/seconde)</span>
            <input
              type="number"
              min="1"
              value={settings.tdsynnex.rate_limit}
              onChange={(event) => updateSection('tdsynnex', 'rate_limit', event.target.value)}
            />
            <small>Maximum de requêtes API par seconde</small>
          </label>
        </div>

        <div className="config-divider" />

        <div className="config-action-row">
          <button className="config-main-button" type="button" onClick={() => handleTest('tdsynnex')} disabled={loading || saving.tdsynnex}>
            Tester la connexion
          </button>
          <span className="config-last-sync">
            {status.tdsynnex === 'success' ? 'Configuration chargée et valide.' : 'Dernière action sur la configuration API.'}
          </span>
        </div>

        <FieldMessage status={status.tdsynnex} message={message.tdsynnex} />

        <div className="config-divider" />

        <div className="config-save-row">
          <button className="config-main-button" type="button" onClick={() => handleSave('tdsynnex')} disabled={loading || saving.tdsynnex}>
            {saving.tdsynnex ? 'Enregistrement...' : 'Enregistrer la configuration TD SYNNEX'}
          </button>
        </div>
      </section>

      <section className="panel config-card">
        <div className="panel-header">
          <div>
            <h2>Configuration PrestaShop</h2>
            <p>Paramètres de connexion à votre boutique</p>
          </div>
        </div>

        <div className="config-grid">
          <label className="config-field config-field-wide">
            <span>URL de la boutique ou du back-office</span>
            <input
              type="text"
              value={settings.prestashop.backoffice_url}
              onChange={(event) => updateSection('prestashop', 'backoffice_url', event.target.value)}
            />
            <small>Si vous collez l'URL admin, le projet extrait automatiquement l'URL racine de la boutique.</small>
          </label>

          <label className="config-field">
            <span>Préfixe des tables</span>
            <input
              type="text"
              value={settings.prestashop.table_prefix}
              onChange={(event) => updateSection('prestashop', 'table_prefix', event.target.value)}
            />
          </label>

          <label className="config-field config-field-full secret-field">
            <span>Clé API Webservice</span>
            <input
              type={visibleSecrets.prestashop ? 'text' : 'password'}
              value={settings.prestashop.webservice_key}
              onChange={(event) => updateSection('prestashop', 'webservice_key', event.target.value)}
            />
            <button
              className="eye-icon"
              type="button"
              aria-label={visibleSecrets.prestashop ? 'Masquer la clé API' : 'Afficher la clé API'}
              onClick={() => toggleSecretVisibility('prestashop')}
            />
          </label>
        </div>

        <div className="config-mode-block">
          <h3>Mode d'écriture</h3>

          <label className={settings.prestashop.write_mode === 'webservice' ? 'mode-option mode-option-active' : 'mode-option'}>
            <input
              type="radio"
              name="write-mode"
              checked={settings.prestashop.write_mode === 'webservice'}
              onChange={() => updateSection('prestashop', 'write_mode', 'webservice')}
            />
            <div>
              <strong>Webservice API (Recommandé)</strong>
              <p>Utilise l'API REST de PrestaShop</p>
            </div>
          </label>

          <label className={settings.prestashop.write_mode === 'sql' ? 'mode-option mode-option-active' : 'mode-option'}>
            <input
              type="radio"
              name="write-mode"
              checked={settings.prestashop.write_mode === 'sql'}
              onChange={() => updateSection('prestashop', 'write_mode', 'sql')}
            />
            <div>
              <strong>SQL Direct</strong>
              <p>Accès direct à la base de données (plus rapide mais risqué)</p>
            </div>
            <span className="advanced-pill">Avancé</span>
          </label>

          <div className="warning-banner">
            <span className="warning-dot" aria-hidden="true">!</span>
            <span>Mode SQL direct: assurez-vous de sauvegarder la base de données avant chaque synchronisation.</span>
          </div>
        </div>

        <div className="config-divider" />

        <div className="config-action-row">
          <button className="config-main-button" type="button" onClick={() => handleTest('prestashop')} disabled={loading || saving.prestashop}>
            Tester la connexion
          </button>
          <span className="config-last-sync">
            {status.prestashop === 'success' ? 'Configuration chargée et valide.' : 'Dernière action sur la configuration PrestaShop.'}
          </span>
        </div>

        <FieldMessage status={status.prestashop} message={message.prestashop} />

        <div className="config-divider" />

        <div className="config-save-row">
          <button className="config-main-button" type="button" onClick={() => handleSave('prestashop')} disabled={loading || saving.prestashop}>
            {saving.prestashop ? 'Enregistrement...' : 'Enregistrer la configuration PrestaShop'}
          </button>
        </div>
      </section>
    </main>
  )
}
