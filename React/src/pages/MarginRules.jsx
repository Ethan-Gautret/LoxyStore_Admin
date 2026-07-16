import { useEffect, useState } from 'react'

const API_BASE_URL = (import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000').replace(/\/$/, '')

async function requestJson(path, options = {}) {
  const response = await fetch(`${API_BASE_URL}${path}`, {
    ...options,
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...(options.headers || {}) },
  })
  const payload = await response.json().catch(() => ({}))
  if (!response.ok) throw new Error(payload.message || 'Une erreur est survenue.')
  return payload
}

export default function MarginRules() {
  const [globalMargin, setGlobalMargin] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState('')

  // Règles spécifiques = catégories avec une marge personnalisée.
  const [specificRules, setSpecificRules] = useState([])
  const [loadingSpecific, setLoadingSpecific] = useState(true)

  useEffect(() => {
    let active = true
    requestJson('/api/margin-rules/global?refresh=1')
      .then((data) => { if (active) setGlobalMargin(String(data?.global?.margin_value ?? '')) })
      .catch(() => { if (active) setMessage('Impossible de charger la marge globale.') })
      .finally(() => { if (active) setLoading(false) })

    requestJson('/api/margin-rules/specific?refresh=1')
      .then((data) => { if (active) setSpecificRules(Array.isArray(data?.rules) ? data.rules : []) })
      .catch(() => { /* silencieux : la section affichera l'état vide */ })
      .finally(() => { if (active) setLoadingSpecific(false) })

    return () => { active = false }
  }, [])

  async function handleSave() {
    // Un champ vide ne doit pas écraser silencieusement la marge à 0.
    if (globalMargin === '' || isNaN(Number(globalMargin)) || Number(globalMargin) < 0) {
      setMessage('Entrez une marge globale valide (ex. 10).')
      return
    }
    setSaving(true)
    setMessage('')
    try {
      const data = await requestJson('/api/margin-rules/global', {
        method: 'PUT',
        body: JSON.stringify({ margin_value: Number(globalMargin) }),
      })
      setGlobalMargin(String(data?.global?.margin_value ?? globalMargin))
      setMessage('Marge globale enregistrée.')
    } catch (err) {
      setMessage(err.message || 'Enregistrement impossible.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <main className="content-grid margin-rules-page">
      <section className="panel info-box">
        <div className="info-left">i</div>
        <div>
          <strong>Priorité d'application des règles</strong>
          <div className="info-sub">Marge de la catégorie (si définie) → sinon marge globale ci-dessous</div>
        </div>
      </section>

      <section className="panel global-rule">
        <div className="panel-header">
          <div>
            <h2>Règle globale</h2>
            <p>Marge par défaut appliquée aux produits sans marge propre au niveau catégorie. Le prix vendu est HT (sans TVA).</p>
          </div>
        </div>

        <div className="global-fields">
          <label>
            <div className="field-label">Marge globale par défaut (%)</div>
            <input
              type="number"
              min="0"
              step="0.1"
              value={globalMargin}
              onChange={(e) => setGlobalMargin(e.target.value)}
              disabled={loading || saving}
              placeholder={loading ? 'Chargement…' : '10'}
            />
          </label>

          <div className="global-actions">
            <button className="save-button" type="button" onClick={handleSave} disabled={loading || saving}>
              {saving ? 'Enregistrement…' : 'Enregistrer la règle globale'}
            </button>
          </div>
        </div>

        {message && (
          <p className={`api-status ${message.includes('enregistrée') ? 'success' : 'error'}`}>{message}</p>
        )}

        <p className="sync-hint" style={{ marginTop: 10 }}>
          Prix vendu HT = prix d'achat × (1 + marge %). Exemple : 30,56 € à 10 % → 33,62 €.
        </p>
      </section>

      <section className="panel specific-rules">
        <div className="panel-header">
          <div>
            <h2>Règles spécifiques</h2>
            <p>Catégories ayant une marge personnalisée (prioritaire sur la marge globale). Modifiable depuis la page Catégories.</p>
          </div>
          <div className="panel-actions">
            <span className="pill muted">{specificRules.length} catégorie{specificRules.length > 1 ? 's' : ''}</span>
          </div>
        </div>

        <div className="table-wrap rules-table-wrap">
          <table className="rules-table">
            <thead>
              <tr>
                <th>Portée</th>
                <th>Catégorie</th>
                <th>Marge (%)</th>
                <th>Produits</th>
                <th>Statut</th>
              </tr>
            </thead>
            <tbody>
              {loadingSpecific ? (
                <tr>
                  <td colSpan="5" style={{ padding: '16px 0', color: '#65708a' }}>Chargement…</td>
                </tr>
              ) : specificRules.length === 0 ? (
                <tr>
                  <td colSpan="5" style={{ padding: '16px 0', color: '#65708a' }}>
                    Aucune catégorie avec marge personnalisée. Définissez une marge par catégorie
                    depuis la page Catégories ; elle apparaîtra ici.
                  </td>
                </tr>
              ) : specificRules.map((r) => (
                <tr key={r.id}>
                  <td><span className="pill muted">Catégorie</span></td>
                  <td>{r.name}</td>
                  <td><strong>{r.margin_value}%</strong></td>
                  <td>{r.product_count}</td>
                  <td>
                    <span className={`pill ${r.active ? 'success' : 'muted'}`}>
                      {r.active ? 'Actif' : 'Inactif'}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
    </main>
  )
}
