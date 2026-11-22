import { useEffect, useState } from 'react'
import type { CSSProperties } from 'react'
import { useTranslation } from 'react-i18next'
import type { Roster } from '../../api/duty'
import { Shell } from '../../components/Shell'
import type { OutboxEntry } from '../../duty/db'
import type { ClassView, DutyStore } from '../../duty/store'
import { displayNames } from '../../lib/attendance'
import { useErrorText } from '../../lib/errors'

interface Props {
  store: DutyStore
  target: ClassView
  /** Смена аяқталған: тек қарауға болады. */
  readOnly: boolean
  /** Сақталды не артқа қайтты; мәтін сыныптар бетінде көрсетіледі. */
  onDone: (flash: string | null) => void
}

/** Бір сыныпты белгілеу: бәрі «бар» болып ашылады, жоқ оқушы басылады. Тізім планшеттегі қоймадан оқылады. */
export function DutyClassPage({ store, target, readOnly, onDone }: Props) {
  const { t } = useTranslation()
  const errorText = useErrorText()
  const [loaded, setLoaded] = useState<{ roster: Roster; state: OutboxEntry['state'] | null } | 'missing' | null>(null)
  const [absent, setAbsent] = useState<Set<number>>(new Set())
  const [dirty, setDirty] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let alive = true
    store.roster(target.id).then((found) => {
      if (!alive) return
      if (found === null) {
        setLoaded('missing')
        return
      }
      setLoaded({ roster: found.roster, state: found.entry?.state ?? null })
      setAbsent(new Set(found.roster.students.filter((s) => s.status === 'absent').map((s) => s.id)))
    }, () => { if (alive) setLoaded('missing') })
    return () => { alive = false }
  }, [store, target.id])

  function toggle(id: number) {
    setAbsent((current) => {
      const next = new Set(current)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
    setDirty(true)
  }

  async function save() {
    if (busy) return
    setBusy(true)
    setError(null)
    try {
      // Белгілер планшетке жазылады; серверге жіберу артқы жақта жүреді, оның күйі сыныптар бетінде көрінеді.
      await store.save(target.id, [...absent])
      onDone(t('duty.savedLocal', { name: target.name }))
    } catch (caught) {
      setError(errorText(caught))
      setBusy(false)
    }
  }

  function back() {
    if (dirty && !window.confirm(t('duty.leaveConfirm'))) return
    onDone(null)
  }

  const roster = loaded !== null && loaded !== 'missing' ? loaded.roster : null
  const state = loaded !== null && loaded !== 'missing' ? loaded.state : null
  const total = roster?.students.length ?? 0
  const names = displayNames(roster?.students ?? [])

  return (
    <Shell wide about={false} actions={<button className="btn" type="button" onClick={back}>{t('duty.back')}</button>}>
      <section className="page page--duty">
        <header className="page__head">
          <h1 className="page__title">{t('duty.classTitle', { name: target.name })}</h1>
          {!readOnly && <p className="muted">{t('duty.classLead')}</p>}
        </header>

        {readOnly && <p className="note" role="status">{t('duty.readOnly')}</p>}
        {!readOnly && state === 'review' && <p className="alert" role="alert">{t('duty.review')}</p>}
        {!readOnly && state === 'conflict' && <p className="note" role="status">{t('duty.conflict', { name: target.name })}</p>}
        {error !== null && <p className="alert" role="alert">{error}</p>}
        {loaded === null && <p className="muted" role="status">{t('common.loading')}</p>}
        {loaded === 'missing' && <p className="alert" role="alert">{t('duty.notStored')}</p>}
        {roster !== null && total === 0 && <p className="alert">{t('duty.emptyClass')}</p>}

        {roster !== null && total > 0 && (
          <>
            <ol className="marks" style={{ '--rows2': Math.ceil(total / 2), '--rows3': Math.ceil(total / 3) } as CSSProperties}>
              {roster.students.map((student, index) => {
                const isAbsent = absent.has(student.id)
                return (
                  <li key={student.id}>
                    <button
                      type="button"
                      className={isAbsent ? 'mark mark--absent' : 'mark'}
                      aria-pressed={isAbsent}
                      disabled={student.locked || busy || readOnly}
                      onClick={() => toggle(student.id)}
                    >
                      <span className="mark__no">{index + 1}</span>
                      <span className="mark__name">{names.get(student.id)}</span>
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
              {!readOnly && (
                <button className="btn btn--strong savebar__btn" type="button" disabled={busy} onClick={() => void save()}>
                  {busy ? t('duty.saving') : t('duty.save')}
                </button>
              )}
            </div>
          </>
        )}
      </section>
    </Shell>
  )
}
