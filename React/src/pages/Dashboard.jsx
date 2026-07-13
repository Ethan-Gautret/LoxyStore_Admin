// dashboard page component

// Demo cards removed - Dashboard will display real indicators from backend
const quickCards = []

// Demo activity removed
const activityItems = []

export default function Dashboard() {
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
            <p>Zone réservée aux données de synchronisation à venir</p>
          </div>
        </div>

        <div className="chart-placeholder" aria-hidden="true">
          <div className="chart-grid" />
          <div className="chart-empty-state">
            <div className="empty-icon">∿</div>
            <strong>Aucune donnée disponible</strong>
            <span>Le graphe sera alimenté par le back plus tard.</span>
          </div>
        </div>
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
