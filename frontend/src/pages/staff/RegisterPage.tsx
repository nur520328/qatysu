import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { teacherApi2 } from '../../api/staff'
import type { TeacherRow } from '../../api/staff'
import { useAuth } from '../../auth/context'
import { Shell } from '../../components/Shell'
import { displayNames, shortStatus } from '../../lib/attendance'
import { dayMonth } from '../../lib/dates'
import { useErrorText } from '../../lib/errors'
import { useLoad } from '../../lib/useLoad'

/**
 * Пән мұғалімі: «N-сабақтан келді» не «N-сабақтан кетіп қалды». Телефонға арналған.
 * Бір тіркеу: сынып → оқушы → «Келді» не «Кетіп қалды» → сабақ нөмірі (төрт басу). Тегі бойынша іздеуге де болады.
 */
export function RegisterPage() {
  const { t, i18n } = useTranslation()
  const { logout } = useAuth()
  const errorText = useErrorText()
  const fetcher = useCallback(() => teacherApi2.context(), [])
  const { data: context, error: loadError } = useLoad(fetcher)
  const [query, setQuery] = useState('')
  const [classId, setClassId] = useState<number | null>(null)
  const [rows, setRows] = useState<TeacherRow[] | null>(null)
  const [picked, setPicked] = useState<TeacherRow | null>(null)
  const [flash, setFlash] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  const searching = query.trim().length >= 3
  const needle = query.trim()

  // Тізім: іздеу нәтижесі не таңдалған сыныптың оқушылары.
  useEffect(() => {
    if (!searching && classId === null) return
    let alive = true
    const load = searching ? teacherApi2.search(needle) : teacherApi2.classToday(classId as number).then((r) => r.students)
    // Теріп жатқанда әр әріпке сұраныс кетпеуі үшін сәл күтеміз.
    const timer = window.setTimeout(() => {
      load.then((found) => { if (alive) { setRows(found); setError(null) } }, (caught: unknown) => { if (alive) setError(errorText(caught)) })
    }, searching ? 250 : 0)
    return () => { alive = false; window.clearTimeout(timer) }
  }, [searching, needle, classId, errorText])

  function replaceRow(updated: TeacherRow, message: string) {
    setRows((current) => current?.map((row) => (row.id === updated.id ? updated : row)) ?? null)
    setPicked(null)
    setFlash(message)
    setError(null)
  }

  if (context === undefined) {
    return (
      <Shell>
        {loadError === null ? <p className="muted">{t('common.loading')}</p> : <p className="alert" role="alert">{errorText(loadError)}</p>}
      </Shell>
    )
  }

  const grades = [...new Set(context.classes.map((c) => c.grade))]
  const current = context.classes.find((c) => c.id === classId)
  const showList = (searching || classId !== null) && rows !== null
  const names = displayNames(rows ?? [])

  return (
    <Shell wide actions={<button className="btn" type="button" onClick={() => void logout()}>{t('home.logout')}</button>}>
      <section className="page page--narrow">
        <header>
          <Link className="back" to="/">← {t('common.back')}</Link>
          <h1 className="page__title">{t('register.title')}</h1>
          <p className="muted">{t('register.today', { date: dayMonth(context.date, i18n.language) })}</p>
        </header>

        {!context.is_school_day ? (
          <p className="alert" role="alert">{t('register.notSchoolDay')}</p>
        ) : (
          <>
            {flash !== null && <p className="note" role="status">{flash}</p>}
            {error !== null && <p className="alert" role="alert">{error}</p>}

            <label className="field">
              <span className="field__label">{t('register.search')}</span>
              <input className="field__input" type="search" value={query} autoComplete="off"
                onChange={(e) => { setQuery(e.target.value); setRows(null); setPicked(null); setFlash(null) }} />
              {query.trim() !== '' && !searching && <span className="field__hint">{t('register.searchHint')}</span>}
            </label>

            {!searching && classId === null && (
              <section aria-label={t('register.pickClass')}>
                <h2 className="group__title">{t('register.pickClass')}</h2>
                {grades.map((grade) => (
                  <div key={grade} className="chips" role="group" aria-label={t('register.grade', { grade })}>
                    {context.classes.filter((c) => c.grade === grade).map((c) => (
                      <button key={c.id} className="chip" type="button" onClick={() => { setClassId(c.id); setRows(null); setFlash(null) }}>{c.name}</button>
                    ))}
                  </div>
                ))}
              </section>
            )}

            {!searching && current !== undefined && (
              <div className="row row--end">
                <h2 className="panel__title">{t('register.classTitle', { name: current.name })}</h2>
                <button className="btn" type="button" onClick={() => { setClassId(null); setRows(null); setPicked(null) }}>{t('register.otherClass')}</button>
              </div>
            )}

            {showList && rows.length === 0 && <p className="muted">{t(searching ? 'register.nothingFound' : 'classPage.empty')}</p>}
            {showList && rows.length > 0 && (
              <ul className="roster" aria-label={t('register.pickStudent')}>
                {rows.map((row) => (
                  <li key={row.id} className="roster__item">
                    <button className="pick" type="button" aria-expanded={picked?.id === row.id}
                      onClick={() => { setPicked(picked?.id === row.id ? null : row); setFlash(null) }}>
                      <span className="pick__name">{names.get(row.id)}{searching && <span className="pick__class"> · {row.class.name}</span>}</span>
                      <span className={row.round_status === 'absent' || row.left_lesson !== null ? 'pick__state pick__state--warn' : 'pick__state'}>{shortStatus(row, t)}</span>
                    </button>
                    {picked?.id === row.id && (
                      <Actions row={row} name={names.get(row.id) ?? ''} maxLessons={context.max_lessons}
                        onDone={replaceRow} onError={(caught) => setError(errorText(caught))} />
                    )}
                  </li>
                ))}
              </ul>
            )}
          </>
        )}
      </section>
    </Shell>
  )
}

interface ActionsProps {
  row: TeacherRow
  name: string
  maxLessons: number
  onDone: (row: TeacherRow, message: string) => void
  onError: (error: unknown) => void
}

function Actions({ row, name, maxLessons, onDone, onError }: ActionsProps) {
  const { t } = useTranslation()
  const [kind, setKind] = useState<'arrival' | 'leave' | null>(null)
  const [busy, setBusy] = useState(false)

  async function run(action: () => Promise<TeacherRow>, message: string) {
    setBusy(true)
    try {
      onDone(await action(), message)
    } catch (caught) {
      onError(caught)
      setBusy(false)
    }
  }

  // Басқа мұғалім тіркегенін өзгертуге болмайды.
  const arrivalTaken = row.arrived_lesson !== null && !row.can_undo_arrival
  const leaveTaken = row.left_lesson !== null && !row.can_undo_leave
  // Кету сабағы келу сабағынан ерте болмайды; кету 2-сабақтан басталады.
  const lessons = Array.from({ length: maxLessons }, (_, i) => i + 1).filter((n) => (kind === 'leave' ? n >= Math.max(2, row.arrived_lesson ?? 2) : row.left_lesson === null || n <= row.left_lesson))

  return (
    <div className="roster__actions">
      <div className="row">
        <button className={kind === 'arrival' ? 'btn btn--strong' : 'btn'} type="button" disabled={busy || arrivalTaken} aria-pressed={kind === 'arrival'} onClick={() => setKind('arrival')}>
          {t('register.arrivedBtn')}
        </button>
        <button className={kind === 'leave' ? 'btn btn--strong' : 'btn'} type="button" disabled={busy || leaveTaken} aria-pressed={kind === 'leave'} onClick={() => setKind('leave')}>
          {t('register.leftBtn')}
        </button>
      </div>
      {(arrivalTaken || leaveTaken) && <p className="muted">{t('register.byOther')}</p>}

      {kind !== null && (
        <div role="group" aria-label={t(kind === 'arrival' ? 'register.whichLessonArrived' : 'register.whichLessonLeft')}>
          <p className="panel__lead">{t(kind === 'arrival' ? 'register.whichLessonArrived' : 'register.whichLessonLeft')}</p>
          <div className="chips">
            {lessons.map((n) => (
              <button key={n} className="chip chip--lesson" type="button" disabled={busy}
                onClick={() => void run(() => teacherApi2.register(row.id, kind, n), t(kind === 'arrival' ? 'register.savedArrival' : 'register.savedLeave', { name, n }))}>
                {t('att.lesson', { n })}
              </button>
            ))}
          </div>
        </div>
      )}

      {(row.can_undo_arrival || row.can_undo_leave) && (
        <div className="row">
          {row.can_undo_arrival && (
            <button className="btn btn--danger" type="button" disabled={busy} onClick={() => void run(() => teacherApi2.undo(row.id, 'arrival'), t('register.undone', { name }))}>
              {t('register.undoArrival')}
            </button>
          )}
          {row.can_undo_leave && (
            <button className="btn btn--danger" type="button" disabled={busy} onClick={() => void run(() => teacherApi2.undo(row.id, 'leave'), t('register.undone', { name }))}>
              {t('register.undoLeave')}
            </button>
          )}
        </div>
      )}
    </div>
  )
}
