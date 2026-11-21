import { useCallback, useMemo, useState } from 'react'
import type { FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { adminApi } from '../../api/admin'
import type { Account } from '../../api/admin'
import type { Role } from '../../api/client'
import { useAuth } from '../../auth/context'
import { useErrorText } from '../../lib/errors'
import { useLoad } from '../../lib/useLoad'

interface Issued {
  name: string
  login: string
  password: string
}

export function UsersPage() {
  const { t } = useTranslation()
  const errorText = useErrorText()
  const { state } = useAuth()
  const selfId = state.status === 'signedIn' ? state.user.id : null
  const fetcher = useCallback(() => adminApi.accounts(), [])
  const { data: accounts, error: loadError, reload } = useLoad(fetcher)
  const [actionError, setError] = useState<string | null>(null)
  const [query, setQuery] = useState('')
  const [openId, setOpenId] = useState<number | null>(null)
  const [issued, setIssued] = useState<Issued | null>(null)
  const error = actionError ?? (loadError === null ? null : errorText(loadError))

  const shown = useMemo(() => {
    const needle = query.trim().toLocaleLowerCase()
    if (accounts === undefined || needle === '') return accounts ?? []
    return accounts.filter((a) => a.full_name.toLocaleLowerCase().includes(needle) || a.login.includes(needle))
  }, [accounts, query])

  /** Әрекетті орындап, тізімді жаңартады; қате болса көрсетеді. */
  async function run<T>(action: () => Promise<T>): Promise<T | null> {
    setError(null)
    try {
      const result = await action()
      await reload()
      return result
    } catch (caught) {
      setError(errorText(caught))
      return null
    }
  }

  function show(result: { user: Account; one_time_password: string } | null): boolean {
    if (result === null) return false
    setIssued({ name: result.user.full_name, login: result.user.login, password: result.one_time_password })
    setOpenId(null)
    window.scrollTo({ top: 0 })
    return true
  }

  if (accounts === undefined) {
    return error === null ? <p className="muted">{t('common.loading')}</p> : <p className="alert" role="alert">{error}</p>
  }

  return (
    <section className="page">
      <header className="page__head">
        <div>
          <h1 className="page__title">{t('users.title')}</h1>
          <p className="muted">{t('users.total', { count: accounts.length })}</p>
        </div>
        <Link className="btn btn--strong" to="/admin/import/teachers">{t('users.import')}</Link>
      </header>

      {issued !== null && (
        <section className="panel form secret" aria-label={t('users.passwordTitle', { name: issued.name })}>
          <h2 className="panel__title">{t('users.passwordTitle', { name: issued.name })}</h2>
          <dl className="secret__list">
            <div><dt>{t('users.login')}</dt><dd>{issued.login}</dd></div>
            <div><dt>{t('users.password')}</dt><dd>{issued.password}</dd></div>
          </dl>
          <p className="muted">{t('users.passwordNote')}</p>
          <button className="btn" type="button" onClick={() => setIssued(null)}>{t('users.hide')}</button>
        </section>
      )}

      {error !== null && <p className="alert" role="alert">{error}</p>}

      <label className="field">
        <span className="field__label">{t('users.search')}</span>
        <input className="field__input" type="search" value={query} onChange={(e) => setQuery(e.target.value)} />
      </label>

      {shown.length === 0 ? (
        <p className="muted">{t('users.notFound')}</p>
      ) : (
        <ul className="roster">
          {shown.map((account) => (
            <li key={account.id} className="roster__item">
              <div className="roster__row">
                <span className="roster__name">
                  {account.full_name}
                  <span className="roster__sub">
                    {account.login} · {t(account.role === 'admin' ? 'users.roleAdmin' : 'users.roleTeacher')}
                    {account.homeroom_classes.length > 0 && ` · ${t('users.homeroomOf', { classes: account.homeroom_classes.join(', ') })}`}
                  </span>
                </span>
                {!account.is_active && <span className="tag tag--off">{t('users.inactive')}</span>}
                {account.is_active && account.must_change_password && <span className="tag">{t('users.notEntered')}</span>}
                <button className="btn" type="button" aria-expanded={openId === account.id}
                  aria-label={t('users.actions', { name: account.full_name })}
                  onClick={() => setOpenId(openId === account.id ? null : account.id)}>
                  {t('common.edit')}
                </button>
              </div>
              {openId === account.id && (
                <AccountActions
                  account={account}
                  isSelf={account.id === selfId}
                  onRename={async (full_name) => (await run(() => adminApi.updateAccount(account.id, { full_name }))) !== null}
                  onToggle={async () => {
                    if (account.is_active && !window.confirm(t('users.deactivateConfirm', { name: account.full_name }))) return
                    await run(() => adminApi.updateAccount(account.id, { is_active: !account.is_active }))
                  }}
                  onReset={async () => {
                    if (!window.confirm(t('users.resetConfirm', { name: account.full_name }))) return
                    show(await run(() => adminApi.resetPassword(account.id)))
                  }}
                />
              )}
            </li>
          ))}
        </ul>
      )}

      <AddAccount onAdd={async (data) => show(await run(() => adminApi.createAccount(data)))} />
    </section>
  )
}

interface ActionsProps {
  account: Account
  isSelf: boolean
  onRename: (fullName: string) => Promise<boolean>
  onToggle: () => Promise<void>
  onReset: () => Promise<void>
}

function AccountActions({ account, isSelf, onRename, onToggle, onReset }: ActionsProps) {
  const { t } = useTranslation()
  const [name, setName] = useState(account.full_name)
  const [busy, setBusy] = useState(false)

  async function guard(action: () => Promise<unknown>) {
    setBusy(true)
    await action()
    setBusy(false)
  }

  function onSubmit(event: FormEvent) {
    event.preventDefault()
    void guard(() => onRename(name.trim()))
  }

  return (
    <div className="roster__actions">
      <form className="row row--end" onSubmit={onSubmit}>
        <label className="field field--grow">
          <span className="field__label">{t('users.name')}</span>
          <input className="field__input" value={name} maxLength={150} required onChange={(e) => setName(e.target.value)} />
        </label>
        <button className="btn" type="submit" disabled={busy || name.trim() === '' || name.trim() === account.full_name}>
          {t('common.save')}
        </button>
      </form>
      <div className="row">
        {account.is_active && (
          <button className="btn" type="button" disabled={busy} onClick={() => void guard(onReset)}>{t('users.resetPassword')}</button>
        )}
        {/* Завуч өзін өшіре алмайды: әйтпесе платформа әкімшісіз қалады. */}
        {!isSelf && (
          <button className={account.is_active ? 'btn btn--danger' : 'btn'} type="button" disabled={busy} onClick={() => void guard(onToggle)}>
            {t(account.is_active ? 'users.deactivate' : 'users.activate')}
          </button>
        )}
      </div>
    </div>
  )
}

interface NewAccount {
  last_name: string
  first_name: string
  patronymic: string | null
  role: Role
}

function AddAccount({ onAdd }: { onAdd: (data: NewAccount) => Promise<boolean> }) {
  const { t } = useTranslation()
  const [last, setLast] = useState('')
  const [first, setFirst] = useState('')
  const [patronymic, setPatronymic] = useState('')
  const [role, setRole] = useState<Role>('teacher')
  const [busy, setBusy] = useState(false)

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    setBusy(true)
    const added = await onAdd({
      last_name: last.trim(),
      first_name: first.trim(),
      patronymic: patronymic.trim() === '' ? null : patronymic.trim(),
      role,
    })
    if (added) {
      setLast('')
      setFirst('')
      setPatronymic('')
      setRole('teacher')
    }
    setBusy(false)
  }

  return (
    <form className="panel form" onSubmit={onSubmit}>
      <h2 className="panel__title">{t('users.addTitle')}</h2>
      <div className="row">
        <label className="field field--grow">
          <span className="field__label">{t('classPage.lastName')}</span>
          <input className="field__input" value={last} maxLength={60} required onChange={(e) => setLast(e.target.value)} />
        </label>
        <label className="field field--grow">
          <span className="field__label">{t('classPage.firstName')}</span>
          <input className="field__input" value={first} maxLength={60} required onChange={(e) => setFirst(e.target.value)} />
        </label>
        <label className="field field--grow">
          <span className="field__label">{t('classPage.patronymic')}</span>
          <input className="field__input" value={patronymic} maxLength={60} onChange={(e) => setPatronymic(e.target.value)} />
        </label>
        <label className="field">
          <span className="field__label">{t('users.role')}</span>
          <select className="field__input" value={role} onChange={(e) => setRole(e.target.value === 'admin' ? 'admin' : 'teacher')}>
            <option value="teacher">{t('users.roleTeacher')}</option>
            <option value="admin">{t('users.roleAdmin')}</option>
          </select>
        </label>
      </div>
      <button className="btn btn--strong" type="submit" disabled={busy}>{busy ? t('common.saving') : t('common.add')}</button>
    </form>
  )
}
