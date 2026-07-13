// margin rules page component

// Demo rules removed - rules will be provided by backend
const marginRules = []

export default function MarginRules() {
  return (
    <main className="content-grid margin-rules-page">
      <section className="panel info-box">
        <div className="info-left">i</div>
        <div>
          <strong>Priorité d'application des règles</strong>
          <div className="info-sub">SKU spécifique → Marque → Catégorie → Règle globale</div>
        </div>
      </section>

      <section className="panel global-rule">
        <div className="panel-header">
          <div>
            <h2>Règle globale</h2>
            <p>Configuration par défaut appliquée à tous les produits</p>
          </div>
        </div>

        <div className="global-fields">
          <label>
            <div className="field-label">Marge globale par défaut (%)</div>
            <input defaultValue="10" />
          </label>

          <label>
            <div className="field-label">Type de prix psychologique</div>
            <select defaultValue="arrondi">
              <option value="arrondi">Arrondi à .99</option>
            </select>
          </label>

          <label>
            <div className="field-label">TVA appliquée (%)</div>
            <select defaultValue="20%">
              <option>20%</option>
            </select>
          </label>

          <div className="global-actions">
            <button className="save-button">Enregistrer la règle globale</button>
          </div>
        </div>
      </section>

      <section className="panel specific-rules">
        <div className="panel-header">
          <h2>Règles spécifiques</h2>
          <div className="panel-actions">
            <button className="secondary-action">Afficher le simulateur</button>
            <button className="primary-action">+ Nouvelle règle</button>
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
              {marginRules.map((r) => (
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
