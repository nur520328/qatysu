import { act, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { DailySummary, SummaryClass, SummaryPerson, SummaryShift } from '../api/admin'
import type { User } from '../api/client'
import { App } from '../App'
import { AuthProvider } from '../auth/AuthContext'
import { setLocale } from '../i18n'
import { mockServer } from './mockServer'
import type { Handler } from './mockServer'

// Барлық дерек ойдан шығарылған: сынақта нақты оқушы не мұғалім аты қолданылмайды.
const admin: User = { id: 1, full_name: 'Сынақ Завуч', role: 'admin', locale: 'kk', must_change_password: false, homeroom_classes: [] }
const ok = (body: unknown): Handler => () => ({ status: 200, body })

function person(id: number, name: string, extra: Partial<SummaryPerson> = {}): SummaryPerson {
  return {
    id, name, round_status: 'absent', full_absence: true, arrived_lesson: null, left_lesson: null,
    absence_reason: null, leave_permission: null, note: null, pending: 'reason', ...extra,
  }
}

function cls(id: number, name: string, extra: Partial<SummaryClass> = {}): SummaryClass {
  return {
    id, name, homeroom: null, students: 25, surveyed: true, saved_at: '2026-11-20T03:12:00+00:00', received_late: false,
    unmarked: 0, absent: 0, late: 0, left: 0, pending: 0, people: [], ...extra,
  }
}

const morning: SummaryShift = {
  shift: 'morning', ended: false, classes_total: 2, classes_surveyed: 1, students: 50, unmarked: 24,
  absent: 2, absent_by_reason: { sick: 1, excused: 0, unexcused: 0, pending: 1 },
  late: 1, left: 2, left_by_permission: { permitted: 1, unpermitted: 0, pending: 1 },
  classes: [
    cls(11, '5А', {
      absent: 2, late: 1, left: 1, pending: 2,
      people: [
        person(1, 'Әлібеков Бірінші', { round_status: 'present', full_absence: false, left_lesson: 4, pending: 'permission' }),
        person(2, 'Жасандиев Төртінші'),
        person(3, 'Сынақов Екінші', { absence_reason: 'sick', note: 'анықтама бар', pending: null }),
        person(4, 'Үлгіев Үшінші', { full_absence: false, arrived_lesson: 2, pending: null }),
      ],
    }),
    cls(12, '6Ә', {
      homeroom: 'Сынақ Жетекші', surveyed: false, saved_at: null, unmarked: 24, left: 1,
      people: [person(5, 'Ойданов Алтыншы', { round_status: 'present', full_absence: false, left_lesson: 3, leave_permission: 'permitted', pending: null })],
    }),
  ],
}
const afternoon: SummaryShift = {
  shift: 'afternoon', ended: false, classes_total: 0, classes_surveyed: 0, students: 0, unmarked: 0,
  absent: 0, absent_by_reason: { sick: 0, excused: 0, unexcused: 0, pending: 0 },
  late: 0, left: 0, left_by_permission: { permitted: 0, unpermitted: 0, pending: 0 }, classes: [],
}
const today: DailySummary = {
  date: '2026-11-20', is_today: true, is_school_day: true, previous_school_day: '2026-11-19', next_school_day: null,
  open_conflicts: 0, shifts: [morning, afternoon],
}

function renderAt(path: string, routes: Record<string, Handler>, user: User = admin) {
  const calls = mockServer({
    'GET /api/auth/me': ok({ user }),
    'GET /api/admin/backups': ok({ last_at: '2026-11-19T21:30:00+00:00', stale: false, backups: [] }),
    ...routes,
  })
  const view = render(
    <MemoryRouter initialEntries={[path]}>
      <AuthProvider>
        <App />
      </AuthProvider>
    </MemoryRouter>,
  )
  return Object.assign(calls, { unmount: view.unmount })
}

beforeEach(() => setLocale('kk'))
afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
})

describe('күнделікті жиынтық', () => {
  it('бүгінгі күн: сандар, аралынбаған сынып жетекшісімен, оқушылардың күйі', async () => {
    renderAt('/admin/summary', { 'GET /api/admin/daily-summary': ok(today) })

    expect(await screen.findByText('Бүгін, 20 қараша, жұма')).toBeInTheDocument()
    const shift = screen.getByRole('region', { name: 'Таңғы смена' })
    expect(within(shift).getByText('Таңғы смена · смена жүріп жатыр')).toBeInTheDocument()
    // Түстен кейінгі сменада сынып жоқ, сондықтан оның бөлімі көрсетілмейді.
    expect(screen.queryByRole('region', { name: 'Түстен кейінгі смена' })).not.toBeInTheDocument()

    const stats = within(shift).getByRole('status')
    expect(stats).toHaveTextContent('1 / 2сынып аралынды')
    expect(stats).toHaveTextContent('2күні бойы жоқ')
    expect(stats).toHaveTextContent('1кешікті')
    expect(stats).toHaveTextContent('2кетіп қалды')
    expect(stats).toHaveTextContent('2шешім күтеді')
    expect(stats).toHaveTextContent('24белгіленбеген')
    expect(within(shift).getByText('Жоқ оқушылар: ауырды 1 · себепті 0 · себепсіз 0 · себебі көрсетілмеген 1')).toBeInTheDocument()
    expect(within(shift).getByText('Кетіп қалғандар: рұқсатпен 1 · рұқсатсыз 0 · анықталмаған 1')).toBeInTheDocument()

    // Смена жүріп жатқанда «аралынбады» деп айыпталмайды.
    const missing = within(shift).getByRole('region', { name: 'Әлі аралынбаған сыныптар: 1' })
    expect(missing).toHaveTextContent('6Ә · жетекшісі: Сынақ Жетекші')

    const row = (name: string) => within(shift).getByText(name).closest('tr') as HTMLElement
    expect(row('Әлібеков Бірінші')).toHaveTextContent('бар · 4-сабақтан кетіп қалды · рұқсаты анықталмаған')
    expect(row('Жасандиев Төртінші')).toHaveTextContent('жоқ · себебі көрсетілмеген')
    expect(row('Сынақов Екінші')).toHaveTextContent('жоқ · ауырды · анықтама бар')
    expect(row('Үлгіев Үшінші')).toHaveTextContent('2-сабақтан келді')
    expect(row('Ойданов Алтыншы')).toHaveTextContent('бар · 3-сабақтан кетіп қалды · рұқсатпен')

    // Сынып аты сол күннің толық бетіне апарады.
    const links = within(shift).getAllByRole('link', { name: '5А сыныбының күнін ашу' })
    expect(links[0]).toHaveAttribute('href', '/admin/attendance/11/2026-11-20')
    expect(within(shift).getByText('08:12')).toBeInTheDocument()
    expect(within(shift).getByText('әлі жоқ')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Келесі оқу күні →' })).toBeDisabled()
  })

  it('өткен күнге өтеді: сұраныс күнмен жіберіледі, аралынбаған сынып «аралынбады» деп көрінеді', async () => {
    const user = userEvent.setup()
    const past: DailySummary = {
      ...today, date: '2026-11-19', is_today: false, previous_school_day: '2026-11-18', next_school_day: '2026-11-20',
      shifts: [{ ...morning, ended: true }, afternoon],
    }
    const calls = renderAt('/admin/summary', {
      'GET /api/admin/daily-summary': ok(today),
      'GET /api/admin/daily-summary?date=2026-11-19': ok(past),
    })

    await user.click(await screen.findByRole('button', { name: '← Алдыңғы оқу күні' }))
    expect(await screen.findByText('19 қараша, бейсенбі')).toBeInTheDocument()
    expect(calls.map((c) => c.key)).toContain('GET /api/admin/daily-summary?date=2026-11-19')
    const shift = screen.getByRole('region', { name: 'Таңғы смена' })
    expect(within(shift).getByText('Таңғы смена · смена аяқталды')).toBeInTheDocument()
    expect(within(shift).getByRole('region', { name: 'Аралынбаған сыныптар: 1' })).toBeInTheDocument()
    expect(within(shift).getByText('аралынбады')).toBeInTheDocument()
    expect(screen.queryByText('Бет минут сайын өзі жаңарады.')).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Бүгін' }))
    expect(await screen.findByText('Бүгін, 20 қараша, жұма')).toBeInTheDocument()
  })

  it('сынып бетінен «артқа» сол күннің жиынтығына қайтарады', async () => {
    const user = userEvent.setup()
    const past: DailySummary = { ...today, date: '2026-11-19', is_today: false }
    renderAt('/admin/summary?date=2026-11-19', {
      'GET /api/admin/daily-summary?date=2026-11-19': ok(past),
      'GET /api/homeroom/classes/12/days/2026-11-19': ok({
        class: { id: 12, name: '6Ә' }, date: '2026-11-19', is_school_day: true, editable: true, max_lessons: 7, duty: null, students: [],
      }),
    })

    await user.click((await screen.findAllByRole('link', { name: '6Ә сыныбының күнін ашу' }))[0])
    const back = await screen.findByRole('link', { name: '← Жиынтық' })
    expect(back).toHaveAttribute('href', '/admin/summary?date=2026-11-19')
  })

  it('оқу күні емес күн және шешілмеген қақтығыс туралы ескертеді', async () => {
    const sunday: DailySummary = {
      ...today, date: '2026-11-15', is_today: false, is_school_day: false, open_conflicts: 1,
      shifts: [{ ...morning, classes_surveyed: 0, classes: [cls(11, '5А', { surveyed: false, saved_at: null, unmarked: 25 })] }, afternoon],
    }
    renderAt('/admin/summary?date=2026-11-15', { 'GET /api/admin/daily-summary?date=2026-11-15': ok(sunday) })

    expect(await screen.findByText('Бұл күн оқу күні емес.')).toBeInTheDocument()
    expect(screen.getByRole('alert')).toHaveTextContent('1 сыныпты екі планшет екі түрлі белгілеген')
    expect(screen.getByRole('link', { name: 'Қақтығысты шешу' })).toHaveAttribute('href', '/admin/duty')
    // Жазбасы жоқ демалыс күні сыныптар тізімі көрсетілмейді.
    expect(screen.queryByRole('region', { name: 'Таңғы смена' })).not.toBeInTheDocument()
  })

  it('бүгінгі бет минут сайын өзі жаңарады', async () => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
    const calls = renderAt('/admin/summary', { 'GET /api/admin/daily-summary': ok(today) })
    await screen.findByText('Бет минут сайын өзі жаңарады.')
    const count = () => calls.filter((c) => c.key === 'GET /api/admin/daily-summary').length
    expect(count()).toBe(1)

    await act(async () => {
      vi.advanceTimersByTime(60_000)
    })
    await waitFor(() => expect(count()).toBe(2))
  })

  it('орысша да, қытайша да толық аударылған', async () => {
    const { unmount } = renderAt('/admin/summary', { 'GET /api/admin/daily-summary': ok(today) }, { ...admin, locale: 'ru' })
    expect(await screen.findByText('Сегодня, 20 ноября, пятница')).toBeInTheDocument()
    expect(screen.getByRole('region', { name: 'Пока не обойдены: 1' })).toBeInTheDocument()
    unmount()

    renderAt('/admin/summary', { 'GET /api/admin/daily-summary': ok(today) }, { ...admin, locale: 'zh' })
    expect(await screen.findByText('今天，11月20日 星期五')).toBeInTheDocument()
    expect(screen.getByRole('region', { name: '尚未巡查的班级：1' })).toBeInTheDocument()
  })
})
