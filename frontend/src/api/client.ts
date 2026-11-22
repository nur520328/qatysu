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

/**
 * Сервер қатесі. `code` — docs/api.md файлындағы машина оқитын код
 * (мысалы `invalid_credentials`, `class_exists`). Мәтінге аудармасы интерфейс жағында:
 * `errors.<code>` кілті, жоқ болса жалпы хабар.
 */
export class ApiError extends Error {
  readonly code: string
  readonly status: number
  /** Сервер қатемен бірге қайтарған қосымша мәлімет (мысалы, Telegram-ның жауабы). Аударылмайды. */
  readonly detail: string | null

  constructor(code: string, status: number, detail: string | null = null) {
    super(code)
    this.name = 'ApiError'
    this.code = code
    this.status = status
    this.detail = detail
  }
}

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

function fallbackCode(status: number): string {
  if (status === 401) return 'unauthenticated'
  if (status === 403) return 'forbidden'
  if (status === 404) return 'not_found'
  if (status === 413) return 'file_too_large'
  if (status === 419) return 'csrf_mismatch'
  if (status === 429) return 'too_many_attempts'
  return 'unknown'
}

async function send<T>(method: string, path: string, body: BodyInit | undefined, json: boolean): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  if (method !== 'GET') {
    await ensureCsrfCookie().catch(() => {
      throw new ApiError('network', 0)
    })
    const token = readCookie('XSRF-TOKEN')
    if (token !== null) headers['X-XSRF-TOKEN'] = token
    // Файл жібергенде Content-Type-ты браузер өзі қояды (шекарасымен бірге).
    if (json) headers['Content-Type'] = 'application/json'
  }

  let response: Response
  try {
    response = await fetch(`/api${path}`, { method, headers, credentials: 'same-origin', body })
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
    const detail = (payload as { detail?: unknown } | null)?.detail
    throw new ApiError(typeof raw === 'string' && raw !== '' ? raw : fallbackCode(response.status), response.status, typeof detail === 'string' ? detail : null)
  }

  return payload as T
}

export function request<T>(method: string, path: string, body?: unknown): Promise<T> {
  return send<T>(method, path, body === undefined ? undefined : JSON.stringify(body), true)
}

/** Файл жіберу (multipart/form-data). */
export function upload<T>(path: string, form: FormData): Promise<T> {
  return send<T>('POST', path, form, false)
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
