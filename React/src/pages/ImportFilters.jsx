// Page Filtres d'import — réglages globaux + overrides par catégorie.
import { useEffect, useState } from 'react'
import { requestJson } from '../lib/auth'

const ATTRIBUTES = [
  { key: 'ean', label: 'EAN requis', hint: 'Code-barres nécessaire' },
  { key: 'weight', label: 'Poids requis', hint: 'Pour calcul frais de port' },
  { key: 'description', label: 'Description requise', hint: 'Texte de description présent' },
]

const emptyFilters = {
  min_stock: 1,
  min_price: '',
  max_price: '',
  exclude_keywords: [],
  required_attributes: [],
  stock_behaviour: 'disable',
}

export default function ImportFilters() {
  const [filters, setFilters] = useState(emptyFilters)
  const [categories, setCategories] = useState([])
  const [keywordInput, setKeywordInput] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState({ text: '', type: 'idle' })

  useEffect(() => {
    let active = true
    Promise.all([
      requestJson('/api/import-filters?refresh=1'),
      requestJson('/api/import-filters/category-overrides?refresh=1'),
    ])
      .then(([f, c]) => {
        if (!active) return
        const g = f.filters || {}
        setFilters({
          min_stock: g.min_stock ?? 1,
          min_price: g.min_price ?? '',
          max_price: g.max_price ?? '',
          exclude_keywords: Array.isArray(g.exclude_keywords) ? g.exclude_keywords : [],
          required_attributes: Array.isArray(g.required_attributes) ? g.required_attributes : [],
          stock_behaviour: g.stock_behaviour ?? 'disable',
        })
        setCategories((c.categories || []).map((cat) => ({
          ...cat,
          min_stock_override: cat.min_stock_override ?? '',
          min_price_override: cat.min_price_override ?? '',
          max_price_override: cat.max_price_override ?? '',
        })))
      })
      .catch(() => { if (active) setMessage({ text: 'Impossible de charger les filtres.', type: 'error' }) })
      .finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [])

  function setField(field, value) {
    setFilters((f) => ({ ...f, [field]: value }))
  }

  function toggleAttribute(key) {
    setFilters((f) => ({
      ...f,
      required_attributes: f.required_attributes.includes(key)
        ? f.required_attributes.filter((a) => a !== key)
        : [...f.required_attributes, key],
    }))
  }

  function addKeyword() {
    const kw = keywordInput.trim().toLowerCase()
    if (kw && !filters.exclude_keywords.includes(kw)) {
      setField('exclude_keywords', [...filters.exclude_keywords, kw])
    }
    setKeywordInput('')
  }

  function removeKeyword(kw) {
    setField('exclude_keywords', filters.exclude_keywords.filter((k) => k !== kw))
  }

  function setOverride(code, field, value) {
    setCategories((cats) => cats.map((c) => (c.tds_category === code ? { ...c, [field]: value } : c)))
  }

  function clearOverride(code) {
    setCategories((cats) => cats.map((c) => (c.tds_category === code
      ? { ...c, min_stock_override: '', min_price_override: '', max_price_override: '' }
      : c)))
  }

  const num = (v) => (v === '' || v === null || v === undefined ? null : Number(v))

  async function handleSave() {
    setSaving(true)
    setMessage({ text: '', type: 'idle' })
    try {
      await requestJson('/api/import-filters', {
        method: 'PUT',
        body: JSON.stringify({
          min_stock: Number(filters.min_stock) || 0,
          min_price: num(filters.min_price),
          max_price: num(filters.max_price),
          exclude_keywords: filters.exclude_keywords,
          required_attributes: filters.required_attributes,
          stock_behaviour: filters.stock_behaviour,
        }),
      })

      // Overrides par catégorie (un PUT par catégorie ; valeurs vides = effacées).
      await Promise.all(categories.map((c) => requestJson(`/api/import-filters/category-overrides/${encodeURIComponent(c.tds_category)}`, {
        method: 'PUT',
        body: JSON.stringify({
          min_stock_override: num(c.min_stock_override),
          min_price_override: num(c.min_price_override),
          max_price_override: num(c.max_price_override),
        }),
      })))

      setMessage({ text: 'Filtres enregistrés. Ils s\'appliqueront au prochain import (sync catalogue complet).', type: 'success' })
    } catch (err) {
      const v = err.payload?.errors ? Object.values(err.payload.errors).flat().join(' ') : ''
      setMessage({ text: v || err.message || 'Enregistrement impossible.', type: 'error' })
    } finally {
      setSaving(false)
    }
  }

  return (
    <main className="content-grid import-filters-page">
      <section className="panel info-box">
        <div className="info-left">i</div>
        <div>
          <strong>Les filtres s'appliquent à l'import</strong>
          <div className="info-sub">Un produit hors critères est exclu du catalogue (ou désactivé s'il est juste en rupture). Prise en compte au prochain import « catalogue complet ».</div>
        </div>
      </section>

      <section className="panel filter-stock">
        <div className="panel-header">
          <div>
            <h2>Filtres de stock</h2>
            <p>Stock minimum et comportement des produits en rupture</p>
          </div>
        </div>

        <div className="stock-row">
          <label className="slider-wrap">
            <input
              type="range" min="0" max="50"
              value={Math.min(50, Number(filters.min_stock) || 0)}
              onChange={(e) => setField('min_stock', Number(e.target.value))}
              disabled={loading}
            />
          </label>
          <label>
            <input
              className="small-input" type="number" min="0"
              value={filters.min_stock}
              onChange={(e) => setField('min_stock', e.target.value === '' ? '' : Number(e.target.value))}
              disabled={loading}
            />
          </label>
          <label className="select-wrap">
            <select value={filters.stock_behaviour} onChange={(e) => setField('stock_behaviour', e.target.value)} disabled={loading}>
              <option value="disable">Désactiver le produit</option>
              <option value="keep">Garder en stock</option>
            </select>
          </label>
        </div>
      </section>

      <section className="panel filter-price">
        <div className="panel-header">
          <div>
            <h2>Filtres de prix</h2>
            <p>Fourchette de prix d'achat (laisser vide = pas de limite)</p>
          </div>
        </div>

        <div className="price-row">
          <label>
            <div className="field-label">Prix achat minimum (€)</div>
            <input type="number" min="0" step="0.01" value={filters.min_price}
              onChange={(e) => setField('min_price', e.target.value)} disabled={loading} placeholder="—" />
          </label>
          <label>
            <div className="field-label">Prix achat maximum (€)</div>
            <input type="number" min="0" step="0.01" value={filters.max_price}
              onChange={(e) => setField('max_price', e.target.value)} disabled={loading} placeholder="—" />
          </label>
        </div>
      </section>

      <section className="panel filter-keywords">
        <div className="panel-header">
          <div>
            <h2>Filtres par mots-clés</h2>
            <p>Exclure un produit si son nom contient l'un de ces mots</p>
          </div>
        </div>

        <div className="keywords-row">
          <label>
            <div className="field-label">Exclure si le nom contient</div>
            <input
              value={keywordInput}
              onChange={(e) => setKeywordInput(e.target.value)}
              onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addKeyword() } }}
              placeholder="Ajouter un mot-clé puis Entrée…"
              disabled={loading}
            />
          </label>
          <div className="chips">
            {filters.exclude_keywords.length === 0 ? (
              <span className="muted">Aucun mot-clé exclu.</span>
            ) : filters.exclude_keywords.map((kw) => (
              <span className="chip" key={kw}>{kw} <button type="button" onClick={() => removeKeyword(kw)}>×</button></span>
            ))}
          </div>
        </div>
      </section>

      <section className="panel attributes-required">
        <div className="panel-header">
          <div>
            <h2>Attributs requis</h2>
            <p>Données obligatoires pour qu'un produit soit importé</p>
          </div>
        </div>

        <div className="attributes-grid">
          {ATTRIBUTES.map((a) => (
            <label className="attr" key={a.key}>
              <input
                type="checkbox"
                checked={filters.required_attributes.includes(a.key)}
                onChange={() => toggleAttribute(a.key)}
                disabled={loading}
              />
              <div>
                <strong>{a.label}</strong>
                <div className="muted">{a.hint}</div>
              </div>
            </label>
          ))}
        </div>
      </section>

      <section className="panel overrides-category">
        <div className="panel-header">
          <div>
            <h2>Overrides par catégorie</h2>
            <p>Surcharge du stock min et de la fourchette de prix pour une catégorie (laisser vide = valeur globale)</p>
          </div>
        </div>

        <div className="table-wrap">
          <table className="overrides-table">
            <thead>
              <tr>
                <th>Catégorie</th>
                <th>Stock min</th>
                <th>Prix min (€)</th>
                <th>Prix max (€)</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr><td colSpan="5" style={{ padding: '14px 0', color: '#65708a' }}>Chargement…</td></tr>
              ) : categories.length === 0 ? (
                <tr><td colSpan="5" style={{ padding: '14px 0', color: '#65708a' }}>Aucune catégorie mappée. Mappez des catégories pour définir des overrides.</td></tr>
              ) : categories.map((c) => (
                <tr key={c.tds_category}>
                  <td>{c.name}</td>
                  <td><input className="small-input" type="number" min="0" value={c.min_stock_override}
                    onChange={(e) => setOverride(c.tds_category, 'min_stock_override', e.target.value)} placeholder="global" /></td>
                  <td><input className="small-input" type="number" min="0" step="0.01" value={c.min_price_override}
                    onChange={(e) => setOverride(c.tds_category, 'min_price_override', e.target.value)} placeholder="global" /></td>
                  <td><input className="small-input" type="number" min="0" step="0.01" value={c.max_price_override}
                    onChange={(e) => setOverride(c.tds_category, 'max_price_override', e.target.value)} placeholder="global" /></td>
                  <td><button type="button" className="table-action ghost" onClick={() => clearOverride(c.tds_category)}>Vider</button></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {message.text && <p className={`api-status ${message.type}`}>{message.text}</p>}

        <div className="save-row">
          <button className="save-button" type="button" onClick={handleSave} disabled={loading || saving}>
            {saving ? 'Enregistrement…' : 'Sauvegarder tous les filtres'}
          </button>
        </div>
      </section>
    </main>
  )
}
