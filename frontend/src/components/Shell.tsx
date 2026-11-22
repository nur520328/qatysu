import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { LanguageSwitcher } from './LanguageSwitcher'

interface ShellProps {
  children: ReactNode
  actions?: ReactNode
  /** Кең беттер (кестелер, тізімдер) үшін мазмұн экран еніне жайылады. */
  wide?: boolean
  /** Төменгі жақтағы «Платформа туралы» сілтемесі. Кезекшінің планшетінде жоқ: ол аралаудан алаңдатпауы тиіс. */
  about?: boolean
}

/** Барлық беттің ортақ жиегі: атау, тіл ауыстырғыш, мазмұн. */
export function Shell({ children, actions, wide = false, about = true }: ShellProps) {
  const { t } = useTranslation()

  return (
    <div className="shell">
      <header className="shell__bar">
        <div className="shell__name">{t('app.name')}</div>
        <div className="shell__tools">
          <LanguageSwitcher />
          {actions}
        </div>
      </header>
      <main className={wide ? 'shell__main shell__main--wide' : 'shell__main'}>{children}</main>
      {about && (
        <footer className="shell__foot no-print">
          <Link to="/about">{t('about.link')}</Link>
        </footer>
      )}
    </div>
  )
}
