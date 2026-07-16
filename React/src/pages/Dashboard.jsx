// dashboard page component
import { useEffect, useState } from 'react'
import { requestJson } from '../lib/auth'
import CatalogueChart from '../components/CatalogueChart'

// Demo cards removed - Dashboard will display real indicators from backend
const quickCards = []

// Demo activity removed
const activityItems = []

export default function Dashboard() {
  const [evolution, setEvolution] = useState(undefined) // undefined=chargement, null=erreur

  useEffect(() => {
    let active = true
    // refresh=1 : contourne le cache API 5 min pour un dashboard à jour.
    requestJson('/api/dashboard/catalogue-evolution?days=30&refresh=1')
      .then((data) => { if (active) setEvolution(data) })
      .catch(() => { if (active) setEvolution(null) })
    return () => { active = false }
  }, [])

  const hasData = evolution && Array.isArray(evolution.series) && evolution.series.length > 0
  const totalNow = evolution?.total_now ?? null

  return (
    <main className="content-grid dashboard-page">
      <section className="summary-row" aria-label="Indicateurs principaux">
        {quickCards.map((card) => (
          <article className="summary-card" key={card.title}>
            <div className="card-header">
              <h2>{card.title}</h2>
              {card.tone === 'warning' ? <span className="warning-mark" aria-hidden="true">!</span> : null}
            </div>
            <div className="card-value">{card.value}</div>
            <div className={card.tone === 'warning' ? 'card-detail warning' : 'card-detail'}>{card.detail}</div>
          </article>
        ))}
      </section>

      <section className="panel chart-panel">
        <div className="panel-header">
          <div>
            <h2>Évolution du catalogue</h2>
            <p>
              {totalNow != null
                ? `${totalNow} produit${totalNow > 1 ? 's' : ''} au total · 30 derniers jours`
                : 'Nombre de produits importés dans le temps'}
            </p>
          </div>
        </div>

        {evolution === undefined ? (
          <div className="chart-placeholder" aria-hidden="true">
            <div className="chart-grid" />
            <div className="chart-empty-state"><span>Chargement…</span></div>
          </div>
        ) : !hasData || totalNow === 0 ? (
          <div className="chart-placeholder" aria-hidden="true">
            <div className="chart-grid" />
            <div className="chart-empty-state">
              <div className="empty-icon">∿</div>
              <strong>Aucun produit importé</strong>
              <span>Le graphe se remplira dès la première synchronisation.</span>
            </div>
          </div>
        ) : (
          <CatalogueChart series={evolution.series} />
        )}
      </section>

      <section className="panel activity-panel">
        <div className="panel-header">
          <div>
            <h2>Activité récente</h2>
            <p>Derniers événements de synchronisation</p>
          </div>
        </div>

        <div className="activity-list">
          {activityItems.map((item) => (
            <article className="activity-item" key={item.title}>
              <span className={`status-dot ${item.tone}`} aria-hidden="true" />
              <div>
                <strong>{item.title}</strong>
                <div className="activity-meta">{item.meta}</div>
                <div className="activity-time">{item.time}</div>
              </div>
            </article>
          ))}
        </div>
      </section>
    </main>
  )
}
