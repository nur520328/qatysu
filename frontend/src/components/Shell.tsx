import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { LanguageSwitcher } from './LanguageSwitcher'

/** Барлық беттің ортақ жиегі: атау, тіл ауыстырғыш, мазмұн. */
export function Shell({ children, actions }: { children: ReactNode; actions?: ReactNode }) {
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
      <main className="shell__main">{children}</main>
    </div>
  )
}
