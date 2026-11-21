import { useCallback, useState } from 'react'
import type { FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { adminApi } from '../../api/admin'
import type { Shift } from '../../api/admin'
import { useErrorText } from '../../lib/errors'
import { useLoad } from '../../lib/useLoad'

export function ClassesPage() {
  const { t } = useTranslation()
  const errorText = useErrorText()
  const fetcher = useCallback(() => adminApi.classes(), [])
  const { data, error: loadError, reload } = useLoad(fetcher)
  const error = loadError === null ? null : errorText(loadError)

  if (data === undefined) {
    return error === null ? <p className="muted">{t('common.loading')}</p> : <p className="alert" role="alert">{error}</p>
  }
  const { year, classes } = data
  if (year === null) {
    return (
      <section className="page">
        <h1 className="page__title">{t('classes.title')}</h1>
        <p className="alert" role="alert">{t('errors.no_current_year')}</p>
        <Link className="btn btn--strong" to="/admin/calendar">{t('calendar.createTitle')}</Link>
      </section>
    )
  }

  const grades = [...new Set(classes.map((c) => c.grade))]
  const students = classes.reduce((sum, c) => sum + (c.student_count ?? 0), 0)

  return (
    <section className="page">
      <header className="page__head">
        <div>
          <h1 className="page__title">{t('classes.title')}</h1>
          <p className="muted">{year.name} · {t('classes.total', { classes: classes.length, students })}</p>
        </div>
        <Link className="btn btn--strong" to="/admin/import/students">{t('classes.importStudents')}</Link>
      </header>

      {error !== null && <p className="alert" role="alert">{error}</p>}

      {classes.length === 0 && <p className="muted">{t('classes.empty')}</p>}
      {grades.map((grade) => (
        <section key={grade} className="group" aria-label={t('classes.gradeHeading', { grade })}>
          <h2 className="group__title">{t('classes.gradeHeading', { grade })}</h2>
          <ul className="tiles">
            {classes.filter((c) => c.grade === grade).map((c) => (
              <li key={c.id}>
                <Link className={c.is_active ? 'tile' : 'tile tile--off'} to={`/admin/classes/${c.id}`}>
                  <strong className="tile__name">{c.name}</strong>
                  <span className="tile__line">{t('classes.students', { count: c.student_count ?? 0 })}</span>
                  <span className="tile__line">{t(c.shift === 'morning' ? 'classes.shiftMorning' : 'classes.shiftAfternoon')}</span>
                  <span className="tile__line tile__line--small">
                    {c.is_active ? (c.homeroom?.full_name ?? t('classes.noHomeroom')) : t('classes.closed')}
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        </section>
      ))}

      <BulkForm onDone={reload} />
    </section>
  )
}

/** Бір параллельдің сыныптарын бірден ашу: 44 сыныпты бір-бірлеп енгізбеу үшін. */
function BulkForm({ onDone }: { onDone: () => Promise<void> }) {
  const { t } = useTranslation()
  const errorText = useErrorText()
  const [grade, setGrade] = useState('5')
  const [letters, setLetters] = useState('')
  const [shift, setShift] = useState<Shift>('morning')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [result, setResult] = useState<{ created: string[]; skipped: string[] } | null>(null)

  const parsed = letters.toUpperCase().split(/[\s,;]+/u).filter((l) => l !== '')

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    setBusy(true)
    setError(null)
    setResult(null)
    try {
      setResult(await adminApi.createClasses({ grade: Number(grade), letters: parsed, shift }))
      setLetters('')
      await onDone()
    } catch (caught) {
      setError(errorText(caught))
    } finally {
      setBusy(false)
    }
  }

  return (
    <form className="panel form" onSubmit={onSubmit}>
      <h2 className="panel__title">{t('classes.bulkTitle')}</h2>
      <div className="row">
        <label className="field">
          <span className="field__label">{t('classes.grade')}</span>
          <select className="field__input" value={grade} onChange={(e) => setGrade(e.target.value)}>
            {Array.from({ length: 11 }, (_, i) => i + 1).map((g) => <option key={g} value={g}>{g}</option>)}
          </select>
        </label>
        <div className="field field--grow">
          <label className="field__label" htmlFor="bulk-letters">{t('classes.letters')}</label>
          <input id="bulk-letters" className="field__input" value={letters} aria-describedby="bulk-letters-hint"
            autoComplete="off" onChange={(e) => setLetters(e.target.value)} />
          <span className="field__hint" id="bulk-letters-hint">{t('classes.lettersHint')}</span>
        </div>
        <label className="field">
          <span className="field__label">{t('classes.shift')}</span>
          <select className="field__input" value={shift} onChange={(e) => setShift(e.target.value as Shift)}>
            <option value="morning">{t('classes.shiftMorning')}</option>
            <option value="afternoon">{t('classes.shiftAfternoon')}</option>
          </select>
        </label>
      </div>
      {error !== null && <p className="alert" role="alert">{error}</p>}
      {result !== null && (
        <p className="note" role="status">
          {result.created.length > 0 && t('classes.created', { list: result.created.join(', ') })}
          {result.created.length > 0 && result.skipped.length > 0 && ' '}
          {result.skipped.length > 0 && t('classes.skipped', { list: result.skipped.join(', ') })}
        </p>
      )}
      <button className="btn btn--strong" type="submit" disabled={busy || parsed.length === 0}>
        {t('classes.addClasses')}
      </button>
    </form>
  )
}
