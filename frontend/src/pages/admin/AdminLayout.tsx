import { NavLink, Outlet } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../../auth/context'
import { Shell } from '../../components/Shell'

const LINKS = [
  { to: '/admin/classes', key: 'nav.classes' },
  { to: '/admin/users', key: 'nav.users' },
  { to: '/admin/calendar', key: 'nav.calendar' },
  { to: '/admin/settings', key: 'nav.settings' },
] as const

/** Әкімші беттерінің ортақ жиегі: мәзір және мазмұн. */
export function AdminLayout() {
  const { t } = useTranslation()
  const { logout } = useAuth()

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
        <Outlet />
      </div>
    </Shell>
  )
}
