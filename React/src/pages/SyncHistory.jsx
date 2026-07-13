import { useEffect, useMemo, useState } from 'react'

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

// Status icon: maps a sync status to the available .sync-status-icon CSS tones.
function statusTone(status) {
  if (status === 'success') return 'success'
  if (status === 'partial') return 'warning'
  return 'error'
}

function statusGlyph(tone) {
  if (tone === 'success') return '✓'
  if (tone === 'warning') return '!'
  return '✕'
}

// Operation → readable label for the secondary line.
const OPERATION_LABELS = {
  push_prestashop: 'Push PrestaShop',
  full_catalog: 'Catalogue complet',
  prices_stock: 'Prix & Stock',
}

const TRIGGER_FILTERS = [
  { key: 'all', label: 'Toutes' },
  { key: 'manual', label: 'Manuelle' },
  { key: 'scheduler', label: 'Automatique' },
]

export default function SyncHistory() {
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [filter, setFilter] = useState('all')
  const [expandedId, setExpandedId] = useState(null)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setError(null)
    requestJson('/api/sync-logs?refresh=1&limit=200')
      .then((payload) => { if (!cancelled) setRows(Array.isArray(payload.data) ? payload.data : []) })
      .catch((err) => { if (!cancelled) setError(err.message || 'Chargement impossible.') })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [])

  const visibleRows = useMemo(
    () => (filter === 'all' ? rows : rows.filter((r) => r.trigger === filter)),
    [rows, filter],
  )

  return (
    <main className="content-grid sync-history-page">
      <section className="sync-history-toolbar">
        <div className="sync-filter-pills">
          {TRIGGER_FILTERS.map((f) => (
            <button
              key={f.key}
              className={filter === f.key ? 'sync-pill-button active' : 'sync-pill-button'}
              type="button"
              onClick={() => setFilter(f.key)}
            >
              {f.label}
            </button>
          ))}
        </div>

        <div className="sync-date-range" aria-label="Filtre par date">
          <label className="sync-date-field sync-date-from">
            <span className="date-icon">📅</span>
            <input type="text" placeholder="jj/mm/aaaa" />
          </label>
          <span className="sync-date-separator">à</span>
          <label className="sync-date-field">
            <input type="text" placeholder="jj/mm/aaaa" />
          </label>
        </div>
      </section>

      <section className="sync-history-list" aria-label="Liste des synchronisations">
        {loading && <p className="sync-empty-state">Chargement de l'historique…</p>}

        {!loading && error && (
          <p className="sync-empty-state" style={{ color: '#ff5b5b' }}>{error}</p>
        )}

        {!loading && !error && visibleRows.length === 0 && (
          <p className="sync-empty-state">Aucune synchronisation enregistrée pour ce filtre.</p>
        )}

        {!loading && !error && visibleRows.map((row) => {
          const tone = statusTone(row.status)
          const triggerTone = row.trigger_tone === 'auto' ? 'success' : 'neutral'
          const operationLabel = OPERATION_LABELS[row.operation] || OPERATION_LABELS[row.type] || row.type
          const reportErrors = Array.isArray(row.report?.errors) ? row.report.errors : []
          const isOpen = expandedId === row.id

          return (
            <article className="sync-row-card" key={row.id}>
              <div className="sync-status-and-type">
                <span className={`sync-status-icon ${tone}`}>{statusGlyph(tone)}</span>
                <span className={`sync-type-chip ${triggerTone}`}>{row.trigger_label}</span>
              </div>

              <div className="sync-col sync-col-date">
                <strong>{row.date || '—'}</strong>
                <span>{operationLabel}{row.category ? ` · ${row.category}` : ''} · Durée: {row.duration}</span>
              </div>

              <div className="sync-col sync-col-metric success">
                <strong>{row.created}</strong>
                <span>créés</span>
              </div>

              <div className="sync-col sync-col-metric info">
                <strong>{row.updated}</strong>
                <span>mis à jour</span>
              </div>

              <div className="sync-col sync-col-metric muted">
                <strong>{row.disabled}</strong>
                <span>désactivés</span>
              </div>

              <div className="sync-col sync-col-metric danger">
                <strong>{row.errors}</strong>
                <span>erreurs</span>
              </div>

              <button
                className="sync-report-link"
                type="button"
                onClick={() => setExpandedId(isOpen ? null : row.id)}
              >
                <span>{isOpen ? 'Masquer le rapport' : 'Voir le rapport'}</span>
                <span className="sync-chevron" aria-hidden="true" />
              </button>

              {isOpen && (
                <div className="sync-report-detail" style={{ gridColumn: '1 / -1', marginTop: 10, padding: '12px 14px', background: '#f6f8fc', borderRadius: 10, fontSize: '0.95rem', color: '#3f4d66' }}>
                  <div><strong>Opération :</strong> {operationLabel}{row.category ? ` (catégorie ${row.category})` : ''}</div>
                  <div><strong>Déclenchement :</strong> {row.trigger_label}</div>
                  <div><strong>Statut :</strong> {row.status} · {row.created} créés, {row.updated} mis à jour, {row.skipped} ignorés (sans prix), {row.errors} erreurs</div>
                  {reportErrors.length > 0 && (
                    <details style={{ marginTop: 8 }}>
                      <summary style={{ cursor: 'pointer' }}>{reportErrors.length} produit(s) en erreur</summary>
                      <ul style={{ margin: '6px 0 0', paddingLeft: 18 }}>
                        {reportErrors.slice(0, 50).map((e, i) => (
                          <li key={i}>{e.sku || '?'} — {e.error || 'erreur'}</li>
                        ))}
                      </ul>
                    </details>
                  )}
                </div>
              )}
            </article>
          )
        })}
      </section>
    </main>
  )
}
