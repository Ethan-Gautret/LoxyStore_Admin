// Import Filters page component
export default function ImportFilters() {
  return (
    <main className="content-grid import-filters-page">
      <section className="panel filter-stock">
        <div className="panel-header">
          <div>
            <h2>Filtres de stock</h2>
            <p>Critères de stock pour l'import des produits</p>
          </div>
        </div>

        <div className="stock-row">
          <label className="slider-wrap">
            <input type="range" min="0" max="100" defaultValue="3" />
          </label>

          <label>
            <input className="small-input" defaultValue="3" />
          </label>

          <label className="select-wrap">
            <select defaultValue="disable">
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
            <p>Critères de prix d'achat pour l'import</p>
          </div>
        </div>

        <div className="price-row">
          <label>
            <div className="field-label">Prix achat minimum (€)</div>
            <input defaultValue="10" />
          </label>
          <label>
            <div className="field-label">Prix achat maximum (€)</div>
            <input defaultValue="100000" />
          </label>
          <label className="checkbox-row">
            <input type="checkbox" defaultChecked /> Exclure les produits sans prix
          </label>
        </div>
      </section>

      <section className="panel filter-keywords">
        <div className="panel-header">
          <div>
            <h2>Filtres par mots-clés</h2>
            <p>Exclusion de produits basée sur le contenu texte</p>
          </div>
        </div>

        <div className="keywords-row">
          <label>
            <div className="field-label">Exclure si le nom contient</div>
            <input placeholder="Ajouter un mot-clé..." />
          </label>
          <div className="chips">
            <span className="chip">reconditionné <button>x</button></span>
            <span className="chip">occasion <button>x</button></span>
            <span className="chip">refurbished <button>x</button></span>
            <span className="chip">used <button>x</button></span>
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
          <label className="attr">
            <input type="checkbox" defaultChecked />
            <div>
              <strong>EAN requis</strong>
              <div className="muted">Code-barres nécessaire</div>
            </div>
          </label>

          <label className="attr">
            <input type="checkbox" defaultChecked />
            <div>
              <strong>Poids requis</strong>
              <div className="muted">Pour calcul frais de port</div>
            </div>
          </label>

          <label className="attr">
            <input type="checkbox" />
            <div>
              <strong>Description requise</strong>
              <div className="muted">Texte de description présent</div>
            </div>
          </label>

          <label className="attr">
            <input type="checkbox" defaultChecked />
            <div>
              <strong>Image disponible</strong>
              <div className="muted">Au moins 1 image produit</div>
            </div>
          </label>
        </div>
      </section>

      <section className="panel overrides-category">
        <div className="panel-header">
          <div>
            <h2>Overrides par catégorie</h2>
            <p>Règles spécifiques pour certaines catégories</p>
          </div>
          <div>
            <button className="primary-action">+ Ajouter un override</button>
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
              <tr>
                <td>Serveurs</td>
                <td>2</td>
                <td>1000</td>
                <td>50000</td>
                <td>Modifier Suppprimer</td>
              </tr>
              <tr>
                <td>Stockage &gt; SSD</td>
                <td>10</td>
                <td>50</td>
                <td>500</td>
                <td>Modifier Supprimer</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div className="save-row">
          <button className="save-button">Sauvegarder tous les filtres</button>
        </div>
      </section>
    </main>
  )
}
