import { useCallback, useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { api, ApiError } from '../api/client'
import type { User } from '../api/client'
import { isLocale, setLocale } from '../i18n'
import type { Locale } from '../i18n'
import { AuthContext } from './context'
import type { AuthState } from './context'

function applyUserLocale(user: User): void {
  if (isLocale(user.locale)) setLocale(user.locale)
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [state, setState] = useState<AuthState>({ status: 'loading' })

  useEffect(() => {
    let cancelled = false
    api
      .me()
      .then((user) => {
        if (cancelled) return
        applyUserLocale(user)
        setState({ status: 'signedIn', user })
      })
      .catch(() => {
        // 401 де, желі қатесі де кіру бетіне апарады: кірмейінше ештеңе көрсетілмейді.
        if (!cancelled) setState({ status: 'guest' })
      })
    return () => {
      cancelled = true
    }
  }, [])

  const login = useCallback(async (loginName: string, password: string) => {
    const user = await api.login(loginName, password)
    applyUserLocale(user)
    setState({ status: 'signedIn', user })
  }, [])

  const logout = useCallback(async () => {
    try {
      await api.logout()
    } catch (error) {
      // Сеанс серверде бітіп қойған болса да, интерфейс шығуы тиіс.
      if (!(error instanceof ApiError)) throw error
    }
    setState({ status: 'guest' })
  }, [])

  const changePassword = useCallback(async (currentPassword: string, newPassword: string) => {
    const user = await api.changePassword(currentPassword, newPassword)
    setState({ status: 'signedIn', user })
  }, [])

  const signedIn = state.status === 'signedIn'
  const changeLocale = useCallback(
    (locale: Locale) => {
      setLocale(locale)
      if (!signedIn) return
      api
        .setLocale(locale)
        .then((user) => setState({ status: 'signedIn', user }))
        .catch(() => {
          // Серверге сақталмаса да тіл осы құрылғыда ауысып тұрады.
        })
    },
    [signedIn],
  )

  const value = useMemo(
    () => ({ state, login, logout, changePassword, changeLocale }),
    [state, login, logout, changePassword, changeLocale],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
