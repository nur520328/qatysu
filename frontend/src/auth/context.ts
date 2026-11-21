import { createContext, useContext } from 'react'
import type { User } from '../api/client'
import type { Locale } from '../i18n'

export type AuthState =
  | { status: 'loading' }
  | { status: 'guest' }
  | { status: 'signedIn'; user: User }

export interface AuthContextValue {
  state: AuthState
  login: (login: string, password: string) => Promise<void>
  logout: () => Promise<void>
  changePassword: (currentPassword: string, newPassword: string) => Promise<void>
  /** Тілді ауыстырады; кірген пайдаланушы үшін таңдау серверде де сақталады. */
  changeLocale: (locale: Locale) => void
}

export const AuthContext = createContext<AuthContextValue | null>(null)

export function useAuth(): AuthContextValue {
  const value = useContext(AuthContext)
  if (value === null) throw new Error('useAuth must be used inside AuthProvider')
  return value
}
