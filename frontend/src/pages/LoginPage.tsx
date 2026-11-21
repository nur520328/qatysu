import { useState } from 'react'
import type { FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { ApiError } from '../api/client'
import { useAuth } from '../auth/context'
import { Shell } from '../components/Shell'

type LoginErrorKey = 'login.error.invalid' | 'login.error.locked' | 'login.error.network' | 'common.errorGeneric'

function errorKey(error: unknown): LoginErrorKey {
  if (error instanceof ApiError) {
    if (error.code === 'invalid_credentials') return 'login.error.invalid'
    if (error.code === 'too_many_attempts') return 'login.error.locked'
    if (error.code === 'network') return 'login.error.network'
  }
  return 'common.errorGeneric'
}

export function LoginPage() {
  const { t } = useTranslation()
  const { login } = useAuth()
  const [loginName, setLoginName] = useState('')
  const [password, setPassword] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<LoginErrorKey | null>(null)

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (busy) return
    setBusy(true)
    setError(null)
    try {
      await login(loginName.trim(), password)
    } catch (caught) {
      setError(errorKey(caught))
      setBusy(false)
    }
  }

  return (
    <Shell>
      <form className="card" onSubmit={onSubmit} noValidate>
        <h1 className="card__title">{t('login.title')}</h1>
        <p className="card__text">{t('login.subtitle')}</p>

        <label className="field">
          <span className="field__label">{t('login.loginLabel')}</span>
          <input
            className="field__input"
            name="login"
            autoComplete="username"
            autoCapitalize="none"
            spellCheck={false}
            value={loginName}
            onChange={(e) => setLoginName(e.target.value)}
            required
          />
        </label>

        <label className="field">
          <span className="field__label">{t('login.passwordLabel')}</span>
          <input
            className="field__input"
            name="password"
            type="password"
            autoComplete="current-password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required
          />
        </label>

        {error !== null && (
          <p className="alert" role="alert">
            {t(error)}
          </p>
        )}

        <button className="btn btn--primary" type="submit" disabled={busy || loginName.trim() === '' || password === ''}>
          {busy ? t('login.submitting') : t('login.submit')}
        </button>
      </form>
    </Shell>
  )
}
