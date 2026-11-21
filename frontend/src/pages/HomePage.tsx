import { useTranslation } from 'react-i18next'
import type { User } from '../api/client'
import { useAuth } from '../auth/context'
import { Shell } from '../components/Shell'

/** 2-кезеңдегі уақытша бастапқы бет: кім кіргенін және рөлін көрсетеді. */
export function HomePage({ user }: { user: User }) {
  const { t } = useTranslation()
  const { logout } = useAuth()

  const roles = [t(user.role === 'admin' ? 'home.role.admin' : 'home.role.teacher')]
  if (user.homeroom_classes.length > 0) {
    roles.push(t('home.role.homeroom', { classes: user.homeroom_classes.join(', ') }))
  }

  return (
    <Shell
      actions={
        <button className="btn" type="button" onClick={() => void logout()}>
          {t('home.logout')}
        </button>
      }
    >
      <section className="card">
        <h1 className="card__title">{t('home.greeting', { name: user.full_name })}</h1>
        <ul className="roles">
          {roles.map((role) => (
            <li key={role} className="roles__item">
              {role}
            </li>
          ))}
        </ul>
        <p className="card__text">{t('home.placeholder')}</p>
      </section>
    </Shell>
  )
}
