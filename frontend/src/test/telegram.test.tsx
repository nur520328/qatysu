import { act, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { User } from '../api/client'
import type { TelegramAdminState, TelegramState } from '../api/telegram'
import { App } from '../App'
import { AuthProvider } from '../auth/AuthContext'
import { setLocale } from '../i18n'
import { mockServer } from './mockServer'
import type { Handler } from './mockServer'

// Барлық дерек ойдан шығарылған; токен еш жерде қолданылмайды.
const admin: User = { id: 1, full_name: 'Сынақ Завуч', role: 'admin', locale: 'kk', must_change_password: false, homeroom_classes: [] }
const teacher: User = { ...admin, id: 5, full_name: 'Сынақ Мұғалім', role: 'teacher' }
const ok = (body: unknown): Handler => () => ({ status: 200, body })
const state = (extra: Partial<TelegramState> = {}): TelegramState => ({ ready: true, bot_username: 'qatysu_synaq_bot', linked: false, linked_at: null, ...extra })
const settings = { max_lessons_per_shift: 7, homeroom_edit_days: 14, pending_reason_days: 3, morning_shift_end: 790, afternoon_shift_end: 1120 }
const adminState = (extra: Partial<TelegramAdminState> = {}): TelegramAdminState => ({
  token_set: true, username: null, webhook_at: null, linked_users: 0, active_users: 131, sent_24h: 0, failed_24h: 0, ...extra,
})

function renderAt(path: string, user: User, routes: Record<string, Handler>) {
  const calls = mockServer({
    'GET /api/auth/me': ok({ user }),
    'GET /api/admin/backups': ok({ last_at: '2026-11-19T21:30:00+00:00', stale: false, backups: [] }),
    'GET /api/admin/settings': ok({ settings }),
    'GET /api/teacher/homeroom': ok({ date: '2026-11-20', classes: [] }),
    'GET /api/homeroom/pending': ok({ items: [] }),
    ...routes,
  })
  render(
    <MemoryRouter initialEntries={[path]}>
      <AuthProvider>
        <App />
      </AuthProvider>
    </MemoryRouter>,
  )
  return calls
}

beforeEach(() => setLocale('kk'))
afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
})

describe('Telegram-мен байланыстыру', () => {
  it('мұғалім сілтеме алады; Telegram-да «Start» басылғанын бет өзі байқайды; ажырата алады', async () => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
    const user = userEvent.setup()
    let linked = false
    const calls = renderAt('/', teacher, {
      'GET /api/telegram': () => ({ status: 200, body: state(linked ? { linked: true, linked_at: '2026-11-20T03:15:00+00:00' } : {}) }),
      'POST /api/telegram/link': () => ({ status: 201, body: { url: 'https://t.me/qatysu_synaq_bot?start=SYNAQKODSYNAQKODSYNAQKODSYNAQKOD12345678', expires_at: '2026-11-20T03:25:00+00:00' } }),
      'DELETE /api/telegram/link': () => {
        linked = false
        return { status: 200, body: state() }
      },
    })

    const panel = await screen.findByRole('region', { name: 'Telegram хабарлары' })
    expect(within(panel).getByText(/Хабарларда оқушылардың аты-жөні жазылмайды/)).toBeInTheDocument()
    await user.click(within(panel).getByRole('button', { name: 'Байланыстыру' }))

    const open = await within(panel).findByRole('link', { name: 'Telegram-ды ашу (@qatysu_synaq_bot)' })
    expect(open).toHaveAttribute('href', 'https://t.me/qatysu_synaq_bot?start=SYNAQKODSYNAQKODSYNAQKODSYNAQKOD12345678')
    expect(open).toHaveAttribute('target', '_blank')
    expect(within(panel).getByText(/Сілтемені басқа адамға жібермеңіз/)).toBeInTheDocument()

    // Пайдаланушы Telegram-да «Start» басты: келесі тексерісте бет өзі жаңарады.
    linked = true
    await act(async () => {
      vi.advanceTimersByTime(4000)
    })
    expect(await within(panel).findByText('Telegram байланыстырылған')).toBeInTheDocument()
    expect(within(panel).getByText(/20 қараша, 08:15/)).toBeInTheDocument()
    expect(within(panel).queryByRole('link', { name: /Telegram-ды ашу/ })).not.toBeInTheDocument()

    await user.click(within(panel).getByRole('button', { name: 'Ажырату' }))
    expect(await within(panel).findByRole('button', { name: 'Байланыстыру' })).toBeInTheDocument()
    expect(calls.map((c) => c.key)).toContain('DELETE /api/telegram/link')
  })

  it('бот қосылмаған болса, мұғалімге бөлік көрсетілмейді', async () => {
    renderAt('/', teacher, { 'GET /api/telegram': ok(state({ ready: false, bot_username: null })) })

    expect(await screen.findByRole('link', { name: 'Кешігу мен кетіп қалуды тіркеу' })).toBeInTheDocument()
    await waitFor(() => expect(screen.queryByRole('region', { name: 'Telegram хабарлары' })).not.toBeInTheDocument())
  })
})

describe('завуч ботты қосады', () => {
  it('токен жазылмаған болса, оны қайда жазу керегі айтылады, батырма жоқ', async () => {
    renderAt('/admin/settings', admin, {
      'GET /api/admin/telegram': ok(adminState({ token_set: false })),
      'GET /api/telegram': ok(state({ ready: false, bot_username: null })),
    })

    const panel = await screen.findByRole('region', { name: 'Telegram бот' })
    expect(within(panel).getByText(/\.env файлына TELEGRAM_BOT_TOKEN жолына жазу керек/)).toBeInTheDocument()
    expect(within(panel).queryByRole('button')).not.toBeInTheDocument()
  })

  it('«Ботты қосу» сәтті өтсе боттың атауы мен күйі көрінеді, жеке байланыстыру бөлігі бірден шығады', async () => {
    const user = userEvent.setup()
    let ready = false
    const calls = renderAt('/admin/settings', admin, {
      'GET /api/admin/telegram': ok(adminState()),
      'GET /api/telegram': () => ({ status: 200, body: ready ? state() : state({ ready: false, bot_username: null }) }),
      'POST /api/admin/telegram/setup': () => {
        ready = true
        return { status: 200, body: adminState({ username: 'qatysu_synaq_bot', webhook_at: '2026-11-20T03:10:00+00:00', linked_users: 2, sent_24h: 14, failed_24h: 1 }) }
      },
    })
    expect(screen.queryByRole('region', { name: 'Telegram хабарлары' })).not.toBeInTheDocument()

    const panel = await screen.findByRole('region', { name: 'Telegram бот' })
    expect(within(panel).getByText('Токен жазылған, бірақ бот әлі қосылмаған.')).toBeInTheDocument()
    await user.click(within(panel).getByRole('button', { name: 'Ботты қосу' }))

    expect(await within(panel).findByText('Бот қосылды.')).toBeInTheDocument()
    expect(within(panel).getByText('Бот: @qatysu_synaq_bot')).toBeInTheDocument()
    expect(within(panel).getByText('Соңғы тексерілген уақыты: 20 қараша, 08:10')).toBeInTheDocument()
    expect(within(panel).getByText('Байланыстырған қызметкер: 2 / 131')).toBeInTheDocument()
    expect(within(panel).getByText('Соңғы тәулікте жіберілген хабар: 14, жіберілмегені: 1')).toBeInTheDocument()
    expect(within(panel).getByRole('button', { name: 'Қайта тексеру' })).toBeInTheDocument()
    expect(calls.map((c) => c.key)).toContain('POST /api/admin/telegram/setup')
    // Бетті жаңартпай-ақ завуч өз аккаунтын байланыстыра алады.
    expect(await within(await screen.findByRole('region', { name: 'Telegram хабарлары' })).findByRole('button', { name: 'Байланыстыру' })).toBeInTheDocument()
  })

  it('қате токен мен Telegram-ның бас тартуы түсінікті айтылады', async () => {
    const user = userEvent.setup()
    let attempt = 0
    renderAt('/admin/settings', { ...admin, locale: 'ru' }, {
      'GET /api/admin/telegram': ok(adminState()),
      'GET /api/telegram': ok(state({ ready: false, bot_username: null })),
      'POST /api/admin/telegram/setup': () => {
        attempt += 1
        return attempt === 1
          ? { status: 422, body: { code: 'telegram_token_invalid', detail: null } }
          : { status: 422, body: { code: 'telegram_rejected', detail: 'Bad Request: bad webhook' } }
      },
    })

    const panel = await screen.findByRole('region', { name: 'Telegram-бот' })
    await user.click(within(panel).getByRole('button', { name: 'Включить бота' }))
    expect(await within(panel).findByRole('alert')).toHaveTextContent('Токен неверный: Telegram его не принял. Проверьте токен в файле .env.')

    await user.click(within(panel).getByRole('button', { name: 'Включить бота' }))
    await waitFor(() => expect(within(panel).getByRole('alert')).toHaveTextContent('Telegram отклонил запрос. Ответ Telegram: Bad Request: bad webhook'))
  })
})
