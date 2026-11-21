import { useState } from 'react'
import type { FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { ApiError } from '../api/client'
import { useAuth } from '../auth/context'
import { isStrongPassword } from '../auth/password'
import { Shell } from '../components/Shell'

type PasswordErrorKey =
  | 'password.error.mismatch'
  | 'password.error.weak'
  | 'password.error.same'
  | 'password.error.wrongCurrent'
  | 'login.error.network'
  | 'common.errorGeneric'

function errorKey(error: unknown): PasswordErrorKey {
  if (error instanceof ApiError) {
    if (error.code === 'wrong_current_password') return 'password.error.wrongCurrent'
    if (error.code === 'weak_password') return 'password.error.weak'
    if (error.code === 'same_password') return 'password.error.same'
    if (error.code === 'network') return 'login.error.network'
  }
  return 'common.errorGeneric'
}

export function ChangePasswordPage() {
  const { t } = useTranslation()
  const { changePassword, logout } = useAuth()
  const [current, setCurrent] = useState('')
  const [next, setNext] = useState('')
  const [repeat, setRepeat] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<PasswordErrorKey | null>(null)

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (busy) return
    if (next !== repeat) return setError('password.error.mismatch')
    if (!isStrongPassword(next)) return setError('password.error.weak')
    if (next === current) return setError('password.error.same')

    setBusy(true)
    setError(null)
    try {
      await changePassword(current, next)
    } catch (caught) {
      setError(errorKey(caught))
      setBusy(false)
    }
  }

  return (
    <Shell
      actions={
        <button className="btn" type="button" onClick={() => void logout()}>
          {t('home.logout')}
        </button>
      }
    >
      <form className="card" onSubmit={onSubmit} noValidate>
        <h1 className="card__title">{t('password.title')}</h1>
        <p className="card__text">{t('password.intro')}</p>

        <label className="field">
          <span className="field__label">{t('password.current')}</span>
          <input
            className="field__input"
            type="password"
            autoComplete="current-password"
            value={current}
            onChange={(e) => setCurrent(e.target.value)}
            required
          />
        </label>

        {/* Ереже мәтіні өріс атауына қосылып кетпеуі үшін label-дің сыртында тұр. */}
        <div className="field">
          <label className="field__label" htmlFor="new-password">
            {t('password.new')}
          </label>
          <input
            id="new-password"
            className="field__input"
            type="password"
            autoComplete="new-password"
            aria-describedby="password-rule"
            value={next}
            onChange={(e) => setNext(e.target.value)}
            required
          />
          <span className="field__hint" id="password-rule">
            {t('password.rule')}
          </span>
        </div>

        <label className="field">
          <span className="field__label">{t('password.repeat')}</span>
          <input
            className="field__input"
            type="password"
            autoComplete="new-password"
            value={repeat}
            onChange={(e) => setRepeat(e.target.value)}
            required
          />
        </label>

        {error !== null && (
          <p className="alert" role="alert">
            {t(error)}
          </p>
        )}

        <button
          className="btn btn--primary"
          type="submit"
          disabled={busy || current === '' || next === '' || repeat === ''}
        >
          {t('password.submit')}
        </button>
      </form>
    </Shell>
  )
}
