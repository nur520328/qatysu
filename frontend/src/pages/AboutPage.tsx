import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { aboutApi } from '../api/about'
import { Shell } from '../components/Shell'
import { useLoad } from '../lib/useLoad'

// Автордың өзі берген байланыс дерегі (2026-11-23). Бет кірмеген адамға да ашық: бұл автордың шешімі.
const CONTACTS = [
  { key: 'email', href: 'mailto:nur520328@gmail.com', text: 'nur520328@gmail.com' },
  { key: 'whatsapp', href: 'https://wa.me/77785131587', text: '+7 778 513 15 87' },
  { key: 'telegram', href: 'https://t.me/+77785131587', text: '+7 778 513 15 87' },
] as const

/**
 * «Платформа туралы»: мақсаты, авторы, байланыс. Мәтін аудармаларда; серверден тек нұсқа мен сурет келеді,
 * сондықтан интернет жоқ кезде де бет ашылады (нұсқа мен суретсіз).
 */
export function AboutPage() {
  const { t } = useTranslation()
  const { data } = useLoad(aboutApi.state)

  return (
    <Shell about={false}>
      <section className="page page--narrow about">
        <Link className="back" to="/">{t('about.back')}</Link>
        <h1 className="page__title">{t('about.title')}</h1>
        <p className="muted">
          {t('app.name')}
          {data !== undefined && <> · {t('about.version', { version: data.version })}</>}
        </p>

        <section className="panel" aria-label={t('about.purposeTitle')}>
          <h2 className="panel__title">{t('about.purposeTitle')}</h2>
          <p>{t('about.purpose')}</p>
        </section>

        <section className="panel about__author" aria-label={t('about.authorTitle')}>
          {data?.photo != null && <img className="about__photo" src={data.photo} alt={t('about.photoAlt')} width={160} height={160} />}
          <div>
            <h2 className="panel__title">{t('about.authorTitle')}</h2>
            <p className="about__name">{t('about.authorName')}</p>
            <ul className="about__roles">
              <li>{t('about.role1')}</li>
              <li>{t('about.role2')}</li>
              <li>{t('about.role3')}</li>
              <li>{t('about.role4')}</li>
            </ul>
          </div>
        </section>

        <section className="panel" aria-label={t('about.contactTitle')}>
          <h2 className="panel__title">{t('about.contactTitle')}</h2>
          <dl className="about__contacts">
            {CONTACTS.map((contact) => (
              <div key={contact.key}>
                <dt>{t(`about.${contact.key}`)}</dt>
                <dd><a href={contact.href} target="_blank" rel="noreferrer">{contact.text}</a></dd>
              </div>
            ))}
          </dl>
        </section>
      </section>
    </Shell>
  )
}
