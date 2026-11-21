import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { adminApi } from '../../api/admin'
import type { ConflictSide, DutyDay, DutyShift, RoundConflict } from '../../api/admin'
import { clockTime, dayMonth } from '../../lib/dates'
import { useErrorText } from '../../lib/errors'
import { useLoad } from '../../lib/useLoad'

/** Завуч кезекші кодын береді және аралау барысын көреді. */
export function DutyPage() {
  const { t, i18n } = useTranslation()
  const errorText = useErrorText()
  const fetcher = useCallback(async () => {
    const [codes, conflicts] = await Promise.all([adminApi.dutyCodes(), adminApi.roundConflicts()])
    return { ...codes, conflicts }
  }, [])
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

      {data.conflicts.length > 0 && (
        <section className="group" aria-label={t('conflicts.title')}>
          <h2 className="panel__title">{t('conflicts.title')}</h2>
          <p className="muted">{t('conflicts.lead')}</p>
          {data.conflicts.map((conflict) => (
            <ConflictCard key={conflict.id} conflict={conflict} busy={busy}
              onResolve={(resolution) => {
                if (resolution === 'replace' && !window.confirm(t('conflicts.replaceConfirm', { name: conflict.class.name }))) return
                void run(() => adminApi.resolveConflict(conflict.id, resolution))
              }} />
          ))}
        </section>
      )}

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

function ConflictCard({ conflict, busy, onResolve }: { conflict: RoundConflict; busy: boolean; onResolve: (r: 'keep' | 'replace') => void }) {
  const { t, i18n } = useTranslation()
  const title = t('conflicts.classDate', { name: conflict.class.name, date: dayMonth(conflict.date, i18n.language) })
  const device = (side: ConflictSide) => (side.device_number === null ? t('conflicts.deviceUnknown') : t('conflicts.device', { n: side.device_number }))

  const sides = [
    { key: 'keep' as const, label: t('conflicts.current'), side: conflict.current, when: t('conflicts.savedAt', { time: clockTime(conflict.current.saved_at) }) },
    {
      key: 'replace' as const, label: t('conflicts.incoming'), side: conflict.incoming,
      when: conflict.incoming.marked_at === null ? '' : t('conflicts.markedAt', { time: clockTime(conflict.incoming.marked_at) }),
    },
  ]

  return (
    <section className="panel form" aria-label={title}>
      <h3 className="panel__title">{title}</h3>
      <div className="shifts">
        {sides.map(({ key, label, side, when }) => (
          <section key={key} className="side" aria-label={label}>
            <h4 className="side__title">{label}</h4>
            <p className="muted">{[device(side), when].filter(Boolean).join(' · ')}</p>
            <p className="panel__lead">{side.absent.length === 0 ? t('conflicts.nobody') : t('conflicts.absentTitle', { count: side.absent.length })}</p>
            {side.absent.length > 0 && <ul className="side__list">{side.absent.map((s) => <li key={s.id}>{s.name}</li>)}</ul>}
            <button className={key === 'keep' ? 'btn' : 'btn btn--strong'} type="button" disabled={busy} onClick={() => onResolve(key)}>
              {t(`conflicts.${key}`)}
            </button>
          </section>
        ))}
      </div>
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
