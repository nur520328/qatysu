import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { ReportClass, ReportCounters, ReportResponse } from '../api/admin'
import type { ClassMonth, MonthStudent } from '../api/staff'
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
  ...zero, id, name, grade: Number.parseInt(name, 10), shift: 'morning', homeroom: null, students: 4, days_expected: 5, days_surveyed: 5, missing_dates: [], ...extra,
})

const current: ReportResponse = {
  period: { from: '2026-11-16', to: '2026-11-22', previous: '2026-11-09', next: null, is_current: true },
  version: 'live',
  snapshot: null,
  report: {
    type: 'weekly', from: '2026-11-16', to: '2026-11-22', school_days: ['2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20'], complete: false,
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
const past: ReportResponse = {
  period: { from: '2026-11-09', to: '2026-11-15', previous: '2026-11-02', next: '2026-11-16', is_current: false },
  version: 'live',
  snapshot: { generated_at: '2026-11-13T14:05:00+00:00', changed_since: 3 },
  report: {
    ...current.report, from: '2026-11-09', to: '2026-11-15', complete: true, days: [], students: [], students_total: 0, events: [],
    classes: [cls(11, '5А', { attendance_pct: 96.5 })],
    totals: { ...current.report.totals, unexcused: 1, class_days_surveyed: 10 },
  },
}
const frozen: ReportResponse = { ...past, version: 'snapshot', report: { ...past.report, totals: { ...past.report.totals, unexcused: 2 } } }

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

    expect(screen.getByRole('link', { name: 'Excel (аты-жөнімен)' })).toHaveAttribute('href', '/api/admin/reports/weekly/export?date=2026-11-16')
    expect(screen.getByRole('link', { name: 'Excel (аты-жөнсіз)' })).toHaveAttribute('href', '/api/admin/reports/weekly/export?date=2026-11-16&names=0')
    expect(screen.getByRole('button', { name: 'Келесі апта →' })).toBeDisabled()
  })

  it('өткен апта: бекітілген есеп туралы хабар, бекітілген нұсқа мен қазіргі күй арасында ауысу', async () => {
    const user = userEvent.setup()
    const calls = renderAt('/admin/reports', {
      'GET /api/admin/reports/weekly': ok(current),
      'GET /api/admin/reports/weekly?date=2026-11-09': ok(past),
      'GET /api/admin/reports/weekly?date=2026-11-09&version=snapshot': ok(frozen),
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
    expect(calls.map((c) => c.key)).toContain('GET /api/admin/reports/weekly?date=2026-11-09&version=snapshot')
    // Excel де көрсетіліп тұрған нұсқадан жасалады.
    expect(screen.getByRole('link', { name: 'Excel (аты-жөнсіз)' })).toHaveAttribute('href', '/api/admin/reports/weekly/export?date=2026-11-09&version=snapshot&names=0')

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
    const first = renderAt('/admin/reports?date=2026-11-11', { 'GET /api/admin/reports/weekly?date=2026-11-11': ok(past) }, { ...admin, locale: 'ru' })
    expect(await screen.findByText('9 ноября – 15 ноября')).toBeInTheDocument()
    expect(screen.getByText(/С тех пор изменены 3 записи\./)).toBeInTheDocument()
    expect(screen.getByRole('region', { name: 'Ученики, требующие внимания' })).toBeInTheDocument()
    first.unmount()

    renderAt('/admin/reports', { 'GET /api/admin/reports/weekly': ok(current) }, { ...admin, locale: 'zh' })
    expect(await screen.findByText('11月16日 – 11月22日')).toBeInTheDocument()
    expect(screen.getByRole('region', { name: '无数据的日期' })).toBeInTheDocument()
  })
})

const monthly: ReportResponse = {
  period: { from: '2026-11-01', to: '2026-11-30', previous: '2026-10-01', next: null, is_current: true },
  version: 'live',
  snapshot: null,
  report: {
    ...current.report, type: 'monthly', from: '2026-11-01', to: '2026-11-30',
    weeks: [
      { ...zero, from: '2026-11-02', to: '2026-11-06', school_days: 5, class_days_expected: 15, class_days_surveyed: 15, marked: 35, attendance_pct: 97.1, sick: 1 },
      { ...zero, from: '2026-11-09', to: '2026-11-13', school_days: 5, class_days_expected: 15, class_days_surveyed: 12, marked: 28, attendance_pct: 92.9, unexcused: 2 },
    ],
    grades: [
      { ...zero, grade: 5, classes: 2, students: 6, class_days_expected: 30, class_days_surveyed: 27, attendance_pct: 95, unexcused: 2, late: 1 },
      { ...zero, grade: 7, classes: 1, students: 4, class_days_expected: 15, class_days_surveyed: 0 },
    ],
  },
}

describe('айлық есеп', () => {
  it('апталар мен параллельдер кестесі бар, күндер кестесі жоқ; Excel айлық есептікі', async () => {
    const user = userEvent.setup()
    const calls = renderAt('/admin/reports', {
      'GET /api/admin/reports/weekly': ok(current),
      'GET /api/admin/reports/monthly': ok(monthly),
    })

    await user.click(await screen.findByRole('link', { name: 'Айлық' }))
    expect(await screen.findByRole('heading', { name: 'Айлық есеп' })).toBeInTheDocument()
    expect(screen.getByText('Қараша 2026')).toBeInTheDocument()
    expect(screen.getByText(/Ай әлі аяқталған жоқ/)).toBeInTheDocument()
    expect(calls.map((c) => c.key)).toContain('GET /api/admin/reports/monthly')

    const weeks = screen.getByRole('region', { name: 'Апталар бойынша' })
    expect(within(weeks).getByRole('row', { name: /^2 қараша – 6 қараша 5 15 \/ 15 97,1% 1 0 0 0 0 0$/ })).toBeInTheDocument()
    expect(within(weeks).getByRole('row', { name: /^9 қараша – 13 қараша 5 12 \/ 15 92,9% 0 0 2 0 0 0$/ })).toBeInTheDocument()
    // Апта атауы сол аптаның апталық есебіне апарады.
    expect(within(weeks).getByRole('link', { name: '9 қараша – 13 қараша' })).toHaveAttribute('href', '/admin/reports?date=2026-11-09')

    const grades = screen.getByRole('region', { name: 'Параллельдер бойынша' })
    expect(within(grades).getByRole('row', { name: /^5-сыныптар 2 6 27 \/ 30 95% 0 0 2 0 1 0$/ })).toBeInTheDocument()
    expect(within(grades).getByRole('row', { name: /^7-сыныптар 1 4 0 \/ 15 дерек жоқ/ })).toBeInTheDocument()

    expect(screen.queryByRole('region', { name: 'Күндер бойынша' })).not.toBeInTheDocument()
    expect(screen.getByRole('region', { name: 'Сыныптар бойынша' })).toBeInTheDocument()
    expect(screen.getByText('Ай ішінде себепсіз не себебі анықталмаған күні бар оқушылар.')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Excel (аты-жөнсіз)' })).toHaveAttribute('href', '/api/admin/reports/monthly/export?date=2026-11-01&names=0')
    expect(screen.getByRole('button', { name: 'Келесі ай →' })).toBeDisabled()

    await user.click(screen.getByRole('link', { name: 'Апталық' }))
    expect(await screen.findByRole('heading', { name: 'Апталық есеп' })).toBeInTheDocument()
  })
})

const student = (id: number, name: string, extra: Partial<MonthStudent>): MonthStudent => ({
  id, name, in_class_now: true, marked: 15, absent: 0, sick: 0, excused: 0, unexcused: 0, pending: 0,
  late: 0, left: 0, left_permitted: 0, left_unpermitted: 0, left_pending: 0, attendance_pct: 100, dates: [], ...extra,
})
const classMonth: ClassMonth = {
  class: { id: 11, name: '5А' },
  month: { from: '2026-11-01', to: '2026-11-30', previous: '2026-10-01', next: null, is_current: true },
  complete: false, days_expected: 15, days_surveyed: 13, missing_dates: ['2026-11-12', '2026-11-19'],
  totals: {
    marked: 39, absent: 3, sick: 1, excused: 0, unexcused: 2, pending: 0, late: 1, left: 0,
    left_permitted: 0, left_unpermitted: 0, left_pending: 0, attendance_pct: 92.3,
  },
  students: [
    student(1, 'Әлібеков Бірінші', { absent: 2, unexcused: 2, attendance_pct: 86.7, dates: ['2026-11-03', '2026-11-10'] }),
    student(2, 'Сынақов Екінші', { in_class_now: false, marked: 9, absent: 1, sick: 1, attendance_pct: 88.9, dates: ['2026-11-10'] }),
    student(3, 'Үлгіев Үшінші', { late: 1 }),
  ],
}

describe('жетекшінің айлық қорытындысы', () => {
  const teacher: User = { ...admin, id: 5, role: 'teacher', homeroom_classes: ['5А'] }

  it('мұғалім бастапқы беттен өз сыныбының айлық қорытындысын ашады', async () => {
    const user = userEvent.setup()
    renderAt('/', {
      'GET /api/teacher/homeroom': ok({ date: '2026-11-20', classes: [{ class: { id: 11, name: '5А' }, students: [] }] }),
      'GET /api/homeroom/pending': ok({ days: 3, items: [] }),
      'GET /api/homeroom/classes/11/month': ok(classMonth),
      'GET /api/homeroom/classes/11/month?date=2026-10-01': ok({
        ...classMonth, complete: true, missing_dates: [], students: [],
        month: { from: '2026-10-01', to: '2026-10-31', previous: '2026-09-01', next: '2026-11-01', is_current: false },
      }),
    }, teacher)

    await user.click(await screen.findByRole('link', { name: 'Айлық қорытынды' }))
    expect(await screen.findByRole('heading', { name: 'Айлық қорытынды · 5А' })).toBeInTheDocument()
    expect(screen.getByText('Қараша 2026')).toBeInTheDocument()
    const stats = screen.getByRole('status', { name: 'Айлық қорытынды' })
    expect(stats).toHaveTextContent('92,3%сыныптың қатысуы')
    expect(stats).toHaveTextContent('13 / 15күн аралынды')
    expect(screen.getByText('Аралау жасалмаған күндер (олар есепке кірмейді): 12 қараша, 19 қараша')).toBeInTheDocument()

    expect(screen.getByRole('row', { name: 'Әлібеков Бірінші 15 86,7% 2 0 0 2 0 0 0 3 қараша, 10 қараша' })).toBeInTheDocument()
    expect(screen.getByRole('row', { name: /^Сынақов Екінші · бұл сыныптан кеткен 9 88,9% 1 1 0 0 0 0 0 10 қараша$/ })).toBeInTheDocument()
    expect(screen.getByRole('row', { name: /^Үлгіев Үшінші 15 100% 0 0 0 0 0 1 0/ })).toBeInTheDocument()
    // Жоқ болған күн сол күннің бетіне апарады: себепті сол жерде түзетеді.
    expect(screen.getByRole('link', { name: '3 қараша' })).toHaveAttribute('href', '/homeroom/11/2026-11-03')

    await user.click(screen.getByRole('button', { name: '← Алдыңғы ай' }))
    expect(await screen.findByText('Қазан 2026')).toBeInTheDocument()
    expect(screen.getByText('Бұл айда сыныпта оқушы болған жоқ.')).toBeInTheDocument()
    expect(screen.queryByText(/Ай әлі аяқталған жоқ/)).not.toBeInTheDocument()
  })

  it('завуч сынып бетінен кез келген сыныптың қорытындысын ашады; күндер завуч бетіне апарады', async () => {
    renderAt('/admin/class-month/11', { 'GET /api/homeroom/classes/11/month': ok(classMonth) })

    expect(await screen.findByRole('heading', { name: 'Айлық қорытынды · 5А' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: '← 5А сыныбы' })).toHaveAttribute('href', '/admin/classes/11')
    expect(screen.getByRole('link', { name: '3 қараша' })).toHaveAttribute('href', '/admin/attendance/11/2026-11-03')
  })
})
