import { useCallback, useEffect, useMemo, useState } from 'react'

const API_BASE_URL = (typeof import.meta.env.VITE_API_URL === 'string' && import.meta.env.VITE_API_URL.length > 0)
  ? import.meta.env.VITE_API_URL.replace(/\/$/, '')
  : ''

async function requestJson(path, options = {}) {
  const response = await fetch(`${API_BASE_URL}${path}`, {
    ...options,
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...(options.headers || {}) },
  })

  const payload = await response.json().catch(() => ({}))
  if (!response.ok) throw new Error(payload.message || 'Une erreur est survenue.')
  return payload
}

function normalizeProduct(product) {
  return {
    id: product.id,
    sku: product.sku,
    name: product.name,
    manufacturer: product.manufacturer || '-',
    category_tds: product.category_tds || '-',
    category_mapped: Boolean(product.category_mapped),
    ean: product.ean || '-',
    stock_qty: typeof product.stock_qty === 'number' ? product.stock_qty : null,
    stock_tone: product.stock_tone || 'muted',
    cost_price: product.cost_price != null ? parseFloat(product.cost_price) : null,
    selling_price: product.selling_price != null ? parseFloat(product.selling_price) : null,
    weight: product.weight != null ? parseFloat(product.weight) : null,
    is_active: Boolean(product.is_active),
    status: product.status || 'Actif',
    status_tone: product.status_tone || 'success',
  }
}

export default function Products() {
  const [products, setProducts] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [apiConnected, setApiConnected] = useState(null)
  const [searchTerm, setSearchTerm] = useState('')
  const [totalResults, setTotalResults] = useState(0)
  const [currentPage, setCurrentPage] = useState(1)
  const [totalPages, setTotalPages] = useState(1)
  const [filterMode, setFilterMode] = useState('mapped')
  const [mappedCount, setMappedCount] = useState(0)
  const [syncing, setSyncing] = useState(false)
  const [syncResult, setSyncResult] = useState(null)
  const [pageInput, setPageInput] = useState('')

  const fetchProducts = useCallback(async ({ page = currentPage, mode = filterMode } = {}) => {
    try {
      setLoading(true)
      setError('')

      const pageSize = 100
      const baseParams = new URLSearchParams()
      baseParams.append('source', 'local')
      baseParams.append('page', String(page))
      baseParams.append('pageSize', String(pageSize))
      baseParams.append('filter', mode)
      if (searchTerm) baseParams.append('search', searchTerm)

      const firstPage = await requestJson(`/api/tdsynnex-products?${baseParams.toString()}`)
      const normalized = Array.isArray(firstPage?.data) ? firstPage.data.map(normalizeProduct) : []

      setProducts(normalized)
      setTotalResults(typeof firstPage.totalResults === 'number' ? firstPage.totalResults : normalized.length)
      setTotalPages(Math.max(1, Number(firstPage?.totalPages || 1)))
      setCurrentPage(Math.max(1, Number(firstPage?.page || 1)))
      if (Array.isArray(firstPage?.mapped_codes)) {
        setMappedCount(firstPage.mapped_codes.length)
      }
    } catch (err) {
      setError(err.message)
    } finally {
      setLoading(false)
    }
  }, [currentPage, filterMode, searchTerm])

  const visibleProducts = useMemo(() => {
    const term = searchTerm.trim().toLowerCase()
    const deduped = []
    const seen = new Set()

    for (const product of products) {
      const uniqueKey = product.id || product.sku || `${product.name}-${product.ean}`
      if (seen.has(uniqueKey)) continue
      seen.add(uniqueKey)
      if (!term) {
        deduped.push(product)
        continue
      }

      const haystack = [product.sku, product.name, product.manufacturer, product.category_tds, product.ean]
        .filter(Boolean)
        .join(' ')
        .toLowerCase()

      if (haystack.includes(term)) {
        deduped.push(product)
      }
    }

    return deduped
  }, [products, searchTerm])

  useEffect(() => {
    let active = true

    requestJson('/api/health')
      .then(() => {
        if (active) setApiConnected(true)
      })
      .catch(() => {
        if (active) setApiConnected(false)
      })

    return () => {
      active = false
    }
  }, [])

  useEffect(() => {
    if (apiConnected) {
      fetchProducts({ page: currentPage, mode: filterMode })
    }
  }, [apiConnected, currentPage, filterMode, fetchProducts])

  useEffect(() => {
    setCurrentPage(1)
  }, [searchTerm, filterMode])

  const handleRefresh = async () => {
    try {
      setSyncing(true)
      setSyncResult(null)
      setError('')

      const result = await requestJson('/api/tdsynnex-products/sync', { method: 'POST' })
      setSyncResult({ ok: true, ...result })
      await fetchProducts({ page: 1, mode: filterMode })
      setCurrentPage(1)
    } catch (err) {
      setSyncResult({ ok: false, message: err.message })
      setError(err.message)
    } finally {
      setSyncing(false)
    }
  }

  const handleFilterChange = (mode) => {
    if (mode === filterMode || loading) return
    setFilterMode(mode)
    setCurrentPage(1)
  }

  const goToPreviousPage = () => {
    if (currentPage > 1 && !loading) {
      setCurrentPage((page) => page - 1)
    }
  }

  const goToNextPage = () => {
    if (currentPage < totalPages && !loading) {
      setCurrentPage((page) => page + 1)
    }
  }

  const handlePageInputSubmit = (e) => {
    e.preventDefault()
    const n = parseInt(pageInput, 10)
    if (!isNaN(n) && n >= 1 && n <= totalPages && n !== currentPage && !loading) {
      setCurrentPage(n)
    }
    setPageInput('')
  }

  const formatPrice = (value) => (
    typeof value === 'number'
      ? new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(value)
      : '-'
  )

  const getInitials = (name) => (name || '??')
    .split(' ')
    .filter(Boolean)
    .map((word) => word[0])
    .join('')
    .toUpperCase()
    .slice(0, 2)

  return (
    <main className="content-grid products-page">
      <section className="products-toolbar">
        <label className="search-field search-field--products" htmlFor="product-search">
          <span className="search-icon" aria-hidden="true" />
          <input
            id="product-search"
            type="search"
            placeholder="Rechercher par SKU, nom, marque..."
            value={searchTerm}
            onChange={(event) => setSearchTerm(event.target.value)}
          />
        </label>

        <div className="product-actions">
          <button className="secondary-action" type="button" onClick={handleRefresh} disabled={!apiConnected || loading || syncing}>
            <span className="filter-icon" aria-hidden="true" />
            {syncing ? 'Synchronisation...' : 'Synchroniser TD SYNNEX'}
          </button>
          <button className="secondary-action" type="button">
            <span className="download-icon" aria-hidden="true" />
            Exporter CSV
          </button>
        </div>
      </section>

      <section className="panel products-panel">
        <div className="panel-header products-panel-header">
          <div>
            <h2>Produits TDSynnex importés</h2>
            <p>
              {totalResults} produit{totalResults > 1 ? 's' : ''} {filterMode === 'mapped' ? 'dans les catégories mappées' : 'au total dans le catalogue'}.
            </p>
          </div>
        </div>

        {syncing && (
          <div className="sync-result warning" style={{ marginBottom: '12px' }}>
            <p className="sync-result-line">Synchronisation en cours depuis TD SYNNEX… cela peut prendre une à deux minutes.</p>
          </div>
        )}

        {!syncing && syncResult && (
          <div className={`sync-result ${syncResult.ok ? 'success' : 'error'}`} style={{ marginBottom: '12px' }}>
            {syncResult.ok ? (
              <>
                <p className="sync-result-line">{syncResult.total} produit{syncResult.total > 1 ? 's' : ''} synchronisé{syncResult.total > 1 ? 's' : ''}.</p>
                {syncResult.per_category && (
                  <ul className="sync-errors">
                    {Object.entries(syncResult.per_category).map(([code, value]) => (
                      <li key={code}>
                        {code}: {typeof value === 'number' ? `${value} produits` : `erreur — ${value.error}`}
                      </li>
                    ))}
                  </ul>
                )}
              </>
            ) : (
              <p className="sync-result-line">{syncResult.message}</p>
            )}
          </div>
        )}

        <div className="filter-pills filter-pills--products">
          <button
            type="button"
            className={filterMode === 'all' ? 'pill pill--btn active' : 'pill pill--btn'}
            onClick={() => handleFilterChange('all')}
          >
            Tous
          </button>
          <button
            type="button"
            className={filterMode === 'mapped' ? 'pill pill--btn active' : 'pill pill--btn'}
            onClick={() => handleFilterChange('mapped')}
          >
            Catégories mappées {mappedCount > 0 ? `(${mappedCount})` : ''}
          </button>
        </div>

        <div className="table-wrap products-table-wrap">
          <table className="products-table">
            <thead>
              <tr>
                <th className="table-check"><span className="checkbox-box" aria-hidden="true" /></th>
                <th>Image</th>
                <th>SKU</th>
                <th>Nom produit</th>
                <th>Fabricant</th>
                <th>Catégorie TDS</th>
                <th>EAN</th>
                <th>Stock</th>
                <th>Prix achat HT</th>
                <th>Prix vendu HT</th>
                <th>Statut</th>
              </tr>
            </thead>
            <tbody>
              {error && (
                <tr>
                  <td colSpan="11">
                    <div style={{ padding: '14px 0', color: '#b26a00', fontWeight: 600 }}>
                      {error}
                    </div>
                  </td>
                </tr>
              )}

              {!error && loading && (
                <tr>
                  <td colSpan="11">
                    <div style={{ padding: '18px 0', color: '#65708a' }}>Chargement des produits TDSynnex...</div>
                  </td>
                </tr>
              )}

              {!error && !loading && visibleProducts.length === 0 && (
                <tr>
                  <td colSpan="11">
                    <div style={{ padding: '18px 0', color: '#65708a' }}>Aucun produit TDSynnex trouvé.</div>
                  </td>
                </tr>
              )}

              {!error && !loading && visibleProducts.map((product) => (
                <tr key={`${product.id || product.sku}-${product.ean || 'no-ean'}`}>
                  <td className="table-check"><span className="checkbox-box" aria-hidden="true" /></td>
                  <td><span className="image-badge">{getInitials(product.name)}</span></td>
                  <td className="sku-cell">{product.sku}</td>
                  <td className="product-name-cell">{product.name}</td>
                  <td>{product.manufacturer || '-'}</td>
                  <td>
                    {product.category_tds || '-'}
                    {product.category_mapped && (
                      <span className="mapped-badge">Mappée</span>
                    )}
                  </td>
                  <td>{product.ean || '-'}</td>
                  <td>
                    <span className={`stock-chip ${product.stock_tone}`}>
                      {product.stock_qty != null ? product.stock_qty : '-'}
                    </span>
                  </td>
                  <td>{formatPrice(product.cost_price)}</td>
                  <td>{formatPrice(product.selling_price)}</td>
                  <td><span className={`status-chip ${product.status_tone}`}>{product.status}</span></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '14px 0 4px', gap: '12px', flexWrap: 'wrap' }}>
          <p style={{ margin: 0, color: '#65708a' }}>
            {visibleProducts.length} produit{visibleProducts.length > 1 ? 's' : ''} affiché{visibleProducts.length > 1 ? 's' : ''}
            {totalResults ? ` sur ${totalResults}` : ''}
          </p>
          <div style={{ display: 'flex', gap: '8px', alignItems: 'center', flexWrap: 'wrap' }}>
            <button
              type="button"
              className="secondary-action"
              onClick={goToPreviousPage}
              disabled={!apiConnected || loading || currentPage <= 1}
              style={{ padding: '6px 12px' }}
            >
              ← Précédent
            </button>
            <form onSubmit={handlePageInputSubmit} style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
              <span style={{ color: '#65708a', whiteSpace: 'nowrap' }}>Page</span>
              <input
                type="number"
                min={1}
                max={totalPages}
                value={pageInput}
                onChange={(e) => setPageInput(e.target.value)}
                placeholder={String(currentPage)}
                disabled={loading || !apiConnected}
                style={{
                  width: '56px',
                  padding: '5px 8px',
                  border: '1px solid #d0d8ec',
                  borderRadius: '8px',
                  fontSize: '0.875rem',
                  textAlign: 'center',
                  color: '#172033',
                  background: '#fff',
                }}
              />
              <span style={{ color: '#65708a', whiteSpace: 'nowrap' }}>/ {totalPages}</span>
              <button
                type="submit"
                className="secondary-action"
                disabled={loading || !apiConnected || pageInput === ''}
                style={{ padding: '5px 10px' }}
              >
                Aller
              </button>
            </form>
            <button
              type="button"
              className="secondary-action"
              onClick={goToNextPage}
              disabled={!apiConnected || loading || currentPage >= totalPages}
              style={{ padding: '6px 12px' }}
            >
              Suivant →
            </button>
          </div>
        </div>
      </section>
    </main>
  )
}
