import type { Locale } from '../i18n'

export type Role = 'admin' | 'teacher'

export interface User {
  id: number
  full_name: string
  role: Role
  locale: Locale
  must_change_password: boolean
  /** Жетекшілік ететін сыныптардың атаулары, мысалы ["7Ә"]. Жетекші болмаса бос. */
  homeroom_classes: string[]
}

/** Сервер қайтаратын қате кодтары. Мәтінге аудармасы интерфейс жағында. */
export type ApiErrorCode =
  | 'invalid_credentials'
  | 'too_many_attempts'
  | 'wrong_current_password'
  | 'weak_password'
  | 'same_password'
  | 'unauthenticated'
  | 'network'
  | 'unknown'

export class ApiError extends Error {
  readonly code: ApiErrorCode
  readonly status: number

  constructor(code: ApiErrorCode, status: number) {
    super(code)
    this.name = 'ApiError'
    this.code = code
    this.status = status
  }
}

const KNOWN_CODES: readonly ApiErrorCode[] = [
  'invalid_credentials',
  'too_many_attempts',
  'wrong_current_password',
  'weak_password',
  'same_password',
  'unauthenticated',
]

function readCookie(name: string): string | null {
  const prefix = `${name}=`
  for (const part of document.cookie.split('; ')) {
    if (part.startsWith(prefix)) {
      return decodeURIComponent(part.slice(prefix.length))
    }
  }
  return null
}

async function ensureCsrfCookie(): Promise<void> {
  if (readCookie('XSRF-TOKEN') !== null) return
  await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' })
}

async function request<T>(method: string, path: string, body?: unknown): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  if (method !== 'GET') {
    await ensureCsrfCookie().catch(() => {
      throw new ApiError('network', 0)
    })
    const token = readCookie('XSRF-TOKEN')
    if (token !== null) headers['X-XSRF-TOKEN'] = token
    headers['Content-Type'] = 'application/json'
  }

  let response: Response
  try {
    response = await fetch(`/api${path}`, {
      method,
      headers,
      credentials: 'same-origin',
      body: body === undefined ? undefined : JSON.stringify(body),
    })
  } catch {
    throw new ApiError('network', 0)
  }

  if (response.status === 204) return undefined as T

  let payload: unknown = null
  try {
    payload = await response.json()
  } catch {
    payload = null
  }

  if (!response.ok) {
    const raw = (payload as { code?: unknown } | null)?.code
    const fallback: ApiErrorCode =
      response.status === 401 ? 'unauthenticated' : response.status === 429 ? 'too_many_attempts' : 'unknown'
    const code = KNOWN_CODES.find((known) => known === raw) ?? fallback
    throw new ApiError(code, response.status)
  }

  return payload as T
}

export const api = {
  me: () => request<{ user: User }>('GET', '/auth/me').then((r) => r.user),
  login: (login: string, password: string) =>
    request<{ user: User }>('POST', '/auth/login', { login, password }).then((r) => r.user),
  logout: () => request<void>('POST', '/auth/logout'),
  changePassword: (currentPassword: string, newPassword: string) =>
    request<{ user: User }>('POST', '/auth/password', {
      current_password: currentPassword,
      new_password: newPassword,
    }).then((r) => r.user),
  setLocale: (locale: Locale) => request<{ user: User }>('POST', '/auth/locale', { locale }).then((r) => r.user),
}
