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

// Règles spécifiques : à venir (MVP = règle globale uniquement)
const marginRules = []

export default function MarginRules() {
  const [globalMargin, setGlobalMargin] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState('')

  useEffect(() => {
    let active = true
    requestJson('/api/margin-rules/global?refresh=1')
      .then((data) => { if (active) setGlobalMargin(String(data?.global?.margin_value ?? '')) })
      .catch(() => { if (active) setMessage('Impossible de charger la marge globale.') })
      .finally(() => { if (active) setLoading(false) })
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
          <h2>Règles spécifiques</h2>
          <div className="panel-actions">
            <span className="pill muted">À venir</span>
          </div>
        </div>

        <div className="table-wrap rules-table-wrap">
          <table className="rules-table">
            <thead>
              <tr>
                <th>Priorité</th>
                <th>Portée</th>
                <th>Cible</th>
                <th>Type</th>
                <th>Valeur</th>
                <th>Actif</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {marginRules.length === 0 ? (
                <tr>
                  <td colSpan="7" style={{ padding: '16px 0', color: '#65708a' }}>
                    Règles par SKU / marque / catégorie : à venir. Pour l'instant, utilisez la marge globale
                    ci-dessus ou la marge par catégorie (page Catégories).
                  </td>
                </tr>
              ) : marginRules.map((r) => (
                <tr key={r.priority}>
                  <td>{r.priority}</td>
                  <td><span className="pill muted">{r.scope}</span></td>
                  <td>{r.target}</td>
                  <td>{r.type}</td>
                  <td>{r.value}</td>
                  <td><label className="toggle"><input type="checkbox" defaultChecked={r.active} /><span className="toggle-knob" /></label></td>
                  <td>
                    <div className="row-actions">
                      <button className="row-icon-button"><span className="edit-icon" /></button>
                      <button className="row-icon-button"><span className="ban-icon" /></button>
                    </div>
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
