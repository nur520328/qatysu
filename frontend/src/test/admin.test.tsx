import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { Account, SchoolClass, Student, Year } from '../api/admin'
import type { User } from '../api/client'
import { App } from '../App'
import { AuthProvider } from '../auth/AuthContext'
import { setLocale } from '../i18n'
import { mockServer } from './mockServer'
import type { Handler } from './mockServer'

// Барлық дерек ойдан шығарылған: сынақта нақты оқушы не мұғалім аты қолданылмайды.
const admin: User = { id: 1, full_name: 'Сынақ Завуч', role: 'admin', locale: 'kk', must_change_password: false, homeroom_classes: [] }
const teacher: User = { ...admin, id: 7, full_name: 'Сынақ Мұғалім', role: 'teacher' }
const year: Year = { id: 3, name: '2026–2027', start_date: '2026-09-01', end_date: '2027-05-25', is_current: true }

function cls(id: number, name: string, extra: Partial<SchoolClass> = {}): SchoolClass {
  return {
    id, name, grade: Number.parseInt(name, 10), letter: name.replace(/\d+/, ''), shift: 'morning', language: 'kk',
    is_active: true, homeroom: null, student_count: 25, ...extra,
  }
}

function account(id: number, full_name: string, extra: Partial<Account> = {}): Account {
  return { id, full_name, login: `login${id}`, role: 'teacher', is_active: true, must_change_password: false, homeroom_classes: [], ...extra }
}

const student: Student = { id: 40, last_name: 'Сынақов', first_name: 'Бірінші', patronymic: null, class_id: 11 }
const ok = (body: unknown): Handler => () => ({ status: 200, body })

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

describe('сыныптар', () => {
  it('завуч кіргенде сыныптар параллель бойынша көрсетіледі', async () => {
    renderAt('/', admin, {
      'GET /api/admin/classes': ok({
        year,
        classes: [cls(11, '5А', { homeroom: { id: 9, full_name: 'Сынақ Жетекші' } }), cls(12, '5Ә'), cls(21, '6А', { shift: 'afternoon', is_active: false })],
      }),
    })

    const fifth = await screen.findByRole('region', { name: '5-сыныптар' })
    expect(within(fifth).getAllByRole('link')).toHaveLength(2)
    expect(within(fifth).getByText('Сынақ Жетекші')).toBeInTheDocument()
    expect(within(screen.getByRole('region', { name: '6-сыныптар' })).getByText('жабық')).toBeInTheDocument()
    expect(screen.getByText(/3 сынып, 75 оқушы/)).toBeInTheDocument()
    expect(within(screen.getByRole('navigation', { name: 'Негізгі мәзір' })).getAllByRole('link')).toHaveLength(5)
  })

  it('оқу жылы ашылмаған болса, күнтізбеге жол сілтейді', async () => {
    renderAt('/admin/classes', admin, { 'GET /api/admin/classes': ok({ year: null, classes: [] }) })
    expect(await screen.findByRole('alert')).toHaveTextContent('Алдымен оқу жылын ашыңыз.')
    expect(screen.getByRole('link', { name: 'Оқу жылын ашу' })).toHaveAttribute('href', '/admin/calendar')
  })

  it('бірнеше сынып бірден ашылады: әріптер бас әріпке айналып жіберіледі', async () => {
    const calls = renderAt('/admin/classes', admin, {
      'GET /api/admin/classes': ok({ year, classes: [] }),
      'POST /api/admin/classes/bulk': () => ({ status: 201, body: { created: ['7А', '7Ә'], skipped: [] } }),
    })
    const user = userEvent.setup()

    await user.selectOptions(await screen.findByLabelText('Сынып (параллель)'), '7')
    await user.type(screen.getByLabelText('Литералар'), 'а ә')
    await user.click(screen.getByRole('button', { name: 'Сыныптарды ашу' }))

    await waitFor(() => expect(calls.find((c) => c.key === 'POST /api/admin/classes/bulk')?.body).toEqual({ grade: 7, letters: ['А', 'Ә'], shift: 'morning' }))
    expect(await screen.findByRole('status')).toHaveTextContent('7А, 7Ә')
  })
})

describe('сынып беті', () => {
  const routes = {
    'GET /api/admin/classes/11/students': ok({ class: cls(11, '5А'), date: '2026-11-20', students: [student] }),
    'GET /api/admin/classes': ok({ year, classes: [cls(11, '5А'), cls(12, '5Ә')] }),
    'GET /api/admin/users': ok({ users: [account(9, 'Сынақ Жетекші')] }),
  }

  it('оқушы қосылады: аты-жөні шетіндегі бос орынсыз жіберіледі', async () => {
    const calls = renderAt('/admin/classes/11', admin, {
      ...routes,
      'POST /api/admin/classes/11/students': () => ({ status: 201, body: { student } }),
    })
    const user = userEvent.setup()

    const form = (await screen.findByRole('heading', { name: 'Оқушы қосу' })).closest('form')!
    await user.type(within(form).getByLabelText('Тегі'), ' Жаңаев ')
    await user.type(within(form).getByLabelText('Аты'), 'Екінші')
    await user.click(within(form).getByRole('button', { name: 'Қосу' }))

    await waitFor(() =>
      expect(calls.find((c) => c.key === 'POST /api/admin/classes/11/students')?.body).toEqual({ last_name: 'Жаңаев', first_name: 'Екінші', patronymic: null }),
    )
    await waitFor(() => expect(within(form).getByLabelText('Тегі')).toHaveValue(''))
  })

  it('сервер қатесі таңдалған тілде көрсетіледі, толтырылған өрістер сақталады', async () => {
    renderAt('/admin/classes/11', admin, {
      ...routes,
      'POST /api/admin/classes/11/students': () => ({ status: 409, body: { code: 'student_exists' } }),
    })
    const user = userEvent.setup()

    const form = (await screen.findByRole('heading', { name: 'Оқушы қосу' })).closest('form')!
    await user.type(within(form).getByLabelText('Тегі'), 'Сынақов')
    await user.type(within(form).getByLabelText('Аты'), 'Бірінші')
    await user.click(within(form).getByRole('button', { name: 'Қосу' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Бұл оқушы осы сыныпта бар.')
    expect(within(form).getByLabelText('Тегі')).toHaveValue('Сынақов')
  })

  it('оқушыны шығару растаусыз орындалмайды', async () => {
    const calls = renderAt('/admin/classes/11', admin, routes)
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false)
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Сынақов Бірінші: әрекеттер' }))
    await user.click(screen.getByRole('button', { name: 'Шығару' }))

    expect(confirm).toHaveBeenCalledOnce()
    expect(calls.some((c) => c.key.includes('/withdraw'))).toBe(false)
  })
})

describe('мұғалімдер', () => {
  const users = [account(1, 'Сынақ Завуч', { role: 'admin', login: 'zavuch' }), account(9, 'Сынақ Жетекші', { homeroom_classes: ['5А'], must_change_password: true })]

  it('жаңа бір реттік пароль бір рет көрсетіледі', async () => {
    renderAt('/admin/users', admin, {
      'GET /api/admin/users': ok({ users }),
      'GET /api/admin/classes': ok({ year, classes: [] }),
      'POST /api/admin/users/9/reset-password': ok({ user: users[1], one_time_password: 'abcd-efgh-jkmn' }),
    })
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    vi.spyOn(window, 'scrollTo').mockImplementation(() => undefined)
    const user = userEvent.setup()

    expect(await screen.findByText(/5А жетекшісі/)).toBeInTheDocument()
    expect(screen.getByText('Әлі кірмеген')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Сынақ Жетекші: әрекеттер' }))
    await user.click(screen.getByRole('button', { name: 'Жаңа бір реттік пароль беру' }))

    const panel = await screen.findByRole('region', { name: 'Бір реттік пароль: Сынақ Жетекші' })
    expect(within(panel).getByText('abcd-efgh-jkmn')).toBeInTheDocument()
    await user.click(within(panel).getByRole('button', { name: 'Жаздым, жасыру' }))
    expect(screen.queryByText('abcd-efgh-jkmn')).not.toBeInTheDocument()
  })

  it('завуч өз аккаунтын өшіре алмайды; іздеу тізімді сүзеді', async () => {
    renderAt('/admin/users', admin, { 'GET /api/admin/users': ok({ users }), 'GET /api/admin/classes': ok({ year, classes: [] }) })
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Сынақ Завуч: әрекеттер' }))
    expect(screen.queryByRole('button', { name: 'Аккаунтты өшіру' })).not.toBeInTheDocument()

    await user.type(screen.getByRole('searchbox'), 'жетекші')
    expect(screen.queryByText('Сынақ Завуч')).not.toBeInTheDocument()
    expect(screen.getByText('Сынақ Жетекші')).toBeInTheDocument()
  })

  it('жетекші мұғалімдер бетінен тағайындалады; бос емес сынып растаумен ауыстырылады', async () => {
    const taken = cls(12, '5Ә', { homeroom: { id: 30, full_name: 'Сынақ Бұрынғы' } })
    const calls = renderAt('/admin/users', admin, {
      'GET /api/admin/users': ok({ users }),
      'GET /api/admin/classes': ok({ year, classes: [cls(11, '5А', { homeroom: { id: 9, full_name: 'Сынақ Жетекші' } }), taken, cls(21, '6А', { is_active: false })] }),
      'PATCH /api/admin/classes/12': ok({ class: taken }),
      'PATCH /api/admin/classes/11': ok({ class: taken }),
    })
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: 'Сынақ Жетекші: әрекеттер' }))
    const group = screen.getByRole('group', { name: 'Сынып жетекшілігі' })
    const select = within(group).getByLabelText('Сынып жетекшісі ету')
    // Өз сыныбы мен жабық сынып таңдауда жоқ.
    expect(within(select).getAllByRole('option').map((o) => o.textContent)).toEqual(['— сыныпты таңдаңыз —', '5Ә (қазір: Сынақ Бұрынғы)'])
    await user.selectOptions(select, '12')
    await user.click(within(group).getByRole('button', { name: 'Тағайындау' }))

    expect(confirm).toHaveBeenCalledWith('5Ә сыныбының жетекшісі қазір Сынақ Бұрынғы. Ауыстырылсын ба?')
    await waitFor(() => expect(calls.find((c) => c.key === 'PATCH /api/admin/classes/12')?.body).toEqual({ homeroom_user_id: 9 }))

    await user.click(within(screen.getByRole('group', { name: 'Сынып жетекшілігі' })).getByRole('button', { name: '5А жетекшілігінен босату' }))
    await waitFor(() => expect(calls.find((c) => c.key === 'PATCH /api/admin/classes/11')?.body).toEqual({ homeroom_user_id: null }))
  })

  it('Excel-ден жүктеу: алдын ала қарау, растау, парольдер парағы', async () => {
    const preview = {
      digest: 'd1',
      summary: { new: 1, exists: 0, errors: 1, warnings: 0 },
      new: [{ row: 3, full_name: 'Сынақ Жаңа', login: 'synaq.zh', homeroom: '5А', warning: null }],
      problems: [{ row: 4, last_name: 'Қате', first_name: null, code: 'missing_first_name' }],
      credentials: null,
    }
    const calls = renderAt('/admin/import/teachers', admin, {
      'POST /api/admin/import/teachers': (_init, body) => ({
        status: 200,
        body: (body as { digest?: string }).digest === undefined
          ? preview
          : { ...preview, credentials: [{ full_name: 'Сынақ Жаңа', login: 'synaq.zh', password: 'wxyz-wxyz-wxyz', homeroom: '5А' }] },
      }),
    })
    const user = userEvent.setup()

    expect(await screen.findByRole('link', { name: 'Үлгі файлды жүктеп алу' })).toHaveAttribute('href', '/templates/mugalimder-ulgi.xlsx')
    await user.upload(screen.getByLabelText('Excel файл (.xlsx)'), new File(['x'], 'tizim.xlsx'))
    await user.click(screen.getByRole('button', { name: 'Алдын ала қарау' }))

    expect(await screen.findByText('Аты жазылмаған.')).toBeInTheDocument()
    expect(screen.queryByText('wxyz-wxyz-wxyz')).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: '1 аккаунт ашу' }))

    const sheet = await screen.findByRole('region', { name: 'Логиндер мен бір реттік парольдер' })
    expect(within(sheet).getByText('wxyz-wxyz-wxyz')).toBeInTheDocument()
    expect(calls.filter((c) => c.key === 'POST /api/admin/import/teachers').map((c) => (c.body as { digest?: string }).digest)).toEqual([undefined, 'd1'])

    // Растамаса тізім жабылмайды.
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false)
    await user.click(within(sheet).getByRole('button', { name: 'Басып шығардым, тізімді жабу' }))
    expect(confirm).toHaveBeenCalledOnce()
    expect(screen.getByText('wxyz-wxyz-wxyz')).toBeInTheDocument()
  })
})

describe('күнтізбе мен баптау', () => {
  it('екі күн таңдалып, демалыс деп белгіленеді', async () => {
    const days = ['2026-11-02', '2026-11-03', '2026-11-04'].map((date) => ({ date, is_school_day: true, note: null }))
    const calls = renderAt('/admin/calendar', admin, {
      'GET /api/admin/academic-years': ok({ years: [year] }),
      'GET /api/admin/academic-years/3/calendar': ok({ year, days, school_day_count: 3 }),
      'PUT /api/admin/academic-years/3/calendar': ok({ changed: 2 }),
    })
    const user = userEvent.setup()

    await user.click(await screen.findByRole('button', { name: /^4 қараша/ }))
    await user.click(screen.getByRole('button', { name: /^3 қараша/ }))
    await user.type(screen.getByLabelText(/Ескертпе/), ' Каникул ')
    await user.click(screen.getByRole('button', { name: 'Оқу күні емес деп белгілеу' }))

    await waitFor(() =>
      expect(calls.find((c) => c.key.startsWith('PUT'))?.body).toEqual({ from: '2026-11-03', to: '2026-11-04', is_school_day: false, note: 'Каникул' }),
    )
  })

  it('баптау сан түрінде сақталады', async () => {
    const settings = { max_lessons_per_shift: 7, homeroom_edit_days: 14, pending_reason_days: 3, morning_shift_end: 790, afternoon_shift_end: 1120 }
    const calls = renderAt('/admin/settings', admin, {
      'GET /api/admin/settings': ok({ settings }),
      'PATCH /api/admin/settings': ok({ settings }),
    })
    const user = userEvent.setup()

    const field = await screen.findByLabelText(/Бір сменадағы ең көп сабақ саны/)
    await user.clear(field)
    await user.type(field, '8')
    await user.click(screen.getByRole('button', { name: 'Сақтау' }))

    expect(await screen.findByRole('status')).toHaveTextContent('Сақталды.')
    expect(calls.find((c) => c.key.startsWith('PATCH'))?.body).toEqual({ ...settings, max_lessons_per_shift: 8 })
  })
})

describe('сақтық көшірме', () => {
  const backup = { name: 'qatysu-20261120-023000.sql.gz', size: 22388, created_at: '2026-11-19T21:30:00+00:00', version: '0.0.3.7', tables: 14, rows: 2649 }
  const settings = { max_lessons_per_shift: 7, homeroom_edit_days: 14, pending_reason_days: 3, morning_shift_end: 790, afternoon_shift_end: 1120 }

  it('баптау бетінде соңғы көшірме Қазақстан уақытымен көрінеді, қолмен жасауға және жүктеуге болады', async () => {
    const calls = renderAt('/admin/settings', admin, {
      'GET /api/admin/settings': ok({ settings }),
      'GET /api/admin/backups': ok({ last_at: backup.created_at, stale: false, backups: [backup] }),
      'POST /api/admin/backups': () => ({ status: 201, body: { backup } }),
    })
    const user = userEvent.setup()

    const panel = await screen.findByRole('region', { name: 'Сақтық көшірме' })
    expect(await within(panel).findByText('Соңғы көшірме: 20 қараша, 02:30')).toBeInTheDocument()
    expect(within(panel).getByRole('link', { name: '20 қараша, 02:30 күнгі көшірмені жүктеп алу' }))
      .toHaveAttribute('href', '/api/admin/backups/qatysu-20261120-023000.sql.gz/download')
    expect(within(panel).getByText('22 КБ')).toBeInTheDocument()

    await user.click(within(panel).getByRole('button', { name: 'Қазір көшірме жасау' }))
    expect(await within(panel).findByRole('status')).toHaveTextContent('Көшірме жасалды.')
    expect(calls.filter((c) => c.key === 'POST /api/admin/backups')).toHaveLength(1)
  })

  it('көшірме ескірсе, завуч кез келген бетте ескерту көреді', async () => {
    renderAt('/admin/classes', admin, {
      'GET /api/admin/classes': ok({ year, classes: [] }),
      'GET /api/admin/backups': ok({ last_at: null, stale: true, backups: [] }),
    })

    const banner = await screen.findByText(/соңғы тәулікте сақтық көшірме жасалмаған/)
    expect(within(banner).getByRole('link', { name: 'Баптауды ашу' })).toHaveAttribute('href', '/admin/settings')
  })

  it('күндік шек таусылса, түсінікті хабар шығады', async () => {
    renderAt('/admin/settings', admin, {
      'GET /api/admin/settings': ok({ settings }),
      'POST /api/admin/backups': () => ({ status: 429, body: { code: 'backup_limit_reached' } }),
    })
    const user = userEvent.setup()

    const panel = await screen.findByRole('region', { name: 'Сақтық көшірме' })
    await user.click(within(panel).getByRole('button', { name: 'Қазір көшірме жасау' }))
    expect(await within(panel).findByRole('alert')).toHaveTextContent('Бүгін көшірме жеткілікті жасалды.')
  })
})

describe('рөлдер', () => {
  it('мұғалімге әкімші беттері ашылмайды, сұраныс та жіберілмейді', async () => {
    const calls = renderAt('/admin/users', teacher, { 'GET /api/teacher/homeroom': ok({ date: '2026-11-20', classes: [] }), 'GET /api/homeroom/pending': ok({ items: [] }) })
    expect(await screen.findByRole('heading', { name: 'Сәлеметсіз бе, Сынақ Мұғалім!' })).toBeInTheDocument()
    expect(calls.some((c) => c.key.includes('/api/admin/'))).toBe(false)
  })
})
