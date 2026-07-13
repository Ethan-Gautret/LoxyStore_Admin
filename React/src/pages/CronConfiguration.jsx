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

// Frequency presets → cron expressions. "custom" leaves the cron field free.
const FREQUENCY_PRESETS = [
  { value: 'every_1h', label: 'Toutes les heures', cron: '0 * * * *' },
  { value: 'every_2h', label: 'Toutes les 2 heures', cron: '0 */2 * * *' },
  { value: 'every_4h', label: 'Toutes les 4 heures', cron: '0 */4 * * *' },
  { value: 'every_6h', label: 'Toutes les 6 heures', cron: '0 */6 * * *' },
  { value: 'daily', label: '1 fois par jour (2h00)', cron: '0 2 * * *' },
  { value: 'twice_daily', label: '2 fois par jour (2h, 14h)', cron: '0 2,14 * * *' },
  { value: 'custom', label: 'Personnalisé', cron: null },
]

function JobCard({ job, danger, busy, onChange, onSave, onToggle, onRun }) {
  const recent = Array.isArray(job.recent_runs) ? job.recent_runs : []

  function handleFrequency(value) {
    const preset = FREQUENCY_PRESETS.find((p) => p.value === value)
    onChange({ ...job, frequency: value, cron: preset && preset.cron ? preset.cron : job.cron })
  }

  return (
    <section className="panel cron-card">
      <div className="panel-header">
        <div>
          <h2>{job.label}</h2>
          <p>{job.description}</p>
        </div>
        <div className={`status-pill ${job.active ? 'success' : 'muted'}`}>{job.active ? 'Actif' : 'Inactif'}</div>
      </div>

      {danger && <div className="notice">Opération longue (import + push). Évitez les heures de pointe.</div>}

      <div className="cron-grid">
        <label className="config-field">
          <span>Fréquence</span>
          <select value={job.frequency || 'custom'} onChange={(e) => handleFrequency(e.target.value)}>
            {FREQUENCY_PRESETS.map((p) => (
              <option key={p.value} value={p.value}>{p.label}</option>
            ))}
          </select>
        </label>

        <label className="config-field">
          <span>Expression cron</span>
          <input
            value={job.cron || ''}
            onChange={(e) => onChange({ ...job, cron: e.target.value, frequency: 'custom' })}
          />
        </label>
      </div>

      <div className="cron-stats">
        <div className="stat-block">
          <div className="muted">Durée moyenne</div>
          <strong>{job.avg_duration || '—'}</strong>
        </div>
        <div className="stat-block">
          <div className="muted">Prochain déclenchement</div>
          <strong>{job.next_run_human || '—'}</strong>
        </div>
      </div>

      <div className="divider" />

      <div>
        <h3>Dernières exécutions automatiques</h3>
        <div className="runs-list">
          {recent.length === 0 && <div className="muted" style={{ padding: '6px 0' }}>Aucune exécution automatique pour l'instant.</div>}
          {recent.map((r) => (
            <div className="run-row" key={r.id}>
              <span className={`run-status ${r.tone === 'success' ? 'success' : 'error'}`}>{r.tone === 'success' ? '✓' : r.tone === 'warning' ? '!' : '✕'}</span>
              <span className="run-time">{r.date}</span>
              <span className="run-duration">{r.duration}</span>
              <span className="muted" style={{ marginLeft: 'auto' }}>{r.updated} maj · {r.errors} err</span>
            </div>
          ))}
        </div>
      </div>

      <div className="cron-actions">
        <button
          className={`primary-action manual-button ${danger ? 'danger' : ''}`}
          type="button"
          disabled={busy}
          onClick={onRun}
        >
          {busy ? '… En cours' : '▸ Lancer manuellement'}
        </button>
        <button className="secondary-action" type="button" onClick={onSave} disabled={busy}>Enregistrer</button>
        <button className="secondary-action deactivate" type="button" onClick={onToggle} disabled={busy}>
          {job.active ? 'Désactiver' : 'Activer'}
        </button>
      </div>
    </section>
  )
}

export default function CronConfiguration() {
  const [jobs, setJobs] = useState(null)
  const [advanced, setAdvanced] = useState({})
  const [hint, setHint] = useState('')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [notice, setNotice] = useState(null)
  const [busyJob, setBusyJob] = useState(null)

  function load() {
    setLoading(true)
    setError(null)
    requestJson('/api/cron?refresh=1')
      .then((payload) => {
        setJobs(payload.jobs || {})
        setAdvanced(payload.advanced || {})
        setHint(payload.scheduler_hint || '')
      })
      .catch((err) => setError(err.message || 'Chargement impossible.'))
      .finally(() => setLoading(false))
  }

  useEffect(() => { load() }, [])

  function updateJob(key, next) {
    setJobs((prev) => ({ ...prev, [key]: next }))
  }

  function flash(message) {
    setNotice(message)
    setTimeout(() => setNotice(null), 3500)
  }

  async function persist(partial) {
    const payload = await requestJson('/api/cron', { method: 'PUT', body: JSON.stringify(partial) })
    setJobs(payload.jobs || {})
    setAdvanced(payload.advanced || advanced)
    return payload
  }

  async function saveJob(key) {
    try {
      setBusyJob(key)
      const j = jobs[key]
      await persist({ [key]: { active: j.active, frequency: j.frequency, cron: j.cron } })
      flash('Configuration enregistrée.')
    } catch (err) {
      flash(err.message)
    } finally {
      setBusyJob(null)
    }
  }

  async function toggleJob(key) {
    try {
      setBusyJob(key)
      const nextActive = !jobs[key].active
      await persist({ [key]: { active: nextActive } })
      flash(nextActive ? 'Tâche activée.' : 'Tâche désactivée.')
    } catch (err) {
      flash(err.message)
    } finally {
      setBusyJob(null)
    }
  }

  async function runJob(key) {
    const isHeavy = key === 'full_catalog'
    const confirmMsg = isHeavy
      ? 'Lancer le Catalogue Complet maintenant ? Cette opération importe puis pousse tous les produits vers PrestaShop et peut durer plusieurs minutes.'
      : 'Lancer la synchronisation Prix & Stock maintenant ? Les produits des catégories mappées seront mis à jour dans PrestaShop.'
    if (!window.confirm(confirmMsg)) return

    try {
      setBusyJob(key)
      flash('Synchronisation en cours…')
      await requestJson(`/api/cron/${key}/run`, { method: 'POST' })
      flash('Synchronisation terminée.')
      load()
    } catch (err) {
      flash(err.message)
    } finally {
      setBusyJob(null)
    }
  }

  async function saveAdvanced() {
    try {
      setBusyJob('advanced')
      await persist({ advanced })
      flash('Paramètres enregistrés.')
    } catch (err) {
      flash(err.message)
    } finally {
      setBusyJob(null)
    }
  }

  if (loading) {
    return <main className="content-grid cron-page"><p className="sync-empty-state">Chargement de la configuration…</p></main>
  }

  if (error) {
    return <main className="content-grid cron-page"><p className="sync-empty-state" style={{ color: '#ff5b5b' }}>{error}</p></main>
  }

  return (
    <main className="content-grid cron-page">
      {hint && <div className="notice" style={{ gridColumn: '1 / -1' }}>⚙️ {hint}</div>}
      {notice && <div className="notice" style={{ gridColumn: '1 / -1', background: 'rgba(74,132,255,0.1)' }}>{notice}</div>}

      <JobCard
        job={jobs.prices_stock}
        busy={busyJob === 'prices_stock'}
        onChange={(next) => updateJob('prices_stock', next)}
        onSave={() => saveJob('prices_stock')}
        onToggle={() => toggleJob('prices_stock')}
        onRun={() => runJob('prices_stock')}
      />

      <JobCard
        job={jobs.full_catalog}
        danger
        busy={busyJob === 'full_catalog'}
        onChange={(next) => updateJob('full_catalog', next)}
        onSave={() => saveJob('full_catalog')}
        onToggle={() => toggleJob('full_catalog')}
        onRun={() => runJob('full_catalog')}
      />

      <section className="panel advanced-card" style={{ gridColumn: '1 / -1' }}>
        <div className="panel-header">
          <div>
            <h2>Paramètres avancés</h2>
            <p>Configuration technique des jobs de synchronisation</p>
          </div>
        </div>

        <div className="advanced-grid">
          <label className="config-field"><span>Taille des lots</span>
            <input type="number" value={advanced.batch_size ?? ''} onChange={(e) => setAdvanced({ ...advanced, batch_size: Number(e.target.value) })} /></label>
          <label className="config-field"><span>Délai entre lots (ms)</span>
            <input type="number" value={advanced.batch_delay_ms ?? ''} onChange={(e) => setAdvanced({ ...advanced, batch_delay_ms: Number(e.target.value) })} /></label>
          <label className="config-field"><span>Workers Redis</span>
            <input type="number" value={advanced.redis_workers ?? ''} onChange={(e) => setAdvanced({ ...advanced, redis_workers: Number(e.target.value) })} /></label>
          <label className="config-field"><span>Timeout job (sec)</span>
            <input type="number" value={advanced.job_timeout ?? ''} onChange={(e) => setAdvanced({ ...advanced, job_timeout: Number(e.target.value) })} /></label>
        </div>

        <div className="advanced-options">
          <label className="checkbox-row">
            <input type="checkbox" checked={!!advanced.retry} onChange={(e) => setAdvanced({ ...advanced, retry: e.target.checked })} />
            Retry automatique en cas d'erreur
            <input type="number" className="small-input" value={advanced.retry_attempts ?? ''} onChange={(e) => setAdvanced({ ...advanced, retry_attempts: Number(e.target.value) })} />
          </label>
          <label className="checkbox-row">
            <input type="checkbox" checked={!!advanced.notify_on_error} onChange={(e) => setAdvanced({ ...advanced, notify_on_error: e.target.checked })} />
            Notification email si erreur
            <input className="small-input" value={advanced.notify_email ?? ''} onChange={(e) => setAdvanced({ ...advanced, notify_email: e.target.value })} />
          </label>
        </div>

        <div className="save-row">
          <button className="save-button" type="button" onClick={saveAdvanced} disabled={busyJob === 'advanced'}>Enregistrer les paramètres</button>
        </div>
      </section>
    </main>
  )
}
