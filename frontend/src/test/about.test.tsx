import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { User } from '../api/client'
import { App } from '../App'
import { AuthProvider } from '../auth/AuthContext'
import { setLocale } from '../i18n'
import { mockServer } from './mockServer'
import type { Handler } from './mockServer'

const admin: User = { id: 1, full_name: 'Сынақ Завуч', role: 'admin', locale: 'kk', must_change_password: false, homeroom_classes: [] }
const ok = (body: unknown): Handler => () => ({ status: 200, body })
const guest: Handler = () => ({ status: 401, body: { code: 'unauthenticated' } })
const settings = { max_lessons_per_shift: 7, homeroom_edit_days: 14, pending_reason_days: 3, morning_shift_end: 790, afternoon_shift_end: 1120 }

function renderAt(path: string, routes: Record<string, Handler>) {
  const calls = mockServer({ 'GET /api/auth/me': guest, ...routes })
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

describe('«Платформа туралы» беті', () => {
  it('кірмеген адам кіру бетінен ашады: мақсаты, авторы, байланысы, нұсқасы мен суреті', async () => {
    const user = userEvent.setup()
    renderAt('/', { 'GET /api/about': ok({ version: '0.0.10.2', photo: '/api/about/photo?v=1795400000' }) })

    await user.click(await screen.findByRole('link', { name: 'Платформа туралы' }))
    expect(await screen.findByRole('heading', { name: 'Платформа туралы' })).toBeInTheDocument()
    expect(await screen.findByText('Сабаққа қатысу · Нұсқасы: 0.0.10.2')).toBeInTheDocument()
    expect(screen.getByRole('region', { name: 'Жобаның мақсаты' })).toHaveTextContent('мектеп жұмысын автоматтандыру және қағазбастылықтан құтылу')

    const author = screen.getByRole('region', { name: 'Автор' })
    expect(within(author).getByText('Әбеу Нұргелді')).toBeInTheDocument()
    expect(within(author).getAllByRole('listitem').map((item) => item.textContent)).toEqual([
      'Химия пәні мұғалімі', 'Педагог-шебер', 'PhD докторант', '«ҮЗДІК ПЕДАГОГ-2025» грантының иегері',
    ])
    expect(within(author).getByRole('img', { name: 'Автордың суреті: Әбеу Нұргелді' })).toHaveAttribute('src', '/api/about/photo?v=1795400000')

    const contacts = screen.getByRole('region', { name: 'Байланыс' })
    expect(within(contacts).getByRole('link', { name: 'nur520328@gmail.com' })).toHaveAttribute('href', 'mailto:nur520328@gmail.com')
    const phones = within(contacts).getAllByRole('link', { name: '+7 778 513 15 87' })
    expect(phones.map((link) => link.getAttribute('href'))).toEqual(['https://wa.me/77785131587', 'https://t.me/+77785131587'])

    // «Артқа» кіру бетіне қайтарады; бұл беттің өзінде қайталанатын сілтеме жоқ.
    expect(screen.queryByRole('link', { name: 'Платформа туралы' })).not.toBeInTheDocument()
    await user.click(screen.getByRole('link', { name: '← Артқа' }))
    expect(await screen.findByLabelText('Логин')).toBeInTheDocument()
  })

  it('сервер қолжетімсіз болса да мәтін көрінеді (нұсқа мен суретсіз); орысша мен қытайша аударылған', async () => {
    const first = renderAt('/about', { 'GET /api/about': () => ({ status: 500, body: { code: 'server_error' } }) })
    expect(await screen.findByText('Әбеу Нұргелді')).toBeInTheDocument()
    expect(screen.queryByText(/Нұсқасы/)).not.toBeInTheDocument()
    expect(screen.queryByRole('img')).not.toBeInTheDocument()
    first.unmount()

    setLocale('ru')
    const second = renderAt('/about', { 'GET /api/about': ok({ version: '0.0.10.2', photo: null }) })
    expect(await screen.findByRole('heading', { name: 'О платформе' })).toBeInTheDocument()
    expect(screen.getByText('Педагог-мастер')).toBeInTheDocument()
    expect(screen.getByText('Әбеу Нұргелді')).toBeInTheDocument()
    second.unmount()

    setLocale('zh')
    renderAt('/about', { 'GET /api/about': ok({ version: '0.0.10.2', photo: null }) })
    expect(await screen.findByText('艾别吾·努尔开力德')).toBeInTheDocument()
    expect(screen.getByText('化学教师')).toBeInTheDocument()
  })

  it('завуч «Баптаудан» суретті жүктейді және алып тастайды', async () => {
    const user = userEvent.setup()
    let sent: unknown = null
    const calls = renderAt('/admin/settings', {
      'GET /api/auth/me': ok({ user: admin }),
      'GET /api/admin/settings': ok({ settings }),
      'GET /api/admin/backups': ok({ last_at: '2026-11-19T21:30:00+00:00', stale: false, backups: [] }),
      'GET /api/about': ok({ version: '0.0.10.2', photo: null }),
      'POST /api/admin/about/photo': (init) => {
        sent = (init as RequestInit).body instanceof FormData ? ((init as RequestInit).body as FormData).get('photo') : null
        return { status: 200, body: { version: '0.0.10.2', photo: '/api/about/photo?v=2' } }
      },
      'DELETE /api/admin/about/photo': ok({ version: '0.0.10.2', photo: null }),
    })

    const panel = await screen.findByRole('region', { name: '«Платформа туралы» бетіндегі сурет' })
    expect(await within(panel).findByText('Сурет әлі жүктелмеген.')).toBeInTheDocument()
    expect(within(panel).getByText(/қосымша дерек \(түсірілген орны, телефон үлгісі\) жойылады/)).toBeInTheDocument()
    expect(within(panel).getByRole('button', { name: 'Жүктеу' })).toBeDisabled()

    const file = new File(['жасанды сурет'], 'suret.jpg', { type: 'image/jpeg' })
    await user.upload(within(panel).getByLabelText(/Сурет таңдау/), file)
    await user.click(within(panel).getByRole('button', { name: 'Жүктеу' }))

    expect(await within(panel).findByText('Сурет сақталды.')).toBeInTheDocument()
    expect(within(panel).getByRole('img', { name: 'Қазіргі сурет' })).toHaveAttribute('src', '/api/about/photo?v=2')
    expect(sent).toBeInstanceOf(File)
    expect((sent as File).name).toBe('suret.jpg')

    await user.click(within(panel).getByRole('button', { name: 'Суретті алып тастау' }))
    expect(await within(panel).findByText('Сурет алып тасталды.')).toBeInTheDocument()
    await waitFor(() => expect(within(panel).queryByRole('img')).not.toBeInTheDocument())
    expect(calls.map((c) => c.key)).toContain('DELETE /api/admin/about/photo')
  })
})
