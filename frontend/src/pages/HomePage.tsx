import { useCallback } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import type { User } from '../api/client'
import { homeroomApi } from '../api/staff'
import { teacherApi } from '../api/teacher'
import { useAuth } from '../auth/context'
import { Shell } from '../components/Shell'
import { dayMonth } from '../lib/dates'
import { useErrorText } from '../lib/errors'
import { useLoad } from '../lib/useLoad'

/**
 * Мұғалімнің бастапқы беті: кешігу мен кетуді тіркеуге өту; жетекші болса, шешім күтіп тұрған
 * оқушылар және өз сыныбының бүгінгі қатысуы.
 */
export function HomePage({ user }: { user: User }) {
  const { t, i18n } = useTranslation()
  const { logout } = useAuth()
  const errorText = useErrorText()
  const fetcher = useCallback(async () => {
    const [homeroom, pending] = await Promise.all([teacherApi.homeroom(), homeroomApi.pending()])
    return { ...homeroom, pending }
  }, [])
  const { data, error } = useLoad(fetcher)

  return (
    <Shell
      wide
      actions={
        <button className="btn" type="button" onClick={() => void logout()}>
          {t('home.logout')}
        </button>
      }
    >
      <section className="page page--narrow">
        <h1 className="page__title">{t('home.greeting', { name: user.full_name })}</h1>
        <Link className="btn btn--strong btn--big" to="/register">{t('register.open')}</Link>

        {error !== null && <p className="alert" role="alert">{errorText(error)}</p>}
        {data === undefined && error === null && <p className="muted">{t('common.loading')}</p>}

        {data !== undefined && data.classes.length > 0 && (
          <>
            <section className="group" aria-label={t('homePending.title')}>
              <h2 className="panel__title">{t('homePending.title')}</h2>
              {data.pending.length === 0 ? (
                <p className="syncbar syncbar--ok">{t('homePending.empty')}</p>
              ) : (
                <ul className="roster">
                  {data.pending.map((item) => (
                    <li key={`${item.date}-${item.student.id}-${item.kind}`} className="roster__item">
                      <Link className="pick" to={`/homeroom/${item.class.id}/${item.date}`}>
                        <span className="pick__name">{item.student.name}<span className="pick__class"> · {item.class.name}, {dayMonth(item.date, i18n.language)}</span></span>
                        <span className="pick__state pick__state--warn">{t(`homePending.${item.kind}`)}</span>
                      </Link>
                    </li>
                  ))}
                </ul>
              )}
            </section>

            <section className="group" aria-label={t('homePending.myClass')}>
              <h2 className="panel__title">{t('homePending.myClass')}</h2>
              <div className="row">
                {data.classes.map(({ class: cls, students }) => (
                  <Link key={cls.id} className="btn" to={`/homeroom/${cls.id}/${data.date}`}>
                    {t('classPage.title', { name: cls.name })} · {t('classes.students', { count: students.length })} · {t('day.openToday')}
                  </Link>
                ))}
              </div>
            </section>
          </>
        )}
      </section>
    </Shell>
  )
}
