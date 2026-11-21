import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { LanguageSwitcher } from './LanguageSwitcher'

interface ShellProps {
  children: ReactNode
  actions?: ReactNode
  /** Кең беттер (кестелер, тізімдер) үшін мазмұн экран еніне жайылады. */
  wide?: boolean
}

/** Барлық беттің ортақ жиегі: атау, тіл ауыстырғыш, мазмұн. */
export function Shell({ children, actions, wide = false }: ShellProps) {
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
    </div>
  )
}
