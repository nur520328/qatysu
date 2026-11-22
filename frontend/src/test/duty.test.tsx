import 'fake-indexeddb/auto'
import { IDBFactory } from 'fake-indexeddb'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { DutyState, Roster } from '../api/duty'
import type { User } from '../api/client'
import { App } from '../App'
import { AuthProvider } from '../auth/AuthContext'
import { dutyDb } from '../duty/db'
import { setLocale } from '../i18n'
import { mockServer } from './mockServer'
import type { Handler } from './mockServer'

// Барлық дерек ойдан шығарылған.
const ok = (body: unknown): Handler => () => ({ status: 200, body })
const guest: Handler = () => ({ status: 401, body: { code: 'unauthenticated' } })
const noDuty: Handler = () => ({ status: 401, body: { code: 'duty_unauthenticated' } })

const round = { client_uuid: '11111111-1111-4111-8111-111111111111', revision: 2, absent_count: 1, saved_at: '2026-11-20T03:15:00+00:00', received_late: false }

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

beforeEach(() => {
  setLocale('kk')
  globalThis.indexedDB = new IDBFactory()
})
afterEach(() => vi.restoreAllMocks())

/** Сервердегі тізімдер: 5А аралынбаған, 5Ә-де бір оқушы жоқ, 9А түгел бар. */
function rosters(): Roster[] {
  return [
    roster(),
    roster({ class: { id: 12, name: '5Ә' }, round, students: roster().students.map((s) => ({ ...s, id: s.id + 20, status: s.id === 2 ? ('absent' as const) : s.status })) }),
    roster({ class: { id: 91, name: '9А' }, round: { ...round, absent_count: 0 }, students: roster().students.map((s) => ({ ...s, id: s.id + 90 })) }),
  ]
}

const base = (): Record<string, Handler> => ({ 'GET /api/duty/session': ok(state()), 'GET /api/duty/rosters': ok({ rosters: rosters() }) })
const savedAs = (data: Roster, stored: 'round' | 'conflict' = 'round'): Handler => ok({ ...data, stored_as: stored, kept_locked: [] })

describe('кезекшінің кіруі', () => {
  it('кіру бетінен кезекші бетіне сілтеме бар', async () => {
    renderDuty({}, '/')
    expect(await screen.findByRole('link', { name: 'Кезекшісіз бе? Кодпен кіру' })).toHaveAttribute('href', '/kezekshi')
  })

  it('код тек цифрдан тұрады; қате код түсінікті хабар береді, дұрысы сыныптарды ашады', async () => {
    let attempts = 0
    const calls = renderDuty({
      'GET /api/duty/session': noDuty,
      'GET /api/duty/rosters': ok({ rosters: rosters() }),
      'POST /api/duty/login': () => (++attempts === 1 ? { status: 422, body: { code: 'invalid_duty_code' } } : { status: 200, body: state() }),
    })
    const user = userEvent.setup()

    const input = await screen.findByLabelText('Код')
    // Кезекшінің планшетінде аралаудан алаңдататын сілтеме болмауы тиіс.
    expect(screen.queryByRole('link', { name: 'Платформа туралы' })).not.toBeInTheDocument()
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
    renderDuty(base())

    const fifth = await screen.findByRole('region', { name: '5-сыныптар' })
    expect(within(fifth).getByRole('button', { name: /5А.*аралынбаған.*3 оқушы/ })).toBeInTheDocument()
    expect(within(fifth).getByRole('button', { name: /5Ә.*1 оқушы жоқ/ })).toBeInTheDocument()
    expect(within(screen.getByRole('region', { name: '9-сыныптар' })).getByRole('button', { name: /9А.*бәрі бар/ })).toBeInTheDocument()
    expect(screen.getByRole('status')).toHaveTextContent('Барлық белгі серверге жетті.')
  })
})

describe('сыныпты белгілеу', () => {
  it('жоқ оқушы басылады, сақталады, серверге жетеді', async () => {
    const calls = renderDuty({
      ...base(),
      'PUT /api/duty/classes/11/round': (_init, body) => ({
        status: 200,
        body: {
          ...roster({ round: { ...round, client_uuid: (body as { client_uuid: string }).client_uuid, revision: 1 }, students: roster().students.map((s) => (s.id === 2 ? { ...s, status: 'absent' as const } : s)) }),
          stored_as: 'round', kept_locked: [],
        },
      }),
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

    expect(await screen.findByText('5А планшетте сақталды.')).toBeInTheDocument()
    expect(await screen.findByRole('button', { name: /5А.*1 оқушы жоқ.*3 оқушы/ })).toBeInTheDocument()
    expect(screen.getByText('Аралынды: 3 / 3 сынып Барлық сынып аралынды.')).toBeInTheDocument()
    const body = calls.find((c) => c.key === 'PUT /api/duty/classes/11/round')?.body as Record<string, unknown>
    expect(body.absent_student_ids).toEqual([2])
    expect(body.revision).toBeNull()
    expect(body.client_uuid).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/)
  })

  it('интернет жоқта сақталған сынып планшетте қалады, анық көрсетіледі және кейін жіберіледі', async () => {
    let offline = true
    renderDuty({
      ...base(),
      'PUT /api/duty/classes/11/round': () => {
        if (offline) throw new TypeError('offline')
        return { status: 200, body: { ...roster({ round: { ...round, revision: 1 } }), stored_as: 'round', kept_locked: [] } }
      },
    })
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: /5А/ }))
    await user.click(await screen.findByRole('button', { name: /Сынақов Екінші/ }))
    await user.click(screen.getByRole('button', { name: 'Сақтау' }))

    // Кезекші бірден сыныптарға қайтады: интернетті күтпейді.
    expect(await screen.findByText('1 сынып серверге жіберілмеді')).toBeInTheDocument()
    expect(await screen.findByText('Планшетті өшірмеңіз. Интернет шыққанда өзі жіберіледі.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /5А.*1 оқушы жоқ.*жіберілмеген/ })).toBeInTheDocument()

    // Жіберілмеген дерекпен шығуға болмайды.
    const confirm = vi.spyOn(window, 'confirm')
    await user.click(screen.getByRole('button', { name: 'Шығу' }))
    expect(confirm).not.toHaveBeenCalled()
    expect(screen.getByRole('alert')).toHaveTextContent('Жіберілмеген сынып бар.')

    // Қайта ашқанда кезекші өз белгілерін көреді.
    await user.click(screen.getByRole('button', { name: /5А/ }))
    expect(await screen.findByRole('button', { name: /Сынақов Екінші/ })).toHaveAttribute('aria-pressed', 'true')
    await user.click(screen.getByRole('button', { name: 'Сыныптарға қайту' }))

    offline = false
    await user.click(await screen.findByRole('button', { name: 'Қазір жіберу' }))
    expect(await screen.findByText('Барлық белгі серверге жетті.')).toBeInTheDocument()
    expect(screen.queryByText(/серверге жіберілмеді/)).not.toBeInTheDocument()
  })

  it('сақталған сынып сол нөмірі және нұсқасымен түзетіледі; мұғалім белгілеген оқушы құлыптаулы', async () => {
    const list = rosters()
    list[1].students[0] = { ...list[1].students[0], status: 'absent' }
    list[1].students[1] = { ...list[1].students[1], locked: true }
    const calls = renderDuty({
      'GET /api/duty/session': ok(state()),
      'GET /api/duty/rosters': ok({ rosters: list }),
      'PUT /api/duty/classes/12/round': savedAs({ ...list[1], round: { ...round, revision: 3 } }),
    })
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: /5Ә/ }))
    const locked = await screen.findByRole('button', { name: /Сынақов Екінші/ })
    expect(locked).toBeDisabled()
    expect(locked).toHaveTextContent('мұғалім белгілеген')

    await user.click(screen.getByRole('button', { name: /Әлібеков Бірінші/ }))
    await user.click(screen.getByRole('button', { name: 'Сақтау' }))

    await waitFor(() => expect(calls.find((c) => c.key.startsWith('PUT'))?.body).toMatchObject({ client_uuid: round.client_uuid, revision: 2, absent_student_ids: [22] }))
  })

  it('басқа планшет өзгерткен сынып «қайта тексеріңіз» деп белгіленіп, сервердегі күйі көрсетіледі', async () => {
    renderDuty({
      ...base(),
      'PUT /api/duty/classes/12/round': () => ({ status: 409, body: { code: 'round_changed' } }),
      'GET /api/duty/classes/12': ok({ ...rosters()[1], round: { ...round, revision: 3 }, students: rosters()[1].students.map((s) => ({ ...s, status: s.id === 23 ? ('absent' as const) : ('present' as const) })) }),
    })
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: /5Ә/ }))
    await user.click(await screen.findByRole('button', { name: /Әлібеков Бірінші/ }))
    await user.click(screen.getByRole('button', { name: 'Сақтау' }))

    await user.click(await screen.findByRole('button', { name: /5Ә.*қайта тексеріңіз/ }))
    expect(await screen.findByRole('alert')).toHaveTextContent('басқа планшет өзгерткен')
    // Сервердегі күй: кезекшінің жазылмаған өзгерісі емес.
    expect(screen.getByRole('button', { name: /Үлгіев Үшінші/ })).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByRole('button', { name: /Әлібеков Бірінші/ })).toHaveAttribute('aria-pressed', 'false')
  })

  it('сынып басқа планшетте белгіленіп қойса, кезекшіге түсіндіріледі', async () => {
    renderDuty({ ...base(), 'PUT /api/duty/classes/11/round': savedAs(roster({ round }), 'conflict') })
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: /5А/ }))
    await user.click(await screen.findByRole('button', { name: 'Сақтау' }))

    await user.click(await screen.findByRole('button', { name: /5А.*басқа планшет белгілеген/ }))
    expect(await screen.findByText(/5А басқа планшетте белгіленіп қойған/)).toBeInTheDocument()
  })

  it('сақталмаған белгімен шығу растауды сұрайды', async () => {
    renderDuty(base())
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false)
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: /5А/ }))
    await user.click(await screen.findByRole('button', { name: /Сынақов Екінші/ }))
    await user.click(screen.getByRole('button', { name: 'Сыныптарға қайту' }))

    expect(confirm).toHaveBeenCalledWith('Белгілер сақталған жоқ. Сақтамай шығасыз ба?')
    expect(screen.getByRole('heading', { name: '5А сыныбы' })).toBeInTheDocument()
  })

  it('код жойылған болса, жіберілмеген белгілер жоғалмайды: завучқа көрсету сұралады', async () => {
    renderDuty({ ...base(), 'PUT /api/duty/classes/11/round': () => ({ status: 401, body: { code: 'duty_code_ended' } }) })
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: /5А/ }))
    await user.click(await screen.findByRole('button', { name: /Сынақов Екінші/ }))
    await user.click(screen.getByRole('button', { name: 'Сақтау' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('1 сыныптың белгісі серверге қабылданбады')
    expect(screen.getByText(/Смена аяқталды, белгілеу жабық\./)).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: /5А.*1 оқушы жоқ.*қабылданбады/ }))
    expect(await screen.findByText('Смена аяқталды: белгілерді өзгертуге болмайды.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Сынақов Екінші/ })).toHaveAttribute('aria-pressed', 'true')
    expect(screen.queryByRole('button', { name: 'Сақтау' })).not.toBeInTheDocument()
  })

  it('смена біткенде бәрі серверде болса, планшет тазаланып, код бетіне қайтады', async () => {
    // Планшетте кешегі смена қалған.
    await dutyDb.putRosters(rosters())
    await dutyDb.setSession({ state: state(), clock_offset: 0 })
    renderDuty({ 'GET /api/duty/session': () => ({ status: 401, body: { code: 'duty_code_ended' } }) })

    expect(await screen.findByRole('heading', { name: 'Кезекші коды' })).toBeInTheDocument()
    await waitFor(async () => expect(await dutyDb.rosters()).toEqual([]))
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
      'GET /api/admin/round-conflicts': ok({ conflicts: [] }),
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

  it('қақтығыста екі нұсқа қатар көрсетіледі; кейін жеткенін қолдану растауды сұрайды', async () => {
    const conflict = {
      id: 7, class: { id: 11, name: '5А' }, date: '2026-11-20', student_count: 25,
      current: { device_number: 1, saved_at: '2026-11-20T08:15:00+05:00', absent: [{ id: 2, name: 'Сынақов Екінші' }] },
      incoming: { device_number: 2, marked_at: '2026-11-20T08:12:00+05:00', received_at: '2026-11-20T09:40:00+05:00', absent: [{ id: 1, name: 'Әлібеков Бірінші' }, { id: 3, name: 'Үлгіев Үшінші' }] },
    }
    let open = true
    const calls = mockServer({
      'GET /api/auth/me': ok({ user: admin }),
      'GET /api/admin/backups': ok({ last_at: '2026-11-19T21:30:00+00:00', stale: false, backups: [] }),
      'GET /api/admin/duty-codes': ok(days(null)),
      'GET /api/admin/round-conflicts': () => ({ status: 200, body: { conflicts: open ? [conflict] : [] } }),
      'POST /api/admin/round-conflicts/7/resolve': () => { open = false; return { status: 200, body: { conflicts: [] } } },
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

    const card = await screen.findByRole('region', { name: '5А сыныбы, 20 қараша' })
    const current = within(card).getByRole('region', { name: 'Қазір сақтаулы тұрғаны' })
    const incoming = within(card).getByRole('region', { name: 'Кейін жеткені' })
    expect(current).toHaveTextContent('1-планшет · сақталған уақыты 08:15')
    expect(within(current).getByText('Сынақов Екінші')).toBeInTheDocument()
    expect(incoming).toHaveTextContent('2-планшет · белгіленген уақыты 08:12')
    expect(within(incoming).getByText('Жоқ: 2 оқушы')).toBeInTheDocument()

    await user.click(within(incoming).getByRole('button', { name: 'Осыны қолдану' }))

    expect(confirm).toHaveBeenCalledWith('5А сыныбының белгілері кейін жеткен нұсқаға ауыстырылады. Жалғастырасыз ба?')
    await waitFor(() => expect(screen.queryByRole('region', { name: 'Шешілмеген қақтығыстар' })).not.toBeInTheDocument())
    expect(calls.find((c) => c.key.includes('/resolve'))?.body).toEqual({ resolution: 'replace' })
  })
})
