import { useEffect, useState } from 'react'

const API_BASE_URL = (import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000').replace(/\/$/, '')

export default function Brands() {
  const [brands, setBrands] = useState([])
  const [loading, setLoading] = useState(true)
  const [syncing, setSyncing] = useState(false)
  const [error, setError] = useState(null)
  const [apiConnected, setApiConnected] = useState(null)
  const [searchTerm, setSearchTerm] = useState('')
  const [filterBlacklist, setFilterBlacklist] = useState(null)

  // TDSynex live tab
  const [activeView, setActiveView] = useState('local') // 'local' | 'tdsynnex'
  const [tdsynnexManufacturers, setTdsynnexManufacturers] = useState([])
  const [loadingTdsynnex, setLoadingTdsynnex] = useState(false)
  const [tdsynnexError, setTdsynnexError] = useState(null)
  const [importingSet, setImportingSet] = useState(new Set())

  useEffect(() => { checkApiConnection() }, [])

  useEffect(() => {
    if (apiConnected) fetchBrands()
  }, [searchTerm, filterBlacklist, apiConnected])

  // Reload TDSynex list when search changes (only if that tab is active)
  useEffect(() => {
    if (activeView === 'tdsynnex' && apiConnected) fetchTdsynnexManufacturers()
  }, [searchTerm, activeView, apiConnected])

  const checkApiConnection = async () => {
    try {
      const response = await fetch(`${API_BASE_URL}/api/health`)
      setApiConnected(response.ok)
    } catch (err) {
      setApiConnected(false)
    }
  }

  const fetchBrands = async () => {
    try {
      setLoading(true)
      setError(null)
      const params = new URLSearchParams()
      if (searchTerm) params.append('search', searchTerm)
      if (filterBlacklist !== null) params.append('blacklisted', filterBlacklist)
      const response = await fetch(`${API_BASE_URL}/api/brands?${params.toString()}`)
      if (!response.ok) throw new Error('Erreur lors de la récupération des marques')
      const json = await response.json()
      setBrands(json.data || [])
    } catch (err) {
      setError(err.message)
    } finally {
      setLoading(false)
    }
  }

  const fetchTdsynnexManufacturers = async () => {
    setLoadingTdsynnex(true)
    setTdsynnexError(null)
    try {
      const params = new URLSearchParams()
      if (searchTerm) params.append('search', searchTerm)
      const response = await fetch(`${API_BASE_URL}/api/brands/tdsynnex-manufacturers?${params.toString()}`)
      const json = await response.json()
      if (!response.ok) throw new Error(json.message || 'Erreur lors du chargement depuis TDSynex')
      setTdsynnexManufacturers(json.data || [])
    } catch (err) {
      setTdsynnexError(err.message)
    } finally {
      setLoadingTdsynnex(false)
    }
  }

  const handleSync = async () => {
    try {
      setSyncing(true)
      setError(null)
      const response = await fetch(`${API_BASE_URL}/api/brands/sync`, { method: 'POST' })
      const json = await response.json()
      if (!response.ok) throw new Error(json.error || json.message || 'Erreur lors de la synchronisation')
      const sourceProducts = typeof json.source_products === 'number' ? json.source_products : 0
      if (json.total === 0 && sourceProducts === 0) {
        alert(json.message || 'Aucun fabricant à récupérer.')
      } else {
        alert(`Synchronisation terminée\n- ${json.created} nouvelles marques créées\n- ${json.updated} marques mises à jour`)
      }
      fetchBrands()
      if (activeView === 'tdsynnex') fetchTdsynnexManufacturers()
    } catch (err) {
      setError(err.message)
    } finally {
      setSyncing(false)
    }
  }

  const importManufacturer = async (name) => {
    setImportingSet(prev => new Set([...prev, name]))
    try {
      const response = await fetch(`${API_BASE_URL}/api/brands`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ tds_manufacturer: name }),
      })
      if (!response.ok) {
        const json = await response.json()
        // 422 with "tds_manufacturer has already been taken" = already imported, treat as OK
        const msg = JSON.stringify(json)
        if (response.status !== 422 || !msg.toLowerCase().includes('already been taken')) {
          throw new Error(json.message || 'Erreur lors de l\'import')
        }
      }
      fetchBrands()
    } catch (err) {
      alert(`Erreur: ${err.message}`)
    } finally {
      setImportingSet(prev => { const s = new Set(prev); s.delete(name); return s })
    }
  }

  const handleToggleBlacklist = async (brandId, brandName, currentBlacklist) => {
    try {
      const reason = currentBlacklist ? null : prompt(`Raison de la blacklist pour ${brandName}:`)
      if (reason === undefined) return
      const response = await fetch(`${API_BASE_URL}/api/brands/${brandId}/toggle-blacklist`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ blacklist_reason: reason }),
      })
      if (!response.ok) throw new Error('Erreur lors de la mise à jour')
      fetchBrands()
    } catch (err) {
      setError(err.message)
    }
  }

  const getInitials = (name) =>
    name.split(' ').map((w) => w[0]).join('').toUpperCase().slice(0, 2)

  // Build a Set of already-imported manufacturer names for quick lookup
  const localNames = new Set(brands.map((b) => b.tds_manufacturer?.toLowerCase()))

  const brandFilters = [
    { label: 'Tous',        active: filterBlacklist === null,  value: null  },
    { label: 'Actifs',      active: filterBlacklist === false, value: false },
    { label: 'Blacklistés', active: filterBlacklist === true,  value: true  },
  ]

  const runDiagnostic = async () => {
    try {
      const resp = await fetch(`${API_BASE_URL}/api/brands/tdsynnex-test`)
      const json = await resp.json()
      let lines = [`Token OK : ${json.token_ok ? '✅' : '❌'}`, '']
      if (json.step === 'token') {
        lines.push(`❌ Échec token (${json.status}) : ${json.message}`)
        lines.push(JSON.stringify(json.body, null, 2))
      } else if (json.attempts) {
        json.attempts.forEach(a => {
          lines.push(`${a.ok ? '✅' : '❌'} ${a.url}`)
          lines.push(`   → HTTP ${a.status}`)
          if (!a.ok) lines.push(`   → ${JSON.stringify(a.body)}`)
        })
      } else {
        lines.push(JSON.stringify(json, null, 2))
      }
      alert(lines.join('\n'))
    } catch (err) {
      alert('Erreur diagnostic : ' + err.message)
    }
  }

  const switchToTdsynnex = () => {
    setActiveView('tdsynnex')
    if (!tdsynnexManufacturers.length && !loadingTdsynnex) fetchTdsynnexManufacturers()
  }

  return (
    <main className="content-grid brands-page">
      {/* ── Toolbar ──────────────────────────────────────────── */}
      <section className="brands-toolbar">
        <label className="search-field search-field--brands" htmlFor="brand-search">
          <span className="search-icon" aria-hidden="true" />
          <input
            id="brand-search"
            type="search"
            placeholder="Rechercher une marque..."
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
          />
        </label>

        <div className="brand-actions">
          <button className="primary-action" type="button" onClick={handleSync} disabled={syncing || !apiConnected}>
            <span className="sync-icon" aria-hidden="true" />
            {syncing ? 'Synchronisation...' : 'Synchroniser'}
          </button>
          <button className="secondary-action" type="button">
            <span className="download-icon" aria-hidden="true" />
            Exporter CSV
          </button>
        </div>
      </section>

      {/* ── View tabs ─────────────────────────────────────────── */}
      <div style={{ display: 'flex', gap: '8px', marginBottom: '8px' }}>
        <button
          type="button"
          onClick={() => setActiveView('local')}
          style={{
            padding: '6px 16px',
            borderRadius: '6px',
            border: '1px solid',
            cursor: 'pointer',
            fontWeight: activeView === 'local' ? 600 : 400,
            background: activeView === 'local' ? 'var(--color-primary, #2563eb)' : 'transparent',
            color: activeView === 'local' ? '#fff' : 'inherit',
            borderColor: activeView === 'local' ? 'var(--color-primary, #2563eb)' : '#d1d5db',
          }}
        >
          Marques locales {!loading && `(${brands.length})`}
        </button>
        <button
          type="button"
          onClick={switchToTdsynnex}
          disabled={!apiConnected}
          style={{
            padding: '6px 16px',
            borderRadius: '6px',
            border: '1px solid',
            cursor: apiConnected ? 'pointer' : 'not-allowed',
            fontWeight: activeView === 'tdsynnex' ? 600 : 400,
            background: activeView === 'tdsynnex' ? 'var(--color-primary, #2563eb)' : 'transparent',
            color: activeView === 'tdsynnex' ? '#fff' : 'inherit',
            borderColor: activeView === 'tdsynnex' ? 'var(--color-primary, #2563eb)' : '#d1d5db',
          }}
        >
          Depuis TDSynex {activeView === 'tdsynnex' && !loadingTdsynnex && `(${tdsynnexManufacturers.length})`}
        </button>
      </div>

      {/* ── Filter pills (local view only) ────────────────────── */}
      {activeView === 'local' && (
        <section className="filter-pills filter-pills--brands">
          {brandFilters.map((filter) => (
            <button
              key={filter.label}
              className={filter.active ? 'pill active' : 'pill'}
              onClick={() => setFilterBlacklist(filter.value)}
              type="button"
            >
              {filter.label}
            </button>
          ))}
        </section>
      )}

      {/* ── Connection warning ────────────────────────────────── */}
      {apiConnected === false && (
        <div style={{ padding: '12px', backgroundColor: '#ffe8cc', color: '#b26a00', borderRadius: '4px', marginBottom: '16px' }}>
          ⚠️ API non connectée. Impossible de charger les marques. Veuillez configurer les paramètres d'intégration.
        </div>
      )}

      {/* ── Local error ───────────────────────────────────────── */}
      {error && (
        <div style={{ padding: '12px', backgroundColor: '#fee', color: '#c33', borderRadius: '4px', marginBottom: '16px' }}>
          {error}
        </div>
      )}

      {/* ══════════════════════════════════════════════════════════
          LOCAL BRANDS VIEW
      ══════════════════════════════════════════════════════════ */}
      {activeView === 'local' && (
        <section className="panel brands-panel">
          <div className="panel-header brands-panel-header">
            <div>
              <h2>Marques</h2>
              <p>{loading ? 'Chargement...' : `${brands.length} marque${brands.length !== 1 ? 's' : ''}`}</p>
            </div>
          </div>

          <div className="table-wrap">
            {apiConnected === false ? (
              <div style={{ padding: '32px', textAlign: 'center', color: '#999' }}>
                API non accessible. Vérifiez votre connexion et la configuration du serveur.
              </div>
            ) : loading ? (
              <div style={{ padding: '32px', textAlign: 'center', color: '#999' }}>
                Chargement des marques...
              </div>
            ) : brands.length === 0 ? (
              <div style={{ padding: '32px', textAlign: 'center', color: '#999' }}>
                Aucune marque locale. Cliquez sur <strong>Depuis TDSynex</strong> pour voir et importer les marques disponibles.

              </div>
            ) : (
              <table className="brands-table">
                <thead>
                  <tr>
                    <th className="table-check"><span className="checkbox-box" aria-hidden="true" /></th>
                    <th>Logo</th>
                    <th>Marque TDS</th>
                    <th>Marque PrestaShop</th>
                    <th>Nb produits</th>
                    <th>Actif</th>
                    <th>Blacklisté</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {brands.map((brand) => (
                    <tr key={brand.id}>
                      <td className="table-check"><span className="checkbox-box" aria-hidden="true" /></td>
                      <td><span className="logo-badge">{getInitials(brand.tds_manufacturer)}</span></td>
                      <td className="tds-name">{brand.tds_manufacturer}</td>
                      <td>{brand.ps_manufacturer_id || '-'}</td>
                      <td><span className="mini-chip">{brand.product_count}</span></td>
                      <td>
                        <span className={`status-chip ${brand.active ? 'success' : 'error'}`}>
                          {brand.active ? 'Actif' : 'Inactif'}
                        </span>
                      </td>
                      <td className="muted-cell">
                        {brand.blacklisted ? (
                          <span title={brand.blacklist_reason || 'Blacklisté'}>✓ Oui</span>
                        ) : 'Non'}
                      </td>
                      <td>
                        <div className="row-actions" aria-label={`Actions pour ${brand.tds_manufacturer}`}>
                          <button
                            className="row-icon-button"
                            type="button"
                            aria-label="Toggle blacklist"
                            onClick={() => handleToggleBlacklist(brand.id, brand.tds_manufacturer, brand.blacklisted)}
                            title={brand.blacklisted ? 'Retirer de la blacklist' : 'Ajouter à la blacklist'}
                          >
                            <span className="ban-icon" aria-hidden="true" />
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        </section>
      )}

      {/* ══════════════════════════════════════════════════════════
          TDSYNEX LIVE VIEW
      ══════════════════════════════════════════════════════════ */}
      {activeView === 'tdsynnex' && (
        <section className="panel brands-panel">
          <div className="panel-header brands-panel-header">
            <div>
              <h2>Marques TD SYNNEX</h2>
              <p>
                {loadingTdsynnex
                  ? 'Chargement depuis l\'API TDSynex…'
                  : tdsynnexError
                  ? 'Erreur lors du chargement'
                  : `${tdsynnexManufacturers.length} marque${tdsynnexManufacturers.length !== 1 ? 's' : ''} trouvée${tdsynnexManufacturers.length !== 1 ? 's' : ''}`}
              </p>
            </div>
            <div style={{ display: 'flex', gap: '8px', alignItems: 'center' }}>
              <button
                type="button"
                className="secondary-action"
                onClick={runDiagnostic}
                style={{ padding: '6px 14px', fontSize: '0.85rem' }}
                title="Tester la connexion TDSynex étape par étape"
              >
                🔍 Diagnostiquer
              </button>
              <button
                type="button"
                className="secondary-action"
                onClick={fetchTdsynnexManufacturers}
                disabled={loadingTdsynnex}
                style={{ padding: '6px 14px', fontSize: '0.85rem' }}
              >
                ↻ Actualiser
              </button>
              <button
                type="button"
                className="primary-action"
                onClick={handleSync}
                disabled={syncing || loadingTdsynnex}
                style={{ padding: '6px 14px', fontSize: '0.85rem' }}
              >
                {syncing ? 'Import en cours…' : 'Tout importer en local'}
              </button>
            </div>
          </div>

          {/* TDSynex error */}
          {tdsynnexError && (
            <div style={{ padding: '12px 16px', backgroundColor: '#fee', color: '#c33', borderRadius: '4px', margin: '8px 16px' }}>
              {tdsynnexError}
            </div>
          )}

          <div className="table-wrap">
            {loadingTdsynnex ? (
              <div style={{ padding: '48px', textAlign: 'center', color: '#999' }}>
                <div style={{ marginBottom: '8px', fontSize: '1.5rem' }}>⏳</div>
                Interrogation de l'API TDSynex en cours… (classes interrogées en parallèle)
              </div>
            ) : tdsynnexManufacturers.length === 0 && !tdsynnexError ? (
              <div style={{ padding: '32px', textAlign: 'center', color: '#999' }}>
                Aucun fabricant trouvé.{searchTerm ? ` Aucun résultat pour « ${searchTerm} ».` : ' Vérifiez vos identifiants TDSynex dans les paramètres.'}
              </div>
            ) : tdsynnexManufacturers.length > 0 ? (
              <table className="brands-table">
                <thead>
                  <tr>
                    <th>Logo</th>
                    <th>Marque TDSynex</th>
                    <th>Statut local</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                  {tdsynnexManufacturers.map((name) => {
                    const alreadyLocal = localNames.has(name.toLowerCase())
                    const isImporting  = importingSet.has(name)
                    return (
                      <tr key={name}>
                        <td><span className="logo-badge">{getInitials(name)}</span></td>
                            <td className="tds-name">{name}</td>
                        <td>
                          {alreadyLocal ? (
                            <span className="status-chip success">Importé</span>
                          ) : (
                            <span className="status-chip" style={{ background: '#f3f4f6', color: '#6b7280' }}>Non importé</span>
                          )}
                        </td>
                        <td>
                          {alreadyLocal ? (
                            <span style={{ color: '#9ca3af', fontSize: '0.85rem' }}>—</span>
                          ) : (
                            <button
                              type="button"
                              className="secondary-action"
                              disabled={isImporting}
                              onClick={() => importManufacturer(name)}
                              style={{ padding: '4px 12px', fontSize: '0.82rem' }}
                            >
                              {isImporting ? '…' : '+ Importer'}
                            </button>
                          )}
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            ) : null}
          </div>
        </section>
      )}
    </main>
  )
}
