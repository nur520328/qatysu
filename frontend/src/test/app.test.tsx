import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { User } from '../api/client'
import { App } from '../App'
import { AuthProvider } from '../auth/AuthContext'
import i18n, { setLocale } from '../i18n'
import { isStrongPassword } from '../auth/password'

const teacher: User = {
  id: 7,
  full_name: 'Сынақ Мұғалім',
  role: 'teacher',
  locale: 'kk',
  must_change_password: false,
  homeroom_classes: ['7Ә'],
}

type Handler = (init: RequestInit | undefined) => { status: number; body?: unknown }

/** fetch-ті жолы бойынша жауап беретін жалған сервермен алмастырады. */
function mockServer(routes: Record<string, Handler>) {
  const calls: { key: string; body: unknown }[] = []
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const key = `${init?.method ?? 'GET'} ${String(input)}`
      calls.push({ key, body: typeof init?.body === 'string' ? JSON.parse(init.body) : undefined })
      if (key === 'GET /sanctum/csrf-cookie') {
        document.cookie = 'XSRF-TOKEN=test-token'
        return new Response(null, { status: 204 })
      }
      const handler = routes[key]
      if (handler === undefined) throw new Error(`unexpected request: ${key}`)
      const { status, body } = handler(init)
      return new Response(body === undefined ? null : JSON.stringify(body), { status })
    }),
  )
  return calls
}

function renderApp() {
  return render(
    <AuthProvider>
      <App />
    </AuthProvider>,
  )
}

beforeEach(() => {
  setLocale('kk')
})

describe('кіру', () => {
  it('кірмеген адамға кіру беті көрсетіледі', async () => {
    mockServer({ 'GET /api/auth/me': () => ({ status: 401, body: { code: 'unauthenticated' } }) })
    renderApp()
    expect(await screen.findByRole('heading', { name: 'Кіру' })).toBeInTheDocument()
  })

  it('дұрыс логин-парольмен бастапқы бет ашылады, CSRF белгісі жіберіледі', async () => {
    const calls = mockServer({
      'GET /api/auth/me': () => ({ status: 401 }),
      'POST /api/auth/login': (init) => {
        expect(new Headers(init?.headers).get('X-XSRF-TOKEN')).toBe('test-token')
        return { status: 200, body: { user: teacher } }
      },
    })
    const user = userEvent.setup()
    renderApp()

    await user.type(await screen.findByLabelText('Логин'), '  mugalim7 ')
    await user.type(screen.getByLabelText('Пароль'), 'qupiya123')
    await user.click(screen.getByRole('button', { name: 'Кіру' }))

    expect(await screen.findByRole('heading', { name: 'Сәлеметсіз бе, Сынақ Мұғалім!' })).toBeInTheDocument()
    expect(screen.getByText('7Ә сынып жетекшісі')).toBeInTheDocument()
    expect(calls.find((c) => c.key === 'POST /api/auth/login')?.body).toEqual({
      login: 'mugalim7',
      password: 'qupiya123',
    })
  })

  it('қате пароль туралы хабар шығады', async () => {
    mockServer({
      'GET /api/auth/me': () => ({ status: 401 }),
      'POST /api/auth/login': () => ({ status: 422, body: { code: 'invalid_credentials' } }),
    })
    const user = userEvent.setup()
    renderApp()

    await user.type(await screen.findByLabelText('Логин'), 'mugalim7')
    await user.type(screen.getByLabelText('Пароль'), 'qate')
    await user.click(screen.getByRole('button', { name: 'Кіру' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Логин не пароль қате.')
  })

  it('тым көп әрекеттен кейін бөлек хабар шығады', async () => {
    mockServer({
      'GET /api/auth/me': () => ({ status: 401 }),
      'POST /api/auth/login': () => ({ status: 429 }),
    })
    const user = userEvent.setup()
    renderApp()

    await user.type(await screen.findByLabelText('Логин'), 'mugalim7')
    await user.type(screen.getByLabelText('Пароль'), 'qate')
    await user.click(screen.getByRole('button', { name: 'Кіру' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Қате әрекет тым көп')
  })
})

describe('бір реттік пароль', () => {
  it('ауыстырылмайынша бастапқы бет ашылмайды', async () => {
    mockServer({
      'GET /api/auth/me': () => ({ status: 200, body: { user: { ...teacher, must_change_password: true } } }),
      'POST /api/auth/password': () => ({ status: 200, body: { user: teacher } }),
    })
    const user = userEvent.setup()
    renderApp()

    expect(await screen.findByRole('heading', { name: 'Жаңа пароль қойыңыз' })).toBeInTheDocument()
    expect(screen.queryByText(/Сәлеметсіз бе/)).not.toBeInTheDocument()

    await user.type(screen.getByLabelText('Қазіргі пароль'), 'birrettik1')
    await user.type(screen.getByLabelText('Жаңа пароль'), 'zhanaparol9')
    await user.type(screen.getByLabelText('Жаңа парольді қайталаңыз'), 'zhanaparol9')
    await user.click(screen.getByRole('button', { name: 'Сақтау' }))

    expect(await screen.findByRole('heading', { name: 'Сәлеметсіз бе, Сынақ Мұғалім!' })).toBeInTheDocument()
  })

  it('сәйкес келмейтін парольдер серверге жіберілмейді', async () => {
    const calls = mockServer({
      'GET /api/auth/me': () => ({ status: 200, body: { user: { ...teacher, must_change_password: true } } }),
    })
    const user = userEvent.setup()
    renderApp()

    await user.type(await screen.findByLabelText('Қазіргі пароль'), 'birrettik1')
    await user.type(screen.getByLabelText('Жаңа пароль'), 'zhanaparol9')
    await user.type(screen.getByLabelText('Жаңа парольді қайталаңыз'), 'basqaparol9')
    await user.click(screen.getByRole('button', { name: 'Сақтау' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Екі жаңа пароль бірдей емес.')
    expect(calls.some((c) => c.key === 'POST /api/auth/password')).toBe(false)
  })

  it('пароль ережесі', () => {
    expect(isStrongPassword('zhanaparol9')).toBe(true)
    expect(isStrongPassword('құпиясөз2026')).toBe(true)
    expect(isStrongPassword('qysqa1')).toBe(false)
    expect(isStrongPassword('tekaripter')).toBe(false)
    expect(isStrongPassword('1234567890')).toBe(false)
  })
})

describe('тіл', () => {
  it('ауыстырғыш интерфейсті орысша мен қытайшаға ауыстырады және есте сақтайды', async () => {
    mockServer({ 'GET /api/auth/me': () => ({ status: 401 }) })
    const user = userEvent.setup()
    renderApp()

    await user.click(await screen.findByRole('button', { name: 'Русский' }))
    expect(screen.getByRole('heading', { name: 'Вход' })).toBeInTheDocument()
    expect(document.documentElement.lang).toBe('ru')

    await user.click(screen.getByRole('button', { name: '中文' }))
    expect(screen.getByRole('heading', { name: '登录' })).toBeInTheDocument()
    expect(window.localStorage.getItem('qatysu.locale')).toBe('zh')
  })

  it('кірген пайдаланушының тілі серверде сақталған мәнге ауысады', async () => {
    mockServer({ 'GET /api/auth/me': () => ({ status: 200, body: { user: { ...teacher, locale: 'ru' } } }) })
    renderApp()

    expect(await screen.findByRole('heading', { name: 'Здравствуйте, Сынақ Мұғалім!' })).toBeInTheDocument()
    await waitFor(() => expect(i18n.language).toBe('ru'))
  })
})
