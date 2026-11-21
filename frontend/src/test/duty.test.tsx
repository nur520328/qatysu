import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { DutyState, Roster } from '../api/duty'
import type { User } from '../api/client'
import { App } from '../App'
import { AuthProvider } from '../auth/AuthContext'
import { setLocale } from '../i18n'
import { mockServer } from './mockServer'
import type { Handler } from './mockServer'

// Барлық дерек ойдан шығарылған.
const ok = (body: unknown): Handler => () => ({ status: 200, body })
const guest: Handler = () => ({ status: 401, body: { code: 'unauthenticated' } })
const noDuty: Handler = () => ({ status: 401, body: { code: 'duty_unauthenticated' } })

const round = { client_uuid: '11111111-1111-4111-8111-111111111111', revision: 2, absent_count: 1, saved_at: '2026-11-20T03:15:00+00:00' }

function state(overrides: Partial<DutyState> = {}): DutyState {
  return {
    date: '2026-11-20', shift: 'morning', expires_at: '2026-11-20T13:10:00+05:00', server_time: '2026-11-20T08:10:00+05:00', device_number: 1,
    classes: [
      { id: 11, name: '5А', grade: 5, student_count: 3, round: null },
      { id: 12, name: '5Ә', grade: 5, student_count: 25, round },
      { id: 91, name: '9А', grade: 9, student_count: 24, round: { ...round, absent_count: 0 } },
    ],
    ...overrides,
  }
}

function roster(overrides: Partial<Roster> = {}): Roster {
  return {
    class: { id: 11, name: '5А' },
    round: null,
    students: [
      { id: 1, last_name: 'Әлібеков', first_name: 'Бірінші', patronymic: null, status: 'present', locked: false },
      { id: 2, last_name: 'Сынақов', first_name: 'Екінші', patronymic: 'Үлгіұлы', status: 'present', locked: false },
      { id: 3, last_name: 'Үлгіев', first_name: 'Үшінші', patronymic: null, status: 'present', locked: false },
    ],
    ...overrides,
  }
}

function renderDuty(routes: Record<string, Handler>, path = '/kezekshi') {
  const calls = mockServer({ 'GET /api/auth/me': guest, ...routes })
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
afterEach(() => vi.restoreAllMocks())

describe('кезекшінің кіруі', () => {
  it('кіру бетінен кезекші бетіне сілтеме бар', async () => {
    renderDuty({}, '/')
    expect(await screen.findByRole('link', { name: 'Кезекшісіз бе? Кодпен кіру' })).toHaveAttribute('href', '/kezekshi')
  })

  it('код тек цифрдан тұрады; қате код түсінікті хабар береді, дұрысы сыныптарды ашады', async () => {
    let attempts = 0
    const calls = renderDuty({
      'GET /api/duty/session': noDuty,
      'POST /api/duty/login': () => (++attempts === 1 ? { status: 422, body: { code: 'invalid_duty_code' } } : { status: 200, body: state() }),
    })
    const user = userEvent.setup()

    const input = await screen.findByLabelText('Код')
    const button = screen.getByRole('button', { name: 'Кіру' })
    await user.type(input, '12a3')
    expect(input).toHaveValue('123')
    expect(button).toBeDisabled()
    await user.type(input, '4567')
    expect(input).toHaveValue('123456')

    await user.click(button)
    expect(await screen.findByRole('alert')).toHaveTextContent('Код қате не мерзімі өткен.')
    await user.click(button)

    expect(await screen.findByRole('heading', { name: 'Таңғы смена · 20 қараша' })).toBeInTheDocument()
    expect(screen.getByText('Белгілеу 13:10 дейін ашық · 1-планшет')).toBeInTheDocument()
    expect(screen.getByText('Аралынды: 2 / 3 сынып')).toBeInTheDocument()
    expect(calls.filter((c) => c.key === 'POST /api/duty/login').map((c) => c.body)).toEqual([{ code: '123456' }, { code: '123456' }])
  })

  it('бұрын кірген планшет бірден сыныптарды көрсетеді; күйлері ажыратылады', async () => {
    renderDuty({ 'GET /api/duty/session': ok(state()) })

    const fifth = await screen.findByRole('region', { name: '5-сыныптар' })
    expect(within(fifth).getByRole('button', { name: /5А.*аралынбаған.*3 оқушы/ })).toBeInTheDocument()
    expect(within(fifth).getByRole('button', { name: /5Ә.*1 оқушы жоқ/ })).toBeInTheDocument()
    expect(within(screen.getByRole('region', { name: '9-сыныптар' })).getByRole('button', { name: /9А.*бәрі бар/ })).toBeInTheDocument()
  })
})

describe('сыныпты белгілеу', () => {
  it('жоқ оқушы басылады, сақталады, сыныптар бетіне қайтады', async () => {
    const calls = renderDuty({
      'GET /api/duty/session': ok(state()),
      'GET /api/duty/classes/11': ok(roster()),
      'PUT /api/duty/classes/11/round': ok({ ...roster(), stored_as: 'round', kept_locked: [] }),
    })
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: /5А/ }))
    expect(await screen.findByRole('heading', { name: '5А сыныбы' })).toBeInTheDocument()
    expect(screen.getByText('Бар: 3 · Жоқ: 0')).toBeInTheDocument()

    const student = screen.getByRole('button', { name: /Сынақов Екінші/ })
    await user.click(student)
    expect(student).toHaveAttribute('aria-pressed', 'true')
    expect(student).toHaveTextContent('жоқ')
    await user.click(screen.getByRole('button', { name: /Үлгіев Үшінші/ }))
    await user.click(screen.getByRole('button', { name: /Үлгіев Үшінші/ })) // қате басылды, қайтарылды
    expect(screen.getByText('Бар: 2 · Жоқ: 1')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Сақтау' }))

    expect(await screen.findByText('5А сақталды.')).toBeInTheDocument()
    const body = calls.find((c) => c.key === 'PUT /api/duty/classes/11/round')?.body as Record<string, unknown>
    expect(body.absent_student_ids).toEqual([2])
    expect(body.revision).toBeNull()
    expect(body.client_uuid).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/)
  })

  it('сақтаудан кейінгі кешіккен жауап кезекшіні келесі сыныптан шығарып жібермейді', async () => {
    let release: (() => void) | null = null
    let sessions = 0
    mockServer({
      'GET /api/auth/me': guest,
      'GET /api/duty/classes/11': ok(roster()),
      'GET /api/duty/classes/12': ok(roster({ class: { id: 12, name: '5Ә' }, round })),
      'PUT /api/duty/classes/11/round': ok({ ...roster(), stored_as: 'round', kept_locked: [] }),
    })
    const base = globalThis.fetch
    vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      if (String(input) === '/api/duty/session') {
        // Сақтаудан кейінгі жаңарту сұранысы баяу желіде кешігеді.
        if (++sessions === 2) await new Promise<void>((resolve) => { release = resolve })
        return new Response(JSON.stringify(state()), { status: 200 })
      }
      return base(input, init)
    }))
    render(
      <MemoryRouter initialEntries={['/kezekshi']}>
        <AuthProvider>
          <App />
        </AuthProvider>
      </MemoryRouter>,
    )
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: /5А/ }))
    await user.click(await screen.findByRole('button', { name: 'Сақтау' }))
    await user.click(await screen.findByRole('button', { name: /5Ә/ }))
    expect(await screen.findByRole('heading', { name: '5Ә сыныбы' })).toBeInTheDocument()

    release!()
    await new Promise((resolve) => setTimeout(resolve, 20))
    expect(screen.getByRole('heading', { name: '5Ә сыныбы' })).toBeInTheDocument()
  })

  it('сақталған сынып сол нөмірі және нұсқасымен түзетіледі; мұғалім белгілеген оқушы құлыптаулы', async () => {
    const saved = roster({
      round,
      students: [
        { id: 1, last_name: 'Әлібеков', first_name: 'Бірінші', patronymic: null, status: 'absent', locked: false },
        { id: 2, last_name: 'Сынақов', first_name: 'Екінші', patronymic: null, status: 'absent', locked: true },
        { id: 3, last_name: 'Үлгіев', first_name: 'Үшінші', patronymic: null, status: 'present', locked: false },
      ],
    })
    const calls = renderDuty({
      'GET /api/duty/session': ok(state()),
      'GET /api/duty/classes/11': ok(saved),
      'PUT /api/duty/classes/11/round': ok({ ...saved, stored_as: 'round', kept_locked: [] }),
    })
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: /5А/ }))
    const locked = await screen.findByRole('button', { name: /Сынақов Екінші/ })
    expect(locked).toBeDisabled()
    expect(locked).toHaveTextContent('мұғалім белгілеген')

    await user.click(screen.getByRole('button', { name: /Әлібеков Бірінші/ }))
    await user.click(screen.getByRole('button', { name: 'Сақтау' }))

    await waitFor(() => expect(calls.find((c) => c.key.startsWith('PUT'))?.body).toMatchObject({ client_uuid: round.client_uuid, revision: 2, absent_student_ids: [2] }))
  })

  it('басқа планшет өзгерткен болса, тізім жаңартылып, хабар шығады', async () => {
    let loads = 0
    renderDuty({
      'GET /api/duty/session': ok(state()),
      'GET /api/duty/classes/11': () => ({
        status: 200,
        body: ++loads === 1 ? roster({ round }) : roster({ round: { ...round, revision: 3 }, students: roster().students.map((s) => (s.id === 3 ? { ...s, status: 'absent' as const } : s)) }),
      }),
      'PUT /api/duty/classes/11/round': () => ({ status: 409, body: { code: 'round_changed' } }),
    })
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: /5А/ }))
    await user.click(await screen.findByRole('button', { name: /Әлібеков Бірінші/ }))
    await user.click(screen.getByRole('button', { name: 'Сақтау' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('басқа планшет өзгертті')
    // Сервердегі күй көрсетіледі: кезекшінің сақталмаған белгісі емес.
    expect(screen.getByRole('button', { name: /Үлгіев Үшінші/ })).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByRole('button', { name: /Әлібеков Бірінші/ })).toHaveAttribute('aria-pressed', 'false')
  })

  it('сынып басқа планшетте белгіленіп қойса, кезекшіге түсіндіріледі', async () => {
    renderDuty({
      'GET /api/duty/session': ok(state()),
      'GET /api/duty/classes/11': ok(roster()),
      'PUT /api/duty/classes/11/round': ok({ ...roster({ round }), stored_as: 'conflict', kept_locked: [] }),
    })
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: /5А/ }))
    await user.click(await screen.findByRole('button', { name: 'Сақтау' }))

    expect(await screen.findByText(/5А басқа планшетте белгіленіп қойған/)).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: '5А сыныбы' })).toBeInTheDocument()
  })

  it('сақталмаған белгімен шығу растауды сұрайды', async () => {
    renderDuty({ 'GET /api/duty/session': ok(state()), 'GET /api/duty/classes/11': ok(roster()) })
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false)
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: /5А/ }))
    await user.click(await screen.findByRole('button', { name: /Сынақов Екінші/ }))
    await user.click(screen.getByRole('button', { name: 'Сыныптарға қайту' }))

    expect(confirm).toHaveBeenCalledWith('Белгілер сақталған жоқ. Сақтамай шығасыз ба?')
    expect(screen.getByRole('heading', { name: '5А сыныбы' })).toBeInTheDocument()
  })

  it('смена бітсе, планшет код бетіне қайтады', async () => {
    renderDuty({
      'GET /api/duty/session': ok(state()),
      'GET /api/duty/classes/11': ok(roster()),
      'PUT /api/duty/classes/11/round': () => ({ status: 401, body: { code: 'duty_code_ended' } }),
    })
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: /5А/ }))
    await user.click(await screen.findByRole('button', { name: 'Сақтау' }))

    expect(await screen.findByRole('heading', { name: 'Кезекші коды' })).toBeInTheDocument()
    expect(screen.getByRole('status')).toHaveTextContent('Смена аяқталды не код ауыстырылды.')
  })
})

describe('завуч: кезекші коды', () => {
  const admin: User = { id: 1, full_name: 'Сынақ Завуч', role: 'admin', locale: 'kk', must_change_password: false, homeroom_classes: [] }
  const shift = (name: 'morning' | 'afternoon', code: string | null) => ({
    shift: name, ends_at: name === 'morning' ? '2026-11-20T13:10:00+05:00' : '2026-11-20T18:40:00+05:00', ended: false,
    classes_total: name === 'morning' ? 25 : 19, classes_done: code === null ? 0 : 7,
    code: code === null ? null : { id: 5, code, issued_at: '2026-11-20T07:40:00+05:00', issued_by: 'Сынақ Завуч', devices: 2 },
  })
  const days = (morning: string | null) => ({
    days: [
      { date: '2026-11-20', is_today: true, shifts: [shift('morning', morning), shift('afternoon', null)] },
      { date: '2026-11-23', is_today: false, shifts: [shift('morning', null), shift('afternoon', null)] },
    ],
    login_blocked: morning !== null,
  })

  it('код көрініп тұрады, аралау барысы және ескерту шығады; жаңа код растаумен беріледі', async () => {
    let issued = false
    const calls = mockServer({
      'GET /api/auth/me': ok({ user: admin }),
      'GET /api/admin/backups': ok({ last_at: '2026-11-19T21:30:00+00:00', stale: false, backups: [] }),
      'GET /api/admin/duty-codes': () => ({ status: 200, body: days(issued ? '654321' : '123456') }),
      'POST /api/admin/duty-codes': () => { issued = true; return { status: 201, body: { code: '654321', shift: shift('morning', '654321') } } },
    })
    render(
      <MemoryRouter initialEntries={['/admin/duty']}>
        <AuthProvider>
          <App />
        </AuthProvider>
      </MemoryRouter>,
    )
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    const user = userEvent.setup()

    const today = await screen.findByRole('region', { name: 'Бүгін, 20 қараша' })
    const morning = within(today).getByRole('region', { name: 'Таңғы смена' })
    expect(within(morning).getByLabelText('Код')).toHaveTextContent('123 456')
    expect(within(morning).getByText('Аралынды: 7 / 25 сынып')).toBeInTheDocument()
    expect(within(morning).getByText(/2 планшет кірген/)).toBeInTheDocument()
    expect(screen.getByRole('alert')).toHaveTextContent('Кодты қате теру әрекеті тым көп болды')
    expect(within(within(today).getByRole('region', { name: 'Түстен кейінгі смена' })).getByRole('button', { name: 'Код беру' })).toBeInTheDocument()

    await user.click(within(morning).getByRole('button', { name: 'Жаңа код беру' }))
    expect(confirm).toHaveBeenCalledOnce()
    await waitFor(() => expect(within(screen.getByRole('region', { name: 'Бүгін, 20 қараша' })).getByLabelText('Код')).toHaveTextContent('654 321'))
    expect(calls.find((c) => c.key === 'POST /api/admin/duty-codes')?.body).toEqual({ date: '2026-11-20', shift: 'morning' })
  })
})
