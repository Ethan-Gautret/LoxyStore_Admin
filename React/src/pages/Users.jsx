import { useCallback, useEffect, useState } from 'react'
import { requestJson } from '../lib/auth'

const emptyForm = { name: '', email: '', password: '' }

export default function Users({ currentUser }) {
  const [users, setUsers] = useState([])
  const [loading, setLoading] = useState(true)
  const [message, setMessage] = useState({ text: '', type: 'idle' })

  // Formulaire de création
  const [createForm, setCreateForm] = useState(emptyForm)
  const [creating, setCreating] = useState(false)

  // Édition inline
  const [editingId, setEditingId] = useState(null)
  const [editForm, setEditForm] = useState(emptyForm)
  const [saving, setSaving] = useState(false)

  function notify(text, type = 'success') {
    setMessage({ text, type })
  }

  // Pas de setLoading(true) synchrone ici : l'état initial vaut déjà true, et les
  // rechargements après mutation mettent simplement la liste à jour.
  const loadUsers = useCallback(async () => {
    try {
      // refresh=1 : contourne le cache API 5 min pour toujours voir la liste à jour.
      const data = await requestJson('/api/users?refresh=1')
      setUsers(data.users || [])
    } catch (err) {
      notify(err.message || 'Impossible de charger les utilisateurs.', 'error')
    } finally {
      setLoading(false)
    }
  }, [])

  // Chargement initial : chaîne .then (setState dans un callback) pour rester
  // aligné sur le reste du projet et éviter un setState synchrone dans l'effet.
  useEffect(() => {
    let active = true
    requestJson('/api/users?refresh=1')
      .then((data) => { if (active) setUsers(data.users || []) })
      .catch((err) => { if (active) notify(err.message || 'Impossible de charger les utilisateurs.', 'error') })
      .finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [])

  async function handleCreate(event) {
    event.preventDefault()
    setCreating(true)
    setMessage({ text: '', type: 'idle' })
    try {
      await requestJson('/api/users', {
        method: 'POST',
        body: JSON.stringify(createForm),
      })
      setCreateForm(emptyForm)
      notify('Utilisateur créé.')
      await loadUsers()
    } catch (err) {
      const validation = err.payload?.errors ? Object.values(err.payload.errors).flat().join(' ') : ''
      notify(validation || err.message || 'Création impossible.', 'error')
    } finally {
      setCreating(false)
    }
  }

  function startEdit(user) {
    setEditingId(user.id)
    setEditForm({ name: user.name, email: user.email, password: '' })
    setMessage({ text: '', type: 'idle' })
  }

  function cancelEdit() {
    setEditingId(null)
    setEditForm(emptyForm)
  }

  async function handleUpdate(userId) {
    setSaving(true)
    setMessage({ text: '', type: 'idle' })
    try {
      const body = { name: editForm.name, email: editForm.email }
      // Mot de passe vide = inchangé.
      if (editForm.password) body.password = editForm.password
      await requestJson(`/api/users/${userId}`, {
        method: 'PUT',
        body: JSON.stringify(body),
      })
      cancelEdit()
      notify('Utilisateur mis à jour.')
      await loadUsers()
    } catch (err) {
      const validation = err.payload?.errors ? Object.values(err.payload.errors).flat().join(' ') : ''
      notify(validation || err.message || 'Mise à jour impossible.', 'error')
    } finally {
      setSaving(false)
    }
  }

  async function handleDelete(user) {
    if (!window.confirm(`Supprimer l'utilisateur ${user.name} (${user.email}) ?`)) return
    setMessage({ text: '', type: 'idle' })
    try {
      await requestJson(`/api/users/${user.id}`, { method: 'DELETE' })
      notify('Utilisateur supprimé.')
      await loadUsers()
    } catch (err) {
      notify(err.message || 'Suppression impossible.', 'error')
    }
  }

  return (
    <main className="content-grid">
      <section className="panel config-card">
        <div className="panel-header">
          <div>
            <h2>Créer un utilisateur</h2>
            <p>Les comptes créés ici peuvent se connecter au back-office.</p>
          </div>
        </div>

        <form className="config-grid" onSubmit={handleCreate}>
          <label className="config-field">
            <span>Nom</span>
            <input
              type="text"
              value={createForm.name}
              onChange={(e) => setCreateForm((f) => ({ ...f, name: e.target.value }))}
            />
          </label>

          <label className="config-field">
            <span>Email</span>
            <input
              type="email"
              value={createForm.email}
              onChange={(e) => setCreateForm((f) => ({ ...f, email: e.target.value }))}
            />
          </label>

          <label className="config-field">
            <span>Mot de passe</span>
            <input
              type="password"
              value={createForm.password}
              onChange={(e) => setCreateForm((f) => ({ ...f, password: e.target.value }))}
              placeholder="Min. 8 caractères"
            />
          </label>

          <div className="config-save-row" style={{ alignSelf: 'end' }}>
            <button className="config-main-button" type="submit" disabled={creating}>
              {creating ? 'Création…' : 'Ajouter'}
            </button>
          </div>
        </form>

        {message.text && <p className={`api-status ${message.type}`}>{message.text}</p>}
      </section>

      <section className="panel config-card">
        <div className="panel-header">
          <div>
            <h2>Utilisateurs</h2>
            <p>{users.length} compte{users.length > 1 ? 's' : ''}</p>
          </div>
        </div>

        {loading ? (
          <p className="api-status idle">Chargement…</p>
        ) : (
          <table className="data-table">
            <thead>
              <tr>
                <th>Nom</th>
                <th>Email</th>
                <th style={{ textAlign: 'right' }}>Actions</th>
              </tr>
            </thead>
            <tbody>
              {users.map((user) => {
                const isEditing = editingId === user.id
                const isSelf = currentUser && currentUser.id === user.id
                return (
                  <tr key={user.id}>
                    <td>
                      {isEditing ? (
                        <input
                          type="text"
                          value={editForm.name}
                          onChange={(e) => setEditForm((f) => ({ ...f, name: e.target.value }))}
                        />
                      ) : (
                        <>
                          {user.name}
                          {isSelf && <span className="advanced-pill" style={{ marginLeft: 8 }}>vous</span>}
                        </>
                      )}
                    </td>
                    <td>
                      {isEditing ? (
                        <>
                          <input
                            type="email"
                            value={editForm.email}
                            onChange={(e) => setEditForm((f) => ({ ...f, email: e.target.value }))}
                          />
                          <input
                            type="password"
                            value={editForm.password}
                            onChange={(e) => setEditForm((f) => ({ ...f, password: e.target.value }))}
                            placeholder="Nouveau mot de passe (optionnel)"
                            style={{ marginTop: 6 }}
                          />
                        </>
                      ) : (
                        user.email
                      )}
                    </td>
                    <td style={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
                      {isEditing ? (
                        <>
                          <button className="table-action" type="button" onClick={() => handleUpdate(user.id)} disabled={saving}>
                            {saving ? '…' : 'Enregistrer'}
                          </button>
                          <button className="table-action ghost" type="button" onClick={cancelEdit} disabled={saving}>
                            Annuler
                          </button>
                        </>
                      ) : (
                        <>
                          <button className="table-action" type="button" onClick={() => startEdit(user)}>
                            Modifier
                          </button>
                          <button
                            className="table-action danger"
                            type="button"
                            onClick={() => handleDelete(user)}
                            disabled={isSelf}
                            title={isSelf ? 'Vous ne pouvez pas supprimer votre propre compte' : ''}
                          >
                            Supprimer
                          </button>
                        </>
                      )}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        )}
      </section>
    </main>
  )
}
