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

// ─── Helpers ──────────────────────────────────────────────────────────────────

function buildPsTree(categories) {
  const byId = categories.reduce((acc, c) => { acc[c.id] = c; return acc }, {})
  const roots = categories.filter(c => c.parent_id === c.id || !byId[c.parent_id])
  function children(parentId) {
    return categories
      .filter(c => c.parent_id === parentId && c.id !== parentId)
      .map(c => ({ ...c, children: children(c.id) }))
  }
  return roots.map(c => ({ ...c, children: children(c.id) }))
}

// ─── Status config ─────────────────────────────────────────────────────────────

const STATUS = {
  mapped: { label: '✓ Mappée', tone: 'success' },
  unmapped: { label: '⚠ Non mappée', tone: 'warning' },
  ignored: { label: '✗ Ignorée', tone: 'muted' },
}

function mappingToStatus(mapping) {
  if (!mapping) return 'unmapped'
  if (mapping.ignored) return 'ignored'
  if (mapping.ps_category_id) return 'mapped'
  return 'unmapped'
}

const FILTERS = [
  { key: 'all', label: 'Toutes' },
  { key: 'mapped', label: 'Mappées', tone: 'success' },
  { key: 'unmapped', label: 'Non mappées', tone: 'warning' },
  { key: 'ignored', label: 'Ignorées', tone: 'muted' },
]

// ─── Tree components ───────────────────────────────────────────────────────────

function matchesFilter(node, filter, statuses) {
  if (filter === 'all') return true
  return (statuses[node.id] || 'unmapped') === filter
}

function TdsRow({ node, depth, isCollapsed, onToggle, status, isSelected, onSelect }) {
  const cfg = STATUS[status] || STATUS.unmapped
  const hasChildren = Array.isArray(node.children) && node.children.length > 0

  return (
    <div
      className={`tree-node${isSelected ? ' selected' : ''}`}
      style={{ paddingLeft: `${12 + depth * 24}px` }}
      role="button"
      tabIndex={0}
      onClick={() => onSelect(node.id)}
      onKeyDown={e => e.key === 'Enter' && onSelect(node.id)}
    >
      <div className="tree-left">
        {hasChildren ? (
          <button
            className="tree-chevron-btn"
            type="button"
            onClick={e => { e.stopPropagation(); onToggle(node.id) }}
            aria-label={isCollapsed ? 'Développer' : 'Réduire'}
          >
            <span className={`tree-chevron${isCollapsed ? ' collapsed' : ''}`}>⌄</span>
          </button>
        ) : (
          <span className="tree-spacer" />
        )}
        <span className="tree-name">{node.name}</span>
        <span className="tree-code">{node.code}</span>
      </div>
      <div className="tree-right">
        <span className="tree-count">{node.product_count} produit{node.product_count > 1 ? 's' : ''}</span>
        <span className={`tree-status ${cfg.tone}`}>{cfg.label}</span>
      </div>
    </div>
  )
}

function TdsSubtree({ nodes, depth, collapsedNodes, onToggle, statuses, selectedId, onSelect, activeFilter }) {
  return nodes
    .filter(node => matchesFilter(node, activeFilter, statuses))
    .map(node => {
      const isCollapsed = collapsedNodes.has(node.id)
      const hasChildren = Array.isArray(node.children) && node.children.length > 0
      return (
        <div key={node.id} className="tree-node-group">
          <TdsRow
            node={node}
            depth={depth}
            isCollapsed={isCollapsed}
            onToggle={onToggle}
            status={statuses[node.id] || 'unmapped'}
            isSelected={selectedId === node.id}
            onSelect={onSelect}
          />
          {hasChildren && !isCollapsed && (
            <div className="tree-children">
              <TdsSubtree
                nodes={node.children}
                depth={depth + 1}
                collapsedNodes={collapsedNodes}
                onToggle={onToggle}
                statuses={statuses}
                selectedId={selectedId}
                onSelect={onSelect}
                activeFilter={activeFilter}
              />
            </div>
          )}
        </div>
      )
    })
}

// ─── Main component ────────────────────────────────────────────────────────────

export default function Categories() {
  // TDS categories (left panel)
  const [tdsCategories, setTdsCategories] = useState([])
  const [tdsLoading, setTdsLoading] = useState(true)
  const [tdsError, setTdsError] = useState('')

  // PS categories (right panel dropdown)
  const [psCategories, setPsCategories] = useState([])

  // Mapping statuses keyed by tds category id (string)
  const [statuses, setStatuses] = useState({})
  // Full mapping data keyed by tds category id
  const [mappings, setMappings] = useState({})

  // UI state
  const [selectedId, setSelectedId] = useState(null)
  const [collapsedNodes, setCollapsedNodes] = useState(new Set())
  const [activeFilter, setActiveFilter] = useState('all')
  const [saving, setSaving] = useState(false)
  const [saveMessage, setSaveMessage] = useState('')
  const [syncing, setSyncing] = useState(false)
  const [syncResult, setSyncResult] = useState(null)
  // Import progress of the selected mapped category (polled from the backend)
  const [importStatus, setImportStatus] = useState(null)

  // Right panel form state
  const [formPsCategoryId, setFormPsCategoryId] = useState('')
  const [formMargin, setFormMargin] = useState('15')
  const [formMinStock, setFormMinStock] = useState('2')

  // Load TDS categories
  useEffect(() => {
    let active = true
    async function load() {
      setTdsLoading(true)
      try {
        const data = await requestJson('/api/categories/tds')
        if (!active) return
        const cats = data.categories || []
        const enriched = cats.map(c => ({ ...c, code: c.code || c.id, children: [] }))
        setTdsCategories(enriched)
        // Initialise statuses and mappings from backend data
        const initStatuses = {}
        const initMappings = {}
        for (const cat of cats) {
          initStatuses[cat.id] = mappingToStatus(cat.mapping)
          initMappings[cat.id] = cat.mapping || null
        }
        setStatuses(initStatuses)
        setMappings(initMappings)
        setSelectedId(enriched[0]?.id ?? null)
        setTdsError(data.success === false ? data.message : '')
      } catch (err) {
        if (!active) return
        setTdsError(err.message)
      } finally {
        if (active) setTdsLoading(false)
      }
    }
    load()
    return () => { active = false }
  }, [])

  // After categories load, fetch remote counts and merge into tdsCategories
  useEffect(() => {
    if (!tdsCategories || tdsCategories.length === 0) return
    let active = true
    async function loadCounts() {
      try {
        const data = await requestJson('/api/categories/tds/counts')
        const counts = data.counts || {}
        if (!active) return
        setTdsCategories(prev => prev.map(c => ({ ...c, product_count: counts[c.code] ?? c.product_count ?? 0 })))
      } catch (err) { }
    }
    loadCounts()
    return () => { active = false }
  }, [tdsCategories.length])

  // Load PS categories for the right panel dropdown
  useEffect(() => {
    requestJson('/api/integration-settings/prestashop/categories')
      .then(data => setPsCategories(data.categories || []))
      .catch(() => { })
  }, [])

  // Sync form when selection changes
  useEffect(() => {
    const m = mappings[selectedId]
    setFormPsCategoryId(m?.ps_category_id ? String(m.ps_category_id) : '')
    setFormMargin(m?.margin_override != null ? String(m.margin_override) : '15')
    setFormMinStock(m?.min_stock_override != null ? String(m.min_stock_override) : '2')
    setSaveMessage('')
    setSyncResult(null)
  }, [selectedId])

  // Poll the import progress for the selected category while it is mapped and the
  // import is still running. As soon as the background import finishes, polling
  // stops and the push button is enabled.
  useEffect(() => {
    const isMapped = (statuses[selectedId] || 'unmapped') === 'mapped'
    setImportStatus(null)
    if (!selectedId || !isMapped) return
    let active = true
    let timer = null
    const poll = async () => {
      try {
        // refresh=1 bypasses the 5-min API response cache so progress is live.
        const data = await requestJson(`/api/categories/${selectedId}/import-status?refresh=1`)
        if (!active) return
        setImportStatus(data)
        if (data.running) timer = setTimeout(poll, 3000)
      } catch { /* erreurs transitoires ignorées */ }
    }
    poll()
    return () => { active = false; if (timer) clearTimeout(timer) }
  }, [selectedId, statuses[selectedId]])

  const counts = useMemo(() => {
    const all = tdsCategories.length
    const mapped = tdsCategories.filter(c => (statuses[c.id] || 'unmapped') === 'mapped').length
    const unmapped = tdsCategories.filter(c => (statuses[c.id] || 'unmapped') === 'unmapped').length
    const ignored = tdsCategories.filter(c => (statuses[c.id] || 'unmapped') === 'ignored').length
    return { all, mapped, unmapped, ignored }
  }, [tdsCategories, statuses])

  function toggleCollapse(id) {
    setCollapsedNodes(prev => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  async function handleSave(ignored = false) {
    if (!selectedId) return
    setSaving(true)
    setSaveMessage('')
    try {
      const data = await requestJson('/api/categories/mapping', {
        method: 'POST',
        body: JSON.stringify({
          tds_category: selectedId,
          ps_category_id: ignored ? null : (formPsCategoryId ? Number(formPsCategoryId) : null),
          margin_override: formMargin ? Number(formMargin) : null,
          min_stock_override: formMinStock ? Number(formMinStock) : null,
          ignored,
        }),
      })
      setMappings(prev => ({ ...prev, [selectedId]: data.mapping }))
      setStatuses(prev => ({ ...prev, [selectedId]: mappingToStatus(data.mapping) }))
      setSaveMessage('Mapping enregistré.')
    } catch (err) {
      setSaveMessage(err.message)
    } finally {
      setSaving(false)
    }
  }

  async function handlePush() {
    if (!selectedId) return
    setSyncing(true)
    setSyncResult(null)
    try {
      const data = await requestJson(`/api/categories/${selectedId}/push`, { method: 'POST' })
      setSyncResult({ ok: true, ...data })
    } catch (err) {
      setSyncResult({ ok: false, message: err.message })
    } finally {
      setSyncing(false)
    }
  }

  const selectedCategory = tdsCategories.find(c => c.id === selectedId)
  const selectedStatus = statuses[selectedId] || 'unmapped'

  // Flat PS category list for select (sorted by name)
  const psCategoryOptions = useMemo(() => {
    const flat = []
    function flatten(nodes, depth) {
      for (const n of nodes) {
        flat.push({ id: n.id, name: '  '.repeat(depth) + n.name })
        if (n.children?.length) flatten(n.children, depth + 1)
      }
    }
    flatten(buildPsTree(psCategories), 0)
    return flat
  }, [psCategories])

  return (
    <main className="content-grid categories-page">
      <section className="categories-toolbar panel">
        <label className="search-field" htmlFor="category-search">
          <span className="search-icon" aria-hidden="true" />
          <input id="category-search" type="search" placeholder="Rechercher une catégorie TD SYNNEX..." />
        </label>
        <button
          className="primary-action"
          type="button"
          onClick={() => window.location.reload()}
          disabled={tdsLoading}
        >
          <span className="refresh-icon" aria-hidden="true" />
          {tdsLoading ? 'Chargement...' : 'Rafraîchir'}
        </button>
      </section>

      <section className="categories-grid">
        {/* ── Left panel : TDS categories ── */}
        <article className="panel tree-panel">
          <div className="panel-header">
            <div>
              <h2>Catégories TD SYNNEX</h2>
              <p>Liste complète des catégories remontées par l’API</p>
            </div>
          </div>

          {tdsError ? <p className="api-status error">{tdsError}</p> : null}

          <div className="filter-pills">
            {FILTERS.map(f => (
              <button
                key={f.key}
                type="button"
                className={`pill${activeFilter === f.key ? ' active' : ''}${f.tone ? ` ${f.tone}` : ''}`}
                onClick={() => setActiveFilter(f.key)}
              >
                {f.label}{counts[f.key] > 0 ? ` (${counts[f.key]})` : ''}
              </button>
            ))}
          </div>

          <div className="tree-list">
            {tdsLoading ? (
              <p className="tree-empty">Chargement des catégories...</p>
            ) : tdsCategories.length > 0 ? (
              <TdsSubtree
                nodes={tdsCategories}
                depth={0}
                collapsedNodes={collapsedNodes}
                onToggle={toggleCollapse}
                statuses={statuses}
                selectedId={selectedId}
                onSelect={setSelectedId}
                activeFilter={activeFilter}
              />
            ) : !tdsError ? (
              <p className="tree-empty">
                Aucune catégorie trouvée.<br />
                Importez ou synchronisez des produits pour les voir apparaître ici.
              </p>
            ) : null}
          </div>
        </article>

        {/* ── Right panel : mapping to PrestaShop ── */}
        <article className="panel mapping-panel">
          <div className="panel-header">
            <div>
              <h2>Mapping vers PrestaShop</h2>
              <p>{selectedCategory?.name || 'Sélectionnez une catégorie'}</p>
            </div>
          </div>

          <section className="detail-block">
            <h3>Catégorie sélectionnée</h3>
            <dl className="detail-grid">
              <div><dt>Nom:</dt><dd>{selectedCategory?.name || '—'}</dd></div>
              <div><dt>Code:</dt><dd>{selectedCategory?.code || selectedCategory?.id || '—'}</dd></div>
              <div><dt>Produits:</dt><dd><span className="mini-chip">{selectedCategory?.product_count ?? '—'}</span></dd></div>
            </dl>
          </section>

          <section className="detail-block">
            <h3>Statut du mapping</h3>
            <div className="mapping-status-row">
              {Object.entries(STATUS).map(([key, cfg]) => (
                <button
                  key={key}
                  type="button"
                  className={`tree-status ${cfg.tone}${selectedStatus === key ? ' status-active' : ''}`}
                  onClick={() => {
                    if (!selectedId) return
                    if (key === 'ignored') { handleSave(true); return }
                    if (key === 'unmapped') {
                      setFormPsCategoryId('')
                      setStatuses(prev => ({ ...prev, [selectedId]: key }))
                      return
                    }
                    setStatuses(prev => ({ ...prev, [selectedId]: key }))
                  }}
                  disabled={selectedId == null}
                >
                  {cfg.label}
                </button>
              ))}
            </div>
          </section>

          <section className="detail-block">
            <h3>Catégorie PrestaShop cible</h3>
            <label className="field-label" htmlFor="ps-category">Catégorie PrestaShop</label>
            <select
              id="ps-category"
              value={formPsCategoryId}
              onChange={e => setFormPsCategoryId(e.target.value)}
              disabled={selectedId == null}
            >
              <option value="">— Sélectionner —</option>
              {psCategoryOptions.map(c => (
                <option key={c.id} value={c.id}>{c.name}</option>
              ))}
            </select>

            <div className="dual-fields">
              <label className="field-group">
                <span>Marge par défaut (%)</span>
                <input
                  type="number"
                  min="0"
                  max="100"
                  value={formMargin}
                  onChange={e => setFormMargin(e.target.value)}
                  disabled={selectedId == null}
                />
              </label>
              <label className="field-group">
                <span>Stock minimum</span>
                <input
                  type="number"
                  min="0"
                  value={formMinStock}
                  onChange={e => setFormMinStock(e.target.value)}
                  disabled={selectedId == null}
                />
              </label>
            </div>

            <button
              className="danger-box"
              type="button"
              onClick={() => handleSave(true)}
              disabled={selectedId == null || saving}
            >
              <span className="square-icon" aria-hidden="true" />
              Ignorer cette catégorie
            </button>
          </section>

          {saveMessage ? (
            <p className={`api-status ${saveMessage.includes('nregistré') ? 'success' : 'error'}`}>
              {saveMessage}
            </p>
          ) : null}

          <div className="action-row">
            <button
              className="save-button"
              type="button"
              onClick={() => handleSave(false)}
              disabled={selectedId == null || saving}
            >
              {saving ? 'Enregistrement...' : 'Enregistrer'}
            </button>
            <button className="cancel-button" type="button" onClick={() => {
              const m = mappings[selectedId]
              setFormPsCategoryId(m?.ps_category_id ? String(m.ps_category_id) : '')
              setFormMargin(m?.margin_override != null ? String(m.margin_override) : '15')
              setFormMinStock(m?.min_stock_override != null ? String(m.min_stock_override) : '2')
              setSaveMessage('')
            }}>
              Annuler
            </button>
          </div>

          {/* ── Sync to PrestaShop ── */}
          <section className="detail-block">
            <h3>Synchronisation PrestaShop</h3>
            <p className="sync-hint">
              {selectedStatus === 'mapped'
                ? 'Cette catégorie est mappée. Vous pouvez envoyer ses produits vers PrestaShop.'
                : 'Mappez cette catégorie à une catégorie PrestaShop pour activer la synchronisation.'}
            </p>

            {selectedStatus === 'mapped' && importStatus && (
              <p className={`import-progress ${importStatus.running ? 'running' : importStatus.stalled ? 'warning' : 'done'}`}>
                {importStatus.running
                  ? `⏳ Import des produits en cours… ${importStatus.imported}${importStatus.total ? ` / ${importStatus.total}` : ''} — patientez avant de pousser.`
                  : importStatus.stalled
                    ? `⚠ Import interrompu — ${importStatus.imported} produit(s) importé(s). Vous pouvez pousser, mais il en manque peut-être (re-mappez pour relancer l'import).`
                    : `✓ ${importStatus.imported} produit(s) importé(s), prêts à être poussés.`}
              </p>
            )}

            <button
              className="push-button"
              type="button"
              onClick={handlePush}
              disabled={selectedStatus !== 'mapped' || syncing || importStatus?.running}
            >
              {syncing
                ? 'Envoi en cours...'
                : importStatus?.running
                  ? 'Import en cours…'
                  : 'Pousser vers PrestaShop'}
            </button>

            {syncResult && (
              <div className={`sync-result ${syncResult.ok && !syncResult.errors?.length ? 'success' : syncResult.ok ? 'warning' : 'error'}`}>
                {!syncResult.ok ? (
                  <p className="sync-result-line">Erreur : {syncResult.message}</p>
                ) : (
                  <>
                    <p className="sync-result-line">
                      {syncResult.total === 0
                        ? 'Aucun produit local à synchroniser pour cette catégorie.'
                        : `${syncResult.total} produit${syncResult.total > 1 ? 's' : ''} traité${syncResult.total > 1 ? 's' : ''} — ${syncResult.created} créé${syncResult.created > 1 ? 's' : ''}, ${syncResult.updated} mis à jour`}
                    </p>
                    {syncResult.errors?.length > 0 && (
                      <ul className="sync-errors">
                        {syncResult.errors.slice(0, 5).map((e, i) => (
                          <li key={i}><strong>{e.sku}</strong> : {e.error}</li>
                        ))}
                        {syncResult.errors.length > 5 && (
                          <li>… et {syncResult.errors.length - 5} autre(s) erreur(s)</li>
                        )}
                      </ul>
                    )}
                  </>
                )}
              </div>
            )}
          </section>
        </article>
      </section>
    </main>
  )
}
