import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { User } from '../api/client'
import type { Day, DayRow, TeacherRow } from '../api/staff'
import { App } from '../App'
import { AuthProvider } from '../auth/AuthContext'
import { setLocale } from '../i18n'
import { mockServer } from './mockServer'
import type { Handler } from './mockServer'

// Барлық дерек ойдан шығарылған.
const teacher: User = { id: 7, full_name: 'Сынақ Мұғалім', role: 'teacher', locale: 'kk', must_change_password: false, homeroom_classes: ['7Ә'] }
const admin: User = { ...teacher, id: 1, full_name: 'Сынақ Завуч', role: 'admin', homeroom_classes: [] }
const ok = (body: unknown): Handler => () => ({ status: 200, body })

const context = {
  date: '2026-11-20', is_school_day: true, max_lessons: 7,
  classes: [{ id: 5, name: '7Ә', grade: 7, shift: 'afternoon' }, { id: 6, name: '7Б', grade: 7, shift: 'afternoon' }, { id: 9, name: '9А', grade: 9, shift: 'morning' }],
}

function row(id: number, last: string, extra: Partial<TeacherRow> = {}): TeacherRow {
  return {
    id, last_name: last, first_name: 'Оқушы', patronymic: null, class: { id: 5, name: '7Ә' },
    round_status: 'present', arrived_lesson: null, left_lesson: null, can_undo_arrival: false, can_undo_leave: false, ...extra,
  }
}

function dayRow(id: number, last: string, extra: Partial<DayRow> = {}): DayRow {
  return {
    id, last_name: last, first_name: 'Оқушы', patronymic: null, round_status: 'present', arrived_lesson: null, left_lesson: null,
    absence_reason: null, leave_permission: null, note: null, pending: null, ...extra,
  }
}

function day(extra: Partial<Day> = {}): Day {
  return {
    class: { id: 5, name: '7Ә' }, date: '2026-11-20', is_school_day: true, editable: true, max_lessons: 7,
    duty: { saved_at: '2026-11-20T14:12:00+05:00', received_late: false },
    students: [dayRow(1, 'Әлібеков'), dayRow(2, 'Сынақов', { round_status: 'absent', pending: 'reason' }), dayRow(3, 'Үлгіев', { left_lesson: 4, pending: 'permission' })],
    ...extra,
  }
}

function renderAt(path: string, user: User, routes: Record<string, Handler>) {
  const calls = mockServer({
    'GET /api/auth/me': ok({ user }),
    'GET /api/admin/backups': ok({ last_at: '2026-11-19T21:30:00+00:00', stale: false, backups: [] }),
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
afterEach(() => vi.restoreAllMocks())

describe('мұғалімнің бастапқы беті', () => {
  it('жетекшіге шешім күтіп тұрған оқушылар мен өз сыныбы көрсетіледі', async () => {
    renderAt('/', teacher, {
      'GET /api/teacher/homeroom': ok({ date: '2026-11-20', classes: [{ class: { id: 5, name: '7Ә' }, students: [{ id: 1 }, { id: 2 }] }] }),
      'GET /api/homeroom/pending': ok({ items: [
        { class: { id: 5, name: '7Ә' }, date: '2026-11-19', student: { id: 2, name: 'Сынақов Оқушы' }, kind: 'reason' },
        { class: { id: 5, name: '7Ә' }, date: '2026-11-20', student: { id: 3, name: 'Үлгіев Оқушы' }, kind: 'permission' },
      ] }),
    })

    const pending = await screen.findByRole('region', { name: 'Шешім күтіп тұрғандар' })
    expect(within(pending).getByRole('link', { name: /Сынақов Оқушы.*7Ә, 19 қараша.*себебін көрсету керек/ })).toHaveAttribute('href', '/homeroom/5/2026-11-19')
    expect(within(pending).getByRole('link', { name: /Үлгіев Оқушы.*рұқсатын анықтау керек/ })).toHaveAttribute('href', '/homeroom/5/2026-11-20')
    expect(screen.getByRole('link', { name: 'Кешігу мен кетіп қалуды тіркеу' })).toHaveAttribute('href', '/register')
  })

  it('жетекші емес мұғалімге тек тіркеу батырмасы көрінеді', async () => {
    renderAt('/', { ...teacher, homeroom_classes: [] }, {
      'GET /api/teacher/homeroom': ok({ date: '2026-11-20', classes: [] }),
      'GET /api/homeroom/pending': ok({ items: [] }),
    })

    expect(await screen.findByRole('link', { name: 'Кешігу мен кетіп қалуды тіркеу' })).toBeInTheDocument()
    await waitFor(() => expect(screen.queryByText('Жүктелуде…')).not.toBeInTheDocument())
    expect(screen.queryByRole('region', { name: 'Шешім күтіп тұрғандар' })).not.toBeInTheDocument()
  })
})

describe('кешігу мен кетуді тіркеу', () => {
  it('сынып → оқушы → «Келді» → сабақ: төрт басумен тіркеледі', async () => {
    const calls = renderAt('/register', teacher, {
      'GET /api/teacher/classes': ok(context),
      'GET /api/teacher/classes/5': ok({ class: { id: 5, name: '7Ә' }, students: [row(1, 'Әлібеков'), row(2, 'Сынақов', { round_status: 'absent' })] }),
      'POST /api/teacher/students/2/arrival': ok({ student: row(2, 'Сынақов', { round_status: 'absent', arrived_lesson: 2, can_undo_arrival: true }) }),
    })
    const user = userEvent.setup()

    expect(await screen.findByText('Бүгін, 20 қараша')).toBeInTheDocument()
    await user.click(within(screen.getByRole('group', { name: '7-сыныптар' })).getByRole('button', { name: '7Ә' }))
    const student = await screen.findByRole('button', { name: /Сынақов Оқушы.*жоқ/ })
    await user.click(student)
    await user.click(screen.getByRole('button', { name: 'Келді' }))
    await user.click(within(screen.getByRole('group', { name: 'Қай сабақтан келді?' })).getByRole('button', { name: '2-сабақ' }))

    expect(await screen.findByRole('status')).toHaveTextContent('Сынақов Оқушы: 2-сабақтан келді деп тіркелді.')
    expect(screen.getByRole('button', { name: /Сынақов Оқушы.*2-сабақтан келді/ })).toBeInTheDocument()
    expect(calls.find((c) => c.key.startsWith('POST'))?.body).toEqual({ lesson: 2 })
  })

  it('кету 2-сабақтан басталады және келген сабағынан ерте ұсынылмайды; өз тіркеуін өшіре алады', async () => {
    const mine = row(1, 'Әлібеков', { arrived_lesson: 3, can_undo_arrival: true })
    const calls = renderAt('/register', teacher, {
      'GET /api/teacher/classes': ok(context),
      'GET /api/teacher/classes/5': ok({ class: { id: 5, name: '7Ә' }, students: [mine, row(2, 'Сынақов', { left_lesson: 4 })] }),
      'DELETE /api/teacher/students/1/arrival': ok({ student: row(1, 'Әлібеков') }),
    })
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: '7Ә' }))
    await user.click(await screen.findByRole('button', { name: /Әлібеков Оқушы/ }))
    await user.click(screen.getByRole('button', { name: 'Кетіп қалды' }))
    const lessons = within(screen.getByRole('group', { name: 'Қай сабақтан кетіп қалды?' })).getAllByRole('button').map((b) => b.textContent)
    expect(lessons).toEqual(['3-сабақ', '4-сабақ', '5-сабақ', '6-сабақ', '7-сабақ'])

    await user.click(screen.getByRole('button', { name: '«Келді» тіркеуін өшіру' }))
    expect(await screen.findByRole('status')).toHaveTextContent('Әлібеков Оқушы: тіркеу өшірілді.')
    expect(calls.some((c) => c.key === 'DELETE /api/teacher/students/1/arrival')).toBe(true)

    // Басқа мұғалім тіркегенін өзгертуге болмайды.
    await user.click(screen.getByRole('button', { name: /Сынақов Оқушы.*4-сабақтан кетіп қалды/ }))
    expect(screen.getByRole('button', { name: 'Кетіп қалды' })).toBeDisabled()
    expect(screen.getByText(/басқа мұғалім тіркеген/)).toBeInTheDocument()
  })

  it('тегі бойынша іздеу үш әріптен басталады, нәтижеде сыныбы көрінеді', async () => {
    const calls = renderAt('/register', teacher, {
      'GET /api/teacher/classes': ok(context),
      [`GET /api/teacher/students?q=${encodeURIComponent('сын')}`]: ok({ students: [row(2, 'Сынақов'), row(8, 'Сынақбаев', { class: { id: 9, name: '9А' } })] }),
    })
    const user = userEvent.setup()

    const input = await screen.findByLabelText('Тегі бойынша іздеу')
    await user.type(input, 'сы')
    expect(screen.getByText('Кемінде үш әріп жазыңыз.')).toBeInTheDocument()
    await user.type(input, 'н')

    expect(await screen.findByRole('button', { name: /Сынақбаев Оқушы.*9А/ })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Сынақов Оқушы.*7Ә/ })).toBeInTheDocument()
    expect(calls.filter((c) => c.key.includes('/teacher/students?q='))).toHaveLength(1)
  })

  it('оқу күні емес күні тіркеу жабық; сервер қатесі түсінікті көрсетіледі', async () => {
    renderAt('/register', teacher, { 'GET /api/teacher/classes': ok({ ...context, is_school_day: false }) })
    expect(await screen.findByRole('alert')).toHaveTextContent('Бүгін оқу күні емес')
    expect(screen.queryByLabelText('Тегі бойынша іздеу')).not.toBeInTheDocument()
  })
})

describe('жетекшінің күн беті', () => {
  const routes = (data: Day): Record<string, Handler> => ({ 'GET /api/homeroom/classes/5/days/2026-11-20': ok(data) })

  it('күннің күйі, кезекші туралы мәлімет және шешім күтетіндер саны көрінеді', async () => {
    renderAt('/homeroom/5/2026-11-20', teacher, routes(day()))

    expect(await screen.findByRole('heading', { name: '7Ә сыныбы, 20 қараша' })).toBeInTheDocument()
    expect(screen.getByText('Кезекші сағат 14:12-де аралаған.')).toBeInTheDocument()
    expect(screen.getByText('2 оқушы бойынша шешім керек')).toBeInTheDocument()
    expect(screen.getByText('жоқ · себебі көрсетілмеген')).toBeInTheDocument()
    expect(screen.getByText('бар · 4-сабақтан кетіп қалды · рұқсаты анықталмаған')).toBeInTheDocument()
  })

  it('жоқ оқушыға себеп пен түсініктеме қойылады: тек өзгерген өрістер жіберіледі', async () => {
    const calls = renderAt('/homeroom/5/2026-11-20', teacher, {
      ...routes(day()),
      'PATCH /api/homeroom/classes/5/days/2026-11-20/students/2': ok({ student: dayRow(2, 'Сынақов', { round_status: 'absent', absence_reason: 'sick' }) }),
    })
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Сынақов Оқушы: өзгерту' }))
    // «Бар» оқушыға себеп ұсынылмайды, жоқ оқушыға рұқсат ұсынылмайды.
    expect(screen.queryByRole('group', { name: 'Кетуге рұқсат' })).not.toBeInTheDocument()
    await user.click(within(screen.getByRole('group', { name: 'Болмау себебі' })).getByRole('radio', { name: 'ауырды' }))
    await user.type(screen.getByLabelText('Түсініктеме'), 'анықтама әкеледі')
    await user.click(screen.getByRole('button', { name: 'Сақтау' }))

    await waitFor(() => expect(calls.find((c) => c.key.startsWith('PATCH'))?.body).toEqual({ absence_reason: 'sick', note: 'анықтама әкеледі' }))
  })

  it('кеткен оқушыға рұқсат қойылады; тарихы көрсетіледі', async () => {
    const calls = renderAt('/homeroom/5/2026-11-20', teacher, {
      ...routes(day()),
      'PATCH /api/homeroom/classes/5/days/2026-11-20/students/3': ok({ student: dayRow(3, 'Үлгіев', { left_lesson: 4, leave_permission: 'unpermitted' }) }),
      'GET /api/homeroom/classes/5/days/2026-11-20/students/3/history': ok({
        created: { at: '2026-11-20T14:12:00+05:00', by_duty: true },
        changes: [
          { field: 'left_lesson', old: null, new: '4', role: 'teacher', by: 'Сынақ Пәнші', device_number: null, at: '2026-11-20T16:40:00+05:00' },
          { field: 'round_status', old: 'absent', new: 'present', role: 'duty', by: null, device_number: 2, at: '2026-11-20T14:20:00+05:00' },
        ],
      }),
    })
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Үлгіев Оқушы: өзгерту' }))
    expect(screen.queryByRole('group', { name: 'Болмау себебі' })).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Тарихы' }))
    const history = await screen.findByRole('list', { name: 'Тарихы' })
    expect(history).toHaveTextContent('Кезекші белгілеген, 20 қараша, 14:12')
    expect(history).toHaveTextContent('Кеткен сабағы: бос → 4-сабақ · мұғалім Сынақ Пәнші, 20 қараша, 16:40')
    expect(history).toHaveTextContent('Белгі: жоқ → бар · кезекші (2-планшет), 20 қараша, 14:20')

    await user.click(within(screen.getByRole('group', { name: 'Кетуге рұқсат' })).getByRole('radio', { name: 'рұқсатсыз' }))
    await user.click(screen.getByRole('button', { name: 'Сақтау' }))
    await waitFor(() => expect(calls.find((c) => c.key.startsWith('PATCH'))?.body).toEqual({ leave_permission: 'unpermitted' }))
  })

  it('мерзімі өткен күн тек қаралады; аралау болмаған күн солай деп көрсетіледі', async () => {
    renderAt('/homeroom/5/2026-11-20', teacher, routes(day({ editable: false, duty: null })))
    const user = userEvent.setup()

    expect(await screen.findByText('Бұл күні кезекші сыныпты араламаған.')).toBeInTheDocument()
    expect(screen.getByText('Бұл күнді енді тек завуч өзгерте алады.')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Сынақов Оқушы: өзгерту' }))
    expect(screen.queryByRole('button', { name: 'Сақтау' })).not.toBeInTheDocument()
    expect(screen.getByLabelText('Түсініктеме')).toBeDisabled()
  })

  it('завуч сынып бетінен кез келген сыныптың қатысуын аша алады', async () => {
    renderAt('/admin/attendance/5/2026-11-20', admin, routes(day()))

    expect(await screen.findByRole('heading', { name: '7Ә сыныбы, 20 қараша' })).toBeInTheDocument()
    expect(screen.getByRole('navigation', { name: 'Негізгі мәзір' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: '← Сыныптар мен оқушылар' })).toHaveAttribute('href', '/admin/classes/5')
  })
})
