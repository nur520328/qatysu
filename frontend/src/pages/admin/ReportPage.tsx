import type { TFunction } from 'i18next'
import { useCallback } from 'react'
import { Link, NavLink, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { adminApi } from '../../api/admin'
import type { ReportCounters, ReportEvent, ReportType } from '../../api/admin'
import { dayMonth, dayMonthTime, dayMonthWeekday, monthYear } from '../../lib/dates'
import { useErrorText } from '../../lib/errors'
import { useLoad } from '../../lib/useLoad'

/**
 * Апталық не айлық есеп: қазіргі күй не бекітілген сурет. Кезең мен нұсқа мекенжайда сақталады (`?date=&version=`).
 * PDF серверде жасалмайды: бет басып шығаруға бейімделген, браузер оны PDF етіп сақтайды (docs/кезең-8-жоспар.md, 10-шешім).
 */
export function ReportPage({ type }: { type: ReportType }) {
  const { t, i18n } = useTranslation()
  const errorText = useErrorText()
  const [params, setParams] = useSearchParams()
  const date = /^\d{4}-\d{2}-\d{2}$/.test(params.get('date') ?? '') ? params.get('date') : null
  const wantSnapshot = params.get('version') === 'snapshot'
  const fetcher = useCallback(() => adminApi.report(type, date, wantSnapshot), [type, date, wantSnapshot])
  const { data: loaded, error } = useLoad(fetcher)
  // Кезең, түр не нұсқа ауысқанда жаңа жауап келгенше ескі дерек жаңа тақырыппен көрінбеуі тиіс.
  const matches = loaded !== undefined
    && loaded.report.type === type
    && (date === null ? loaded.period.is_current : loaded.period.from <= date && date <= loaded.period.to)
    && (loaded.version === 'snapshot') === wantSnapshot
  const data = matches ? loaded : undefined

  const tabs = (
    <nav className="row no-print" aria-label={t('report.tabs')}>
      <NavLink end to="/admin/reports" className={({ isActive }) => (isActive ? 'tab tab--on' : 'tab')}>{t('report.tabWeekly')}</NavLink>
      <NavLink to="/admin/reports/monthly" className={({ isActive }) => (isActive ? 'tab tab--on' : 'tab')}>{t('report.tabMonthly')}</NavLink>
    </nav>
  )

  if (data === undefined) {
    return (
      <section className="page">
        {tabs}
        {error === null ? <p className="muted">{t('common.loading')}</p> : <p className="alert" role="alert">{errorText(error)}</p>}
      </section>
    )
  }

  const { report, snapshot } = data
  const lang = i18n.language
  const go = (to: string | null, version: 'live' | 'snapshot' = 'live') => {
    const next: Record<string, string> = {}
    if (to !== null) next.date = to
    if (version === 'snapshot') next.version = 'snapshot'
    setParams(next)
  }
  const totals = report.totals
  const pct = (value: number | null) => (value === null ? t('report.noData') : `${value.toLocaleString(lang === 'zh' ? 'zh' : 'ru')}%`)
  const of = (done: number, total: number) => t('report.of', { done, total })
  const missing = report.classes.filter((c) => c.missing_dates.length > 0)
  const counterHeads = (
    <>
      <th className="num">{t('report.colPct')}</th>
      <th className="num">{t('report.colSick')}</th>
      <th className="num">{t('report.colExcused')}</th>
      <th className="num">{t('report.colUnexcused')}</th>
      <th className="num">{t('report.colPending')}</th>
      <th className="num">{t('report.colLate')}</th>
      <th className="num">{t('report.colLeft')}</th>
    </>
  )
  const counterCells = (row: ReportCounters) => (
    <>
      <td className="num">{pct(row.attendance_pct)}</td>
      <td className="num">{row.sick}</td>
      <td className="num">{row.excused}</td>
      <td className={row.unexcused > 0 ? 'num summary__pending' : 'num'}>{row.unexcused}</td>
      <td className={row.pending > 0 ? 'num summary__pending' : 'num'}>{row.pending}</td>
      <td className="num">{row.late}</td>
      <td className="num">{row.left}</td>
    </>
  )
  const stat = (value: number | string, text: string, warn = false) => (
    <div className={warn ? 'stat stat--warn' : 'stat'}><b>{value}</b><span>{text}</span></div>
  )

  return (
    <section className="page report">
      {tabs}
      <header className="page__head">
        <div>
          <h1 className="page__title">{t(`report.title_${type}`)}</h1>
          <p className="muted">
            {type === 'monthly' ? monthYear(report.from, lang) : t('report.period', { from: dayMonth(report.from, lang), to: dayMonth(report.to, lang) })}
          </p>
        </div>
        <div className="row no-print">
          <button className="btn" type="button" onClick={() => go(data.period.previous)}>{t(`report.previous_${type}`)}</button>
          <button className="btn" type="button" disabled={data.period.next === null} onClick={() => go(data.period.next)}>{t(`report.next_${type}`)}</button>
          {!data.period.is_current && <button className="btn" type="button" onClick={() => go(null)}>{t(`report.current_${type}`)}</button>}
        </div>
      </header>

      {!report.complete && <p className="alert">{t(`report.${report.school_days.length === 0 ? 'noSchoolDays' : 'incomplete'}_${type}`)}</p>}
      {snapshot !== null && (
        <p className="alert" role="status">
          {t('report.frozen', { when: dayMonthTime(snapshot.generated_at, lang) })}{' '}
          {snapshot.changed_since > 0 && <>{t('report.changed', { count: snapshot.changed_since })}{' '}</>}
          {t(data.version === 'snapshot' ? 'report.showingSnapshot' : 'report.showingLive')}{' '}
          <button className="linkbtn no-print" type="button" onClick={() => go(data.period.from, data.version === 'snapshot' ? 'live' : 'snapshot')}>
            {t(data.version === 'snapshot' ? 'report.viewLive' : 'report.viewSnapshot')}
          </button>
        </p>
      )}

      <div className="row no-print">
        <a className="btn" href={adminApi.reportExportUrl(type, data.period.from, data.version === 'snapshot', true)} download>{t('report.excelFull')}</a>
        <a className="btn" href={adminApi.reportExportUrl(type, data.period.from, data.version === 'snapshot', false)} download>{t('report.excelAnonymous')}</a>
        <button className="btn" type="button" onClick={() => window.print()}>{t('report.print')}</button>
      </div>
      <p className="muted no-print">{t('report.printHint')}</p>

      <div className="stats" role="status" aria-label={t(`report.title_${type}`)}>
        {stat(pct(totals.attendance_pct), t('report.pct'))}
        {stat(of(totals.class_days_surveyed, totals.class_days_expected), t('report.classDays'), totals.class_days_surveyed < totals.class_days_expected)}
        {stat(totals.absent, t('report.absent'))}
        {stat(totals.unexcused, t('report.unexcused'), totals.unexcused > 0)}
        {stat(totals.pending, t('report.pending'), totals.pending > 0)}
        {stat(totals.late, t('report.late'))}
        {stat(totals.left, t('report.left'))}
      </div>
      {totals.absent > 0 && (
        <p className="muted">{t('daySummary.absentBreakdown', { sick: totals.sick, excused: totals.excused, unexcused: totals.unexcused, pending: totals.pending })}</p>
      )}
      {totals.left > 0 && (
        <p className="muted">{t('daySummary.leftBreakdown', { permitted: totals.left_permitted, unpermitted: totals.left_unpermitted, pending: totals.left_pending })}</p>
      )}
      <p className="muted">{t('report.pctNote')}</p>

      {report.weeks !== undefined && report.weeks.length > 0 && (
        <section className="group" aria-label={t('report.byWeek')}>
          <h2 className="group__title">{t('report.byWeek')}</h2>
          <div className="tablewrap">
            <table className="table">
              <thead>
                <tr>
                  <th>{t('report.colWeek')}</th>
                  <th className="num">{t('report.colSchoolDays')}</th>
                  <th className="num">{t('report.colSurveyed')}</th>
                  {counterHeads}
                </tr>
              </thead>
              <tbody>
                {report.weeks.map((week) => (
                  <tr key={week.from}>
                    <th scope="row">
                      <Link to={`/admin/reports?date=${week.from}`}>{dayMonth(week.from, lang)} – {dayMonth(week.to, lang)}</Link>
                    </th>
                    <td className="num">{week.school_days}</td>
                    <td className={week.class_days_surveyed < week.class_days_expected ? 'num summary__pending' : 'num'}>{of(week.class_days_surveyed, week.class_days_expected)}</td>
                    {counterCells(week)}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}

      {report.grades !== undefined && report.grades.length > 0 && (
        <section className="group" aria-label={t('report.byGrade')}>
          <h2 className="group__title">{t('report.byGrade')}</h2>
          <div className="tablewrap">
            <table className="table">
              <thead>
                <tr>
                  <th>{t('report.colGrade')}</th>
                  <th className="num">{t('report.colClasses')}</th>
                  <th className="num">{t('report.colStudents')}</th>
                  <th className="num">{t('report.colSurveyed')}</th>
                  {counterHeads}
                </tr>
              </thead>
              <tbody>
                {report.grades.map((grade) => (
                  <tr key={grade.grade}>
                    <th scope="row">{t('report.gradeName', { grade: grade.grade })}</th>
                    <td className="num">{grade.classes}</td>
                    <td className="num">{grade.students}</td>
                    <td className={grade.class_days_surveyed < grade.class_days_expected ? 'num summary__pending' : 'num'}>{of(grade.class_days_surveyed, grade.class_days_expected)}</td>
                    {counterCells(grade)}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}

      {type === 'weekly' && report.days.length > 0 && (
        <section className="group" aria-label={t('report.byDay')}>
          <h2 className="group__title">{t('report.byDay')}</h2>
          <div className="tablewrap">
            <table className="table">
              <thead>
                <tr>
                  <th>{t('report.colDate')}</th>
                  <th className="num">{t('report.colSurveyed')}</th>
                  <th className="num">{t('report.colPct')}</th>
                  <th className="num">{t('report.colAbsent')}</th>
                  <th className="num">{t('report.colLate')}</th>
                  <th className="num">{t('report.colLeft')}</th>
                </tr>
              </thead>
              <tbody>
                {report.days.map((day) => (
                  <tr key={day.date}>
                    <th scope="row">
                      <Link to={`/admin/summary?date=${day.date}`}>{dayMonthWeekday(day.date, lang)}</Link>
                    </th>
                    <td className={day.class_days_surveyed < day.class_days_expected ? 'num summary__pending' : 'num'}>{of(day.class_days_surveyed, day.class_days_expected)}</td>
                    <td className="num">{pct(day.attendance_pct)}</td>
                    <td className="num">{day.absent}</td>
                    <td className="num">{day.late}</td>
                    <td className="num">{day.left}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}

      <section className="group" aria-label={t('report.missingTitle')}>
        <h2 className="group__title">{t('report.missingTitle')}</h2>
        {missing.length === 0 ? (
          <p className="muted">{t('report.missingNone')}</p>
        ) : (
          <ul className="summary__list">
            {missing.map((c) => (
              <li key={c.id}>
                <b>{c.name}</b>: {c.days_surveyed === 0 && c.days_expected > 1 ? t('report.allDays') : c.missing_dates.map((d) => dayMonth(d, lang)).join(', ')}
                {c.homeroom !== null && <span className="muted"> · {t('report.homeroom', { name: c.homeroom })}</span>}
              </li>
            ))}
          </ul>
        )}
      </section>

      <section className="group" aria-label={t('report.byClass')}>
        <h2 className="group__title">{t('report.byClass')}</h2>
        <div className="tablewrap">
          <table className="table">
            <thead>
              <tr>
                <th>{t('report.colClass')}</th>
                <th className="num">{t('report.colStudents')}</th>
                <th className="num">{t('report.colDays')}</th>
                {counterHeads}
              </tr>
            </thead>
            <tbody>
              {report.classes.map((c) => (
                <tr key={c.id}>
                  <th scope="row">{c.name}</th>
                  <td className="num">{c.students}</td>
                  <td className={c.days_surveyed < c.days_expected ? 'num summary__pending' : 'num'}>{of(c.days_surveyed, c.days_expected)}</td>
                  {counterCells(c)}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      <section className="group" aria-label={t('report.studentsTitle')}>
        <h2 className="group__title">{t('report.studentsTitle')}</h2>
        <p className="muted">{t(`report.studentsHint_${type}`)}</p>
        {report.students.length === 0 ? (
          <p className="muted">{t('report.studentsNone')}</p>
        ) : (
          <div className="tablewrap">
            <table className="table">
              <thead>
                <tr>
                  <th>{t('report.colStudent')}</th>
                  <th>{t('report.colClass')}</th>
                  <th className="num">{t('report.colUnexcusedDays')}</th>
                  <th className="num">{t('report.colPendingDays')}</th>
                  <th>{t('report.colDates')}</th>
                </tr>
              </thead>
              <tbody>
                {report.students.map((s) => (
                  <tr key={s.id}>
                    <th scope="row">{s.name}</th>
                    <td>{s.class}</td>
                    <td className="num">{s.unexcused}</td>
                    <td className="num">{s.pending}</td>
                    <td>{s.dates.map((d) => dayMonth(d, lang)).join(', ')}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {report.students_total > report.students.length && (
          <p className="muted">{t('report.studentsMore', { shown: report.students.length, total: report.students_total })}</p>
        )}
      </section>

      <section className="group" aria-label={t('report.eventsTitle')}>
        <h2 className="group__title">{t('report.eventsTitle')}</h2>
        <p className="muted">{t('report.eventsHint')}</p>
        {report.events.length === 0 ? (
          <p className="muted">{t('report.eventsNone')}</p>
        ) : (
          <div className="tablewrap">
            <table className="table">
              <thead>
                <tr>
                  <th>{t('report.colDate')}</th>
                  <th>{t('report.colClass')}</th>
                  <th>{t('report.colStudent')}</th>
                  <th>{t('report.colWhat')}</th>
                </tr>
              </thead>
              <tbody>
                {report.events.map((e) => (
                  <tr key={`${e.date}-${e.student_id}`}>
                    <td>{dayMonth(e.date, lang)}</td>
                    <td>{e.class}</td>
                    <th scope="row">{e.name}</th>
                    <td>{eventText(e, t)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </section>
  )
}

/** «2-сабақтан келді», «5-сабақтан кетіп қалды · рұқсатсыз». */
function eventText(event: ReportEvent, t: TFunction): string {
  const parts: string[] = []
  if (event.arrived_lesson !== null) parts.push(t('att.arrived', { n: event.arrived_lesson }))
  if (event.left_lesson !== null) {
    parts.push(t('att.left', { n: event.left_lesson }))
    parts.push(event.leave_permission === null ? t('att.permMissing') : t(`att.perm_${event.leave_permission}`))
  }
  return parts.join(' · ')
}
