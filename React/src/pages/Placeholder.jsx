// placeholder page component
export default function Placeholder({ route }) {
  const title = route || 'Page'

  return (
    <main className="content-grid placeholder-page">
      <section className="panel placeholder-card">
        <h2>{title}</h2>
        <p>Cette page est réservée pour une prochaine étape du front. Le router est déjà en place.</p>
      </section>
    </main>
  )
}
