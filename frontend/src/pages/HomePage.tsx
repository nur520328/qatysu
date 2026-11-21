import { useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import type { User } from '../api/client'
import { teacherApi } from '../api/teacher'
import { useAuth } from '../auth/context'
import { Shell } from '../components/Shell'
import { useErrorText } from '../lib/errors'
import { useLoad } from '../lib/useLoad'

/** Мұғалімнің бастапқы беті: кім кіргені және жетекші сыныбының бүгінгі тізімі (тек қарау үшін). */
export function HomePage({ user }: { user: User }) {
  const { t } = useTranslation()
  const { logout } = useAuth()
  const errorText = useErrorText()
  const fetcher = useCallback(() => teacherApi.homeroom(), [])
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
      <section className="page">
        <header>
          <h1 className="page__title">{t('home.greeting', { name: user.full_name })}</h1>
          <p className="muted">{t('home.placeholder')}</p>
        </header>

        {error !== null && <p className="alert" role="alert">{errorText(error)}</p>}
        {data === undefined && error === null && <p className="muted">{t('common.loading')}</p>}

        {data?.classes.map(({ class: cls, students }) => (
          <section key={cls.id} className="group" aria-label={t('classPage.title', { name: cls.name })}>
            <h2 className="panel__title">
              {t('classPage.title', { name: cls.name })} · {t('classes.students', { count: students.length })}
            </h2>
            {students.length === 0 ? (
              <p className="muted">{t('classPage.empty')}</p>
            ) : (
              <ol className="roster">
                {students.map((student, index) => (
                  <li key={student.id} className="roster__item">
                    <div className="roster__row roster__row--plain">
                      <span className="roster__no">{index + 1}</span>
                      <span className="roster__name">
                        {[student.last_name, student.first_name, student.patronymic].filter(Boolean).join(' ')}
                      </span>
                    </div>
                  </li>
                ))}
              </ol>
            )}
          </section>
        ))}
        {data !== undefined && data.classes.length > 0 && <p className="muted">{t('home.rosterNote')}</p>}
      </section>
    </Shell>
  )
}
