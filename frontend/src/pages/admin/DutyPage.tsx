import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { adminApi } from '../../api/admin'
import type { DutyDay, DutyShift } from '../../api/admin'
import { clockTime, dayMonth } from '../../lib/dates'
import { useErrorText } from '../../lib/errors'
import { useLoad } from '../../lib/useLoad'

/** Завуч кезекші кодын береді және аралау барысын көреді. */
export function DutyPage() {
  const { t, i18n } = useTranslation()
  const errorText = useErrorText()
  const fetcher = useCallback(() => adminApi.dutyCodes(), [])
  const { data, error: loadError, reload } = useLoad(fetcher)
  const [actionError, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const error = actionError ?? (loadError === null ? null : errorText(loadError))

  // Аралау барысы мен кірген планшеттер саны бет ашық тұрғанда өзі жаңарып тұрады.
  useEffect(() => {
    const timer = window.setInterval(() => void reload(), 30_000)
    return () => window.clearInterval(timer)
  }, [reload])

  async function run(action: () => Promise<unknown>) {
    setBusy(true)
    setError(null)
    try {
      await action()
      await reload()
    } catch (caught) {
      setError(errorText(caught))
    } finally {
      setBusy(false)
    }
  }

  if (data === undefined) {
    return error === null ? <p className="muted">{t('common.loading')}</p> : <p className="alert" role="alert">{error}</p>
  }

  const tabletUrl = `${window.location.host}/kezekshi`

  return (
    <section className="page">
      <header>
        <h1 className="page__title">{t('dutyAdmin.title')}</h1>
        <p className="muted">{t('dutyAdmin.lead')}</p>
        <p className="muted">{t('dutyAdmin.tabletHint', { url: tabletUrl })}</p>
      </header>

      {data.login_blocked && <p className="alert" role="alert">{t('dutyAdmin.blocked')}</p>}
      {error !== null && <p className="alert" role="alert">{error}</p>}
      {data.days.length === 0 && <p className="alert">{t('dutyAdmin.noSchoolDay')}</p>}

      {data.days.map((day) => {
        const title = t(day.is_today ? 'dutyAdmin.today' : 'dutyAdmin.nextDay', { date: dayMonth(day.date, i18n.language) })
        return (
          <section key={day.date} className="group" aria-label={title}>
            <h2 className="panel__title">{title}</h2>
            <div className="shifts">
              {day.shifts.map((shift) => (
                <ShiftCard key={shift.shift} day={day} shift={shift} busy={busy}
                  onIssue={() => {
                    if (shift.code !== null && !window.confirm(t('dutyAdmin.reissueConfirm'))) return
                    void run(() => adminApi.issueDutyCode(day.date, shift.shift))
                  }}
                  onRevoke={(id) => {
                    if (!window.confirm(t('dutyAdmin.revokeConfirm'))) return
                    void run(() => adminApi.revokeDutyCode(id))
                  }} />
              ))}
            </div>
          </section>
        )
      })}
    </section>
  )
}

interface CardProps {
  day: DutyDay
  shift: DutyShift
  busy: boolean
  onIssue: () => void
  onRevoke: (id: number) => void
}

function ShiftCard({ day, shift, busy, onIssue, onRevoke }: CardProps) {
  const { t, i18n } = useTranslation()
  const name = t(shift.shift === 'morning' ? 'dutyAdmin.shiftMorning' : 'dutyAdmin.shiftAfternoon')

  return (
    <section className="panel form" aria-label={name}>
      <h3 className="panel__title">
        {name} · {shift.ended ? t('dutyAdmin.ended') : t('dutyAdmin.endsAt', { time: clockTime(shift.ends_at) })}
      </h3>

      {shift.classes_total === 0 && <p className="muted">{t('dutyAdmin.noClasses')}</p>}

      {shift.code?.code != null && (
        <p className="code" aria-label={t('dutyAdmin.code')}>
          {shift.code.code.slice(0, 3)} {shift.code.code.slice(3)}
        </p>
      )}
      {shift.code === null && !shift.ended && <p className="muted">{t('dutyAdmin.noCode')}</p>}

      {shift.code !== null && (
        <p className="muted">
          {t('dutyAdmin.issuedBy', { name: shift.code.issued_by, when: `${dayMonth(shift.code.issued_at.slice(0, 10), i18n.language)}, ${clockTime(shift.code.issued_at)}` })}
          {' · '}
          {shift.code.devices === 0 ? t('dutyAdmin.noDevices') : t('dutyAdmin.devices', { count: shift.code.devices })}
        </p>
      )}

      {day.is_today && shift.classes_total > 0 && (
        <p className="panel__lead">{t('dutyAdmin.progress', { done: shift.classes_done, total: shift.classes_total })}</p>
      )}

      {!shift.ended && shift.classes_total > 0 && (
        <div className="row">
          <button className={shift.code === null ? 'btn btn--strong' : 'btn'} type="button" disabled={busy} onClick={onIssue}>
            {t(shift.code === null ? 'dutyAdmin.issue' : 'dutyAdmin.reissue')}
          </button>
          {shift.code !== null && (
            <button className="btn btn--danger" type="button" disabled={busy} onClick={() => onRevoke(shift.code!.id)}>
              {t('dutyAdmin.revoke')}
            </button>
          )}
        </div>
      )}
    </section>
  )
}
