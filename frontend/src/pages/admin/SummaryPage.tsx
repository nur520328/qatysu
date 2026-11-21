import type { TFunction } from 'i18next'
import { useCallback, useEffect } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { adminApi } from '../../api/admin'
import type { DailySummary, SummaryClass, SummaryShift } from '../../api/admin'
import { fullStatus } from '../../lib/attendance'
import { clockTime, dayMonthWeekday } from '../../lib/dates'
import { useErrorText } from '../../lib/errors'
import { useLoad } from '../../lib/useLoad'

/**
 * Завучтың күнделікті жиынтығы: смена бойынша неше сынып аралынды, кім жоқ, кім кешікті, кім кетіп қалды,
 * қай сыныптар аралынбады. Күн мекенжайда сақталады (`?date=`), сондықтан сынып бетінен қайтқанда орнында тұрады.
 */
export function SummaryPage() {
  const { t, i18n } = useTranslation()
  const errorText = useErrorText()
  const [params, setParams] = useSearchParams()
  const date = /^\d{4}-\d{2}-\d{2}$/.test(params.get('date') ?? '') ? params.get('date') : null
  const fetcher = useCallback(() => adminApi.dailySummary(date), [date])
  const { data: loaded, error, reload } = useLoad(fetcher)
  // Күн ауысқанда жаңа жауап келгенше ескі күннің дерегі жаңа күннің атымен көрінбеуі тиіс.
  const data = loaded !== undefined && (date === null ? loaded.is_today : loaded.date === date) ? loaded : undefined
  const live = data?.is_today === true

  // Аралау жүріп жатқанда завуч бетті өзі жаңартып отырмауы үшін.
  useEffect(() => {
    if (!live) return undefined
    const timer = window.setInterval(() => void reload(), 60_000)
    return () => window.clearInterval(timer)
  }, [live, reload])

  if (data === undefined) {
    return error === null ? <p className="muted">{t('common.loading')}</p> : <p className="alert" role="alert">{errorText(error)}</p>
  }

  const label = dayMonthWeekday(data.date, i18n.language)
  const shifts = data.shifts.filter((shift) => shift.classes_total > 0)
  const go = (to: string | null) => setParams(to === null ? {} : { date: to })

  return (
    <section className="page">
      <header className="page__head">
        <div>
          <h1 className="page__title">{t('daySummary.title')}</h1>
          <p className="muted">{data.is_today ? t('daySummary.today', { date: label }) : label}</p>
        </div>
        <div className="row">
          <button className="btn" type="button" disabled={data.previous_school_day === null} onClick={() => go(data.previous_school_day)}>
            {t('daySummary.previous')}
          </button>
          <button className="btn" type="button" disabled={data.next_school_day === null} onClick={() => go(data.next_school_day)}>
            {t('daySummary.next')}
          </button>
          {!data.is_today && <button className="btn" type="button" onClick={() => go(null)}>{t('daySummary.toToday')}</button>}
          {data.is_today && <button className="btn" type="button" onClick={() => void reload()}>{t('daySummary.refresh')}</button>}
        </div>
      </header>

      {error !== null && <p className="alert" role="alert">{errorText(error)}</p>}
      {!data.is_school_day && <p className="alert">{t('daySummary.notSchoolDay')}</p>}
      {data.open_conflicts > 0 && (
        <p className="alert" role="alert">
          {t('daySummary.conflicts', { count: data.open_conflicts })} <Link to="/admin/duty">{t('daySummary.conflictsLink')}</Link>
        </p>
      )}
      {data.is_school_day && shifts.length === 0 && <p className="muted">{t('daySummary.noClasses')}</p>}

      {/* Оқу күні емес күні тек жазба болса ғана көрсетіледі (күнтізбе кейін өзгертілген болуы мүмкін). */}
      {shifts
        .filter((shift) => data.is_school_day || shift.classes.some((c) => c.surveyed || c.people.length > 0))
        .map((shift) => <ShiftSummary key={shift.shift} summary={data} shift={shift} />)}

      {live && <p className="muted">{t('daySummary.autoRefresh')}</p>}
    </section>
  )
}

function ShiftSummary({ summary, shift }: { summary: DailySummary; shift: SummaryShift }) {
  const { t } = useTranslation()
  const name = t(shift.shift === 'morning' ? 'dutyAdmin.shiftMorning' : 'dutyAdmin.shiftAfternoon')
  // Өткен күннің сменасы әрқашан аяқталған; бүгінгісі уақытына қарай.
  const running = summary.is_today && !shift.ended
  const missing = shift.classes.filter((c) => !c.surveyed)
  const withPeople = shift.classes.filter((c) => c.people.length > 0)
  const pending = shift.absent_by_reason.pending + shift.left_by_permission.pending
  const dayLink = (c: SummaryClass) => `/admin/attendance/${c.id}/${summary.date}`
  const stat = (value: number | string, text: string, warn = false) => (
    <div className={warn ? 'stat stat--warn' : 'stat'}><b>{value}</b><span>{text}</span></div>
  )

  return (
    <section className="group" aria-label={name}>
      <h2 className="panel__title">{name} · {t(running ? 'daySummary.shiftRunning' : 'daySummary.shiftEnded')}</h2>

      <div className="stats" role="status">
        {stat(`${shift.classes_surveyed} / ${shift.classes_total}`, t('daySummary.surveyed'), !running && missing.length > 0)}
        {stat(shift.students, t('daySummary.students'))}
        {stat(shift.absent, t('daySummary.absent'))}
        {stat(shift.late, t('daySummary.late'))}
        {stat(shift.left, t('daySummary.left'))}
        {stat(pending, t('daySummary.pending'), pending > 0)}
        {shift.unmarked > 0 && stat(shift.unmarked, t('daySummary.unmarked'), !running)}
      </div>

      {shift.absent > 0 && <p className="muted">{t('daySummary.absentBreakdown', shift.absent_by_reason)}</p>}
      {shift.left > 0 && <p className="muted">{t('daySummary.leftBreakdown', shift.left_by_permission)}</p>}

      {missing.length === 0 ? (
        <p className="muted">{t('daySummary.allSurveyed')}</p>
      ) : (
        <section className="panel" aria-label={t(running ? 'daySummary.notYetSurveyedTitle' : 'daySummary.notSurveyedTitle', { count: missing.length })}>
          <h3 className="panel__title">{t(running ? 'daySummary.notYetSurveyedTitle' : 'daySummary.notSurveyedTitle', { count: missing.length })}</h3>
          <ul className="summary__list">
            {missing.map((c) => (
              <li key={c.id}>
                <Link to={dayLink(c)} state={{ from: 'summary' }} aria-label={t('daySummary.openDay', { name: c.name })}>{c.name}</Link>
                <span className="muted"> · {c.homeroom === null ? t('daySummary.noHomeroom') : t('daySummary.homeroom', { name: c.homeroom })}</span>
              </li>
            ))}
          </ul>
        </section>
      )}

      <h3 className="group__title">{t('daySummary.peopleTitle')}</h3>
      {withPeople.length === 0 ? (
        <p className="muted">{t('daySummary.nobody')}</p>
      ) : (
        <div className="tablewrap">
          <table className="table">
            <thead>
              <tr><th>{t('daySummary.colClass')}</th><th>{t('daySummary.colStudent')}</th><th>{t('daySummary.colStatus')}</th></tr>
            </thead>
            <tbody>
              {withPeople.flatMap((c) => c.people.map((person, index) => (
                <tr key={`${c.id}-${person.id}`}>
                  {index === 0 && (
                    <th rowSpan={c.people.length} scope="rowgroup">
                      <Link to={dayLink(c)} state={{ from: 'summary' }} aria-label={t('daySummary.openDay', { name: c.name })}>{c.name}</Link>
                    </th>
                  )}
                  <td>{person.name}</td>
                  <td className={person.pending === null ? undefined : 'summary__pending'}>
                    {fullStatus(person, t)}
                    {person.note !== null && <span className="muted"> · {person.note}</span>}
                  </td>
                </tr>
              )))}
            </tbody>
          </table>
        </div>
      )}

      <h3 className="group__title">{t('daySummary.classesTitle')}</h3>
      <div className="tablewrap">
        <table className="table">
          <thead>
            <tr>
              <th>{t('daySummary.colClass')}</th>
              <th className="num">{t('daySummary.colStudents')}</th>
              <th className="num">{t('daySummary.colAbsent')}</th>
              <th className="num">{t('daySummary.colLate')}</th>
              <th className="num">{t('daySummary.colLeft')}</th>
              <th className="num">{t('daySummary.colUnmarked')}</th>
              <th>{t('daySummary.colRound')}</th>
            </tr>
          </thead>
          <tbody>
            {shift.classes.map((c) => (
              <tr key={c.id}>
                <th scope="row">
                  <Link to={dayLink(c)} state={{ from: 'summary' }} aria-label={t('daySummary.openDay', { name: c.name })}>{c.name}</Link>
                </th>
                <td className="num">{c.students}</td>
                <td className="num">{c.absent}</td>
                <td className="num">{c.late}</td>
                <td className="num">{c.left}</td>
                <td className="num">{c.unmarked}</td>
                <td className={c.surveyed || running ? undefined : 'summary__pending'}>{roundText(c, running, t)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  )
}

function roundText(c: SummaryClass, running: boolean, t: TFunction): string {
  if (c.saved_at === null) return t(running ? 'daySummary.roundWaiting' : 'daySummary.roundMissing')
  return t(c.received_late ? 'daySummary.roundLate' : 'daySummary.roundAt', { time: clockTime(c.saved_at) })
}
