import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { ReportClass, ReportCounters, WeeklyReportResponse } from '../api/admin'
import type { User } from '../api/client'
import { App } from '../App'
import { AuthProvider } from '../auth/AuthContext'
import { setLocale } from '../i18n'
import { mockServer } from './mockServer'
import type { Handler } from './mockServer'

// Барлық дерек ойдан шығарылған.
const admin: User = { id: 1, full_name: 'Сынақ Завуч', role: 'admin', locale: 'kk', must_change_password: false, homeroom_classes: [] }
const ok = (body: unknown): Handler => () => ({ status: 200, body })
const zero: ReportCounters = {
  marked: 0, absent_marked: 0, absent: 0, sick: 0, excused: 0, unexcused: 0, pending: 0,
  late: 0, left: 0, left_permitted: 0, left_unpermitted: 0, left_pending: 0, attendance_pct: null,
}
const cls = (id: number, name: string, extra: Partial<ReportClass>): ReportClass => ({
  ...zero, id, name, shift: 'morning', homeroom: null, students: 4, days_expected: 5, days_surveyed: 5, missing_dates: [], ...extra,
})

const current: WeeklyReportResponse = {
  week: { from: '2026-11-16', to: '2026-11-22', previous: '2026-11-09', next: null, is_current: true },
  version: 'live',
  snapshot: null,
  report: {
    from: '2026-11-16', to: '2026-11-22', school_days: ['2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20'], complete: false,
    totals: {
      ...zero, marked: 16, absent_marked: 4, absent: 4, sick: 1, unexcused: 2, pending: 1, late: 1, left: 2, left_permitted: 1, left_pending: 1,
      attendance_pct: 75, classes: 2, students: 6, class_days_expected: 10, class_days_surveyed: 5,
    },
    days: [
      { ...zero, date: '2026-11-16', class_days_expected: 2, class_days_surveyed: 2, marked: 6, absent: 2, attendance_pct: 66.7 },
      { ...zero, date: '2026-11-19', class_days_expected: 2, class_days_surveyed: 0 },
    ],
    classes: [
      cls(11, '5А', { homeroom: 'Сынақ Жетекші', days_surveyed: 3, missing_dates: ['2026-11-19', '2026-11-20'], marked: 12, absent: 3, sick: 1, unexcused: 1, pending: 1, late: 1, left: 1, attendance_pct: 75 }),
      cls(12, '6Ә', { shift: 'afternoon', students: 2, attendance_pct: 100 }),
      cls(13, '7Б', { days_surveyed: 0, missing_dates: ['2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20'] }),
    ],
    students: [{ id: 2, name: 'Сынақов Екінші', class_id: 11, class: '5А', unexcused: 1, pending: 1, dates: ['2026-11-16', '2026-11-17'] }],
    students_total: 1,
    events: [
      { date: '2026-11-17', class_id: 11, class: '5А', student_id: 3, name: 'Үлгіев Үшінші', arrived_lesson: 2, left_lesson: null, leave_permission: null },
      { date: '2026-11-18', class_id: 11, class: '5А', student_id: 4, name: 'Жасандиев Төртінші', arrived_lesson: null, left_lesson: 5, leave_permission: null },
    ],
  },
}
const past: WeeklyReportResponse = {
  week: { from: '2026-11-09', to: '2026-11-15', previous: '2026-11-02', next: '2026-11-16', is_current: false },
  version: 'live',
  snapshot: { generated_at: '2026-11-13T14:05:00+00:00', changed_since: 3 },
  report: {
    ...current.report, from: '2026-11-09', to: '2026-11-15', complete: true, days: [], students: [], students_total: 0, events: [],
    classes: [cls(11, '5А', { attendance_pct: 96.5 })],
    totals: { ...current.report.totals, unexcused: 1, class_days_surveyed: 10 },
  },
}
const frozen: WeeklyReportResponse = { ...past, version: 'snapshot', report: { ...past.report, totals: { ...past.report.totals, unexcused: 2 } } }

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
afterEach(() => vi.restoreAllMocks())

describe('апталық есеп', () => {
  it('ағымдағы апта: сандар, дерек жоқ күндер, оқушылар мен оқиғалар', async () => {
    renderAt('/admin/reports', { 'GET /api/admin/reports/weekly': ok(current) })

    expect(await screen.findByText('16 қараша – 22 қараша')).toBeInTheDocument()
    expect(screen.getByText(/Апта әлі аяқталған жоқ/)).toBeInTheDocument()

    const stats = screen.getByRole('status', { name: 'Апталық есеп' })
    expect(stats).toHaveTextContent('75%қатысу')
    expect(stats).toHaveTextContent('5 / 10сынып-күн аралынды')
    expect(stats).toHaveTextContent('2себепсіз')
    expect(stats).toHaveTextContent('1себебі анықталмаған')
    expect(screen.getByText('Жоқ оқушылар: ауырды 1 · себепті 0 · себепсіз 2 · себебі көрсетілмеген 1')).toBeInTheDocument()
    expect(screen.getByText(/Аралынбаған күн «дерек жоқ» деп саналады/)).toBeInTheDocument()

    const days = screen.getByRole('region', { name: 'Күндер бойынша' })
    expect(within(days).getByRole('row', { name: /16 қараша, дүйсенбі 2 \/ 2 66,7%/ })).toBeInTheDocument()
    // Аралынбаған күн 100% де, 0% де емес: «дерек жоқ».
    expect(within(days).getByRole('row', { name: /19 қараша, бейсенбі 0 \/ 2 дерек жоқ/ })).toBeInTheDocument()
    expect(within(days).getByRole('link', { name: '19 қараша, бейсенбі' })).toHaveAttribute('href', '/admin/summary?date=2026-11-19')

    const missing = screen.getByRole('region', { name: 'Дерек жоқ күндер' })
    expect(missing).toHaveTextContent('5А: 19 қараша, 20 қараша · жетекшісі: Сынақ Жетекші')
    expect(missing).not.toHaveTextContent('6Ә')
    // Бір күні де аралынбаған сыныптың күндері тізбектелмейді.
    expect(missing).toHaveTextContent('7Б: барлық оқу күні')

    const classes = screen.getByRole('region', { name: 'Сыныптар бойынша' })
    expect(within(classes).getByRole('row', { name: /^5А 4 3 \/ 5 75% 1 0 1 1 1 1$/ })).toBeInTheDocument()
    expect(within(classes).getByRole('row', { name: /^6Ә 2 5 \/ 5 100%/ })).toBeInTheDocument()

    const students = screen.getByRole('region', { name: 'Назар аударатын оқушылар' })
    expect(within(students).getByRole('row', { name: 'Сынақов Екінші 5А 1 1 16 қараша, 17 қараша' })).toBeInTheDocument()

    const events = screen.getByRole('region', { name: 'Кешігу мен кетіп қалу' })
    expect(within(events).getByRole('row', { name: '17 қараша 5А Үлгіев Үшінші 2-сабақтан келді' })).toBeInTheDocument()
    expect(within(events).getByRole('row', { name: '18 қараша 5А Жасандиев Төртінші 5-сабақтан кетіп қалды · рұқсаты анықталмаған' })).toBeInTheDocument()

    expect(screen.getByRole('link', { name: 'Excel (аты-жөнімен)' })).toHaveAttribute('href', '/api/admin/reports/weekly/export?week=2026-11-16')
    expect(screen.getByRole('link', { name: 'Excel (аты-жөнсіз)' })).toHaveAttribute('href', '/api/admin/reports/weekly/export?week=2026-11-16&names=0')
    expect(screen.getByRole('button', { name: 'Келесі апта →' })).toBeDisabled()
  })

  it('өткен апта: бекітілген есеп туралы хабар, бекітілген нұсқа мен қазіргі күй арасында ауысу', async () => {
    const user = userEvent.setup()
    const calls = renderAt('/admin/reports', {
      'GET /api/admin/reports/weekly': ok(current),
      'GET /api/admin/reports/weekly?week=2026-11-09': ok(past),
      'GET /api/admin/reports/weekly?week=2026-11-09&version=snapshot': ok(frozen),
    })

    await user.click(await screen.findByRole('button', { name: '← Алдыңғы апта' }))
    expect(await screen.findByText('9 қараша – 15 қараша')).toBeInTheDocument()
    const notice = screen.getByText(/Есеп 13 қараша, 19:05 бекітілді/)
    expect(notice).toHaveTextContent('Содан бері 3 жазба өзгерді. Төменде қазіргі күй көрсетілген.')
    expect(screen.queryByText(/Апта әлі аяқталған жоқ/)).not.toBeInTheDocument()
    expect(screen.getByRole('status', { name: 'Апталық есеп' })).toHaveTextContent('1себепсіз')
    expect(screen.getByText('Барлық сынып барлық күні аралынды.')).toBeInTheDocument()
    expect(screen.getByText('Мұндай оқушы жоқ.')).toBeInTheDocument()
    expect(screen.getByText('Тіркелген жағдай жоқ.')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Бекітілген нұсқаны көру' }))
    expect(await screen.findByText(/Төменде бекітілген нұсқа көрсетілген/)).toBeInTheDocument()
    expect(screen.getByRole('status', { name: 'Апталық есеп' })).toHaveTextContent('2себепсіз')
    expect(calls.map((c) => c.key)).toContain('GET /api/admin/reports/weekly?week=2026-11-09&version=snapshot')
    // Excel де көрсетіліп тұрған нұсқадан жасалады.
    expect(screen.getByRole('link', { name: 'Excel (аты-жөнсіз)' })).toHaveAttribute('href', '/api/admin/reports/weekly/export?week=2026-11-09&version=snapshot&names=0')

    await user.click(screen.getByRole('button', { name: 'Қазіргі күйді көру' }))
    expect(await screen.findByText(/Төменде қазіргі күй көрсетілген/)).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Осы апта' }))
    expect(await screen.findByText('16 қараша – 22 қараша')).toBeInTheDocument()
  })

  it('«Басып шығару / PDF» браузердің басып шығару терезесін ашады', async () => {
    const user = userEvent.setup()
    const print = vi.spyOn(window, 'print').mockImplementation(() => undefined)
    renderAt('/admin/reports', { 'GET /api/admin/reports/weekly': ok(current) })

    await user.click(await screen.findByRole('button', { name: 'Басып шығару / PDF' }))
    expect(print).toHaveBeenCalledTimes(1)
  })

  it('орысша да, қытайша да толық аударылған', async () => {
    const first = renderAt('/admin/reports?week=2026-11-11', { 'GET /api/admin/reports/weekly?week=2026-11-11': ok(past) }, { ...admin, locale: 'ru' })
    expect(await screen.findByText('9 ноября – 15 ноября')).toBeInTheDocument()
    expect(screen.getByText(/С тех пор изменены 3 записи\./)).toBeInTheDocument()
    expect(screen.getByRole('region', { name: 'Ученики, требующие внимания' })).toBeInTheDocument()
    first.unmount()

    renderAt('/admin/reports', { 'GET /api/admin/reports/weekly': ok(current) }, { ...admin, locale: 'zh' })
    expect(await screen.findByText('11月16日 – 11月22日')).toBeInTheDocument()
    expect(screen.getByRole('region', { name: '无数据的日期' })).toBeInTheDocument()
  })
})
