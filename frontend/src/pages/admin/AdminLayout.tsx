import { useCallback } from 'react'
import { Link, NavLink, Outlet, useLocation } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { adminApi } from '../../api/admin'
import { useAuth } from '../../auth/context'
import { Shell } from '../../components/Shell'
import { useLoad } from '../../lib/useLoad'

const LINKS = [
  { to: '/admin/duty', key: 'nav.duty' },
  { to: '/admin/summary', key: 'nav.summary' },
  { to: '/admin/reports', key: 'nav.reports' },
  { to: '/admin/classes', key: 'nav.classes' },
  { to: '/admin/users', key: 'nav.users' },
  { to: '/admin/calendar', key: 'nav.calendar' },
  { to: '/admin/settings', key: 'nav.settings' },
] as const

/** Әкімші беттерінің ортақ жиегі: мәзір және мазмұн. */
export function AdminLayout() {
  const { t } = useTranslation()
  const { logout } = useAuth()
  const { pathname } = useLocation()
  // Түнгі көшірме жасалмай қалса, завуч оны қай бетте жүрсе де көруі тиіс (баптау бетінде толық нұсқасы тұр).
  const fetcher = useCallback(() => adminApi.backups(), [])
  const { data: backups } = useLoad(fetcher)
  const warn = backups?.stale === true && pathname !== '/admin/settings'

  return (
    <Shell
      wide
      actions={
        <button className="btn" type="button" onClick={() => void logout()}>
          {t('home.logout')}
        </button>
      }
    >
      <div className="admin">
        <nav className="admin__nav" aria-label={t('nav.label')}>
          {LINKS.map((link) => (
            <NavLink key={link.to} to={link.to} className={({ isActive }) => (isActive ? 'tab tab--on' : 'tab')}>
              {t(link.key)}
            </NavLink>
          ))}
        </nav>
        {warn && (
          <p className="alert no-print" role="alert">
            {t('backup.banner')} <Link to="/admin/settings">{t('backup.bannerLink')}</Link>
          </p>
        )}
        <Outlet />
      </div>
    </Shell>
  )
}
