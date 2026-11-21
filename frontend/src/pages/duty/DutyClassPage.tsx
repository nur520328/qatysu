import { useCallback, useEffect, useState } from 'react'
import type { CSSProperties } from 'react'
import { useTranslation } from 'react-i18next'
import { ApiError } from '../../api/client'
import { dutyApi } from '../../api/duty'
import type { DutyClass, Roster } from '../../api/duty'
import { Shell } from '../../components/Shell'
import { useErrorText } from '../../lib/errors'
import { uuid } from '../../lib/uuid'
import { isDutyEnded } from './ended'

interface Props {
  target: DutyClass
  /** Сақталды не артқа қайтты; мәтін сыныптар бетінде көрсетіледі. */
  onDone: (flash: string | null) => void
  onEnded: () => void
}

/** Бір сыныпты белгілеу: бәрі «бар» болып ашылады, жоқ оқушы басылады. */
export function DutyClassPage({ target, onDone, onEnded }: Props) {
  const { t } = useTranslation()
  const errorText = useErrorText()
  const [roster, setRoster] = useState<Roster | null>(null)
  const [absent, setAbsent] = useState<Set<number>>(new Set())
  const [dirty, setDirty] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  // Жаңа аралаудың нөмірі бет ашылғанда бір рет жасалады: байланыс үзіліп, «Сақтау» қайта басылса да,
  // сервер оны сол бір аралау деп таниды.
  const [freshUuid] = useState(uuid)

  const apply = useCallback((data: Roster) => {
    setRoster(data)
    setAbsent(new Set(data.students.filter((s) => s.status === 'absent').map((s) => s.id)))
    setDirty(false)
  }, [])

  const fail = useCallback((caught: unknown) => {
    if (isDutyEnded(caught)) onEnded()
    else setError(errorText(caught))
  }, [errorText, onEnded])

  useEffect(() => {
    let alive = true
    dutyApi.roster(target.id).then((data) => { if (alive) apply(data) }, (caught: unknown) => { if (alive) fail(caught) })
    return () => { alive = false }
  }, [target.id, apply, fail])

  function toggle(id: number) {
    setAbsent((current) => {
      const next = new Set(current)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
    setDirty(true)
    setNotice(null)
  }

  async function save() {
    if (roster === null || busy) return
    setBusy(true)
    setError(null)
    setNotice(null)
    try {
      const result = await dutyApi.saveRound(target.id, {
        client_uuid: roster.round?.client_uuid ?? freshUuid,
        absent_student_ids: [...absent],
        revision: roster.round?.revision ?? null,
        client_time: new Date().toISOString(),
      })
      if (result.stored_as === 'round' && result.kept_locked.length === 0) {
        onDone(t('duty.saved', { name: target.name }))
        return
      }
      // Сервердегі күй кезекші жібергеннен өзгеше: оны көрсетіп, себебін айтамыз.
      apply(result)
      setNotice(result.stored_as === 'conflict' ? t('duty.conflict', { name: target.name }) : t('duty.keptLocked'))
    } catch (caught) {
      const stale = caught instanceof ApiError && (caught.code === 'round_changed' || caught.code === 'unknown_students')
      if (stale) {
        // Тізім не белгілер серверде өзгерген: жаңасын жүктеп, кезекші қайта тексереді.
        setError(errorText(caught))
        await dutyApi.roster(target.id).then(apply, fail)
      } else {
        fail(caught)
      }
    } finally {
      setBusy(false)
    }
  }

  function back() {
    if (dirty && !window.confirm(t('duty.leaveConfirm'))) return
    onDone(null)
  }

  const total = roster?.students.length ?? 0

  return (
    <Shell wide actions={<button className="btn" type="button" onClick={back}>{t('duty.back')}</button>}>
      <section className="page page--duty">
        <header className="page__head">
          <h1 className="page__title">{t('duty.classTitle', { name: target.name })}</h1>
          <p className="muted">{t('duty.classLead')}</p>
        </header>

        {notice !== null && <p className="note" role="status">{notice}</p>}
        {error !== null && <p className="alert" role="alert">{error}</p>}
        {roster === null && error === null && <p className="muted" role="status">{t('common.loading')}</p>}
        {roster !== null && total === 0 && <p className="alert">{t('duty.emptyClass')}</p>}

        {roster !== null && total > 0 && (
          <>
            <ol className="marks" style={{ '--rows2': Math.ceil(total / 2), '--rows3': Math.ceil(total / 3) } as CSSProperties}>
              {roster.students.map((student, index) => {
                const isAbsent = absent.has(student.id)
                const name = [student.last_name, student.first_name].join(' ')
                return (
                  <li key={student.id}>
                    <button
                      type="button"
                      className={isAbsent ? 'mark mark--absent' : 'mark'}
                      aria-pressed={isAbsent}
                      disabled={student.locked || busy}
                      onClick={() => toggle(student.id)}
                    >
                      <span className="mark__no">{index + 1}</span>
                      <span className="mark__name">{name}</span>
                      <span className="mark__state">
                        {student.locked ? t('duty.locked') : isAbsent ? t('duty.absentMark') : t('duty.present')}
                      </span>
                    </button>
                  </li>
                )
              })}
            </ol>

            <div className="savebar">
              <p className="savebar__sum" role="status">{t('duty.summary', { present: total - absent.size, absent: absent.size })}</p>
              <button className="btn btn--strong savebar__btn" type="button" disabled={busy} onClick={() => void save()}>
                {busy ? t('duty.saving') : t('duty.save')}
              </button>
            </div>
          </>
        )}
      </section>
    </Shell>
  )
}
