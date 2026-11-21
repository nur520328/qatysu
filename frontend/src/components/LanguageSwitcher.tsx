import { useTranslation } from 'react-i18next'
import { useAuth } from '../auth/context'
import { LOCALES, LOCALE_NAMES } from '../i18n'

export function LanguageSwitcher() {
  const { t, i18n } = useTranslation()
  const { changeLocale } = useAuth()

  return (
    <div className="lang" role="group" aria-label={t('lang.label')}>
      {LOCALES.map((locale) => (
        <button
          key={locale}
          type="button"
          lang={locale}
          className={locale === i18n.language ? 'lang__btn lang__btn--on' : 'lang__btn'}
          aria-pressed={locale === i18n.language}
          onClick={() => changeLocale(locale)}
        >
          {LOCALE_NAMES[locale]}
        </button>
      ))}
    </div>
  )
}
