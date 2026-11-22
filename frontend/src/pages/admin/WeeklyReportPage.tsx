import type { TFunction } from 'i18next'
import { useCallback } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { adminApi } from '../../api/admin'
import type { ReportEvent } from '../../api/admin'
import { dayMonth, dayMonthTime, dayMonthWeekday } from '../../lib/dates'
import { useErrorText } from '../../lib/errors'
import { useLoad } from '../../lib/useLoad'

/**
 * Апталық есеп: қазіргі күй не бекітілген сурет. Апта мен нұсқа мекенжайда сақталады (`?week=&version=`).
 * PDF серверде жасалмайды: бет басып шығаруға бейімделген, браузер оны PDF етіп сақтайды (docs/кезең-8-жоспар.md, 10-шешім).
 */
export function WeeklyReportPage() {
  const { t, i18n } = useTranslation()
  const errorText = useErrorText()
  const [params, setParams] = useSearchParams()
  const week = /^\d{4}-\d{2}-\d{2}$/.test(params.get('week') ?? '') ? params.get('week') : null
  const wantSnapshot = params.get('version') === 'snapshot'
  const fetcher = useCallback(() => adminApi.weeklyReport(week, wantSnapshot), [week, wantSnapshot])
  const { data: loaded, error } = useLoad(fetcher)
  // Апта не нұсқа ауысқанда жаңа жауап келгенше ескі дерек жаңа тақырыппен көрінбеуі тиіс.
  const matches = loaded !== undefined
    && (week === null ? loaded.week.is_current : loaded.week.from <= week && week <= loaded.week.to)
    && (loaded.version === 'snapshot') === wantSnapshot
  const data = matches ? loaded : undefined

  if (data === undefined) {
    return error === null ? <p className="muted">{t('common.loading')}</p> : <p className="alert" role="alert">{errorText(error)}</p>
  }

  const { report, snapshot } = data
  const lang = i18n.language
  const go = (to: string | null, version: 'live' | 'snapshot' = 'live') => {
    const next: Record<string, string> = {}
    if (to !== null) next.week = to
    if (version === 'snapshot') next.version = 'snapshot'
    setParams(next)
  }
  const totals = report.totals
  const pct = (value: number | null) => (value === null ? t('weekly.noData') : `${value.toLocaleString(lang === 'zh' ? 'zh' : 'ru')}%`)
  const of = (done: number, total: number) => t('weekly.of', { done, total })
  const missing = report.classes.filter((c) => c.missing_dates.length > 0)
  const stat = (value: number | string, text: string, warn = false) => (
    <div className={warn ? 'stat stat--warn' : 'stat'}><b>{value}</b><span>{text}</span></div>
  )

  return (
    <section className="page report">
      <header className="page__head">
        <div>
          <h1 className="page__title">{t('weekly.title')}</h1>
          <p className="muted">{t('weekly.period', { from: dayMonth(report.from, lang), to: dayMonth(report.to, lang) })}</p>
        </div>
        <div className="row no-print">
          <button className="btn" type="button" onClick={() => go(data.week.previous)}>{t('weekly.previous')}</button>
          <button className="btn" type="button" disabled={data.week.next === null} onClick={() => go(data.week.next)}>{t('weekly.next')}</button>
          {!data.week.is_current && <button className="btn" type="button" onClick={() => go(null)}>{t('weekly.current')}</button>}
        </div>
      </header>

      {!report.complete && <p className="alert">{t(report.school_days.length === 0 ? 'weekly.noSchoolDays' : 'weekly.incomplete')}</p>}
      {snapshot !== null && (
        <p className="alert" role="status">
          {t('weekly.frozen', { when: dayMonthTime(snapshot.generated_at, lang) })}{' '}
          {snapshot.changed_since > 0 && <>{t('weekly.changed', { count: snapshot.changed_since })}{' '}</>}
          {t(data.version === 'snapshot' ? 'weekly.showingSnapshot' : 'weekly.showingLive')}{' '}
          <button className="linkbtn no-print" type="button" onClick={() => go(data.week.from, data.version === 'snapshot' ? 'live' : 'snapshot')}>
            {t(data.version === 'snapshot' ? 'weekly.viewLive' : 'weekly.viewSnapshot')}
          </button>
        </p>
      )}

      <div className="row no-print">
        <a className="btn" href={adminApi.weeklyReportExportUrl(data.week.from, data.version === 'snapshot', true)} download>{t('weekly.excelFull')}</a>
        <a className="btn" href={adminApi.weeklyReportExportUrl(data.week.from, data.version === 'snapshot', false)} download>{t('weekly.excelAnonymous')}</a>
        <button className="btn" type="button" onClick={() => window.print()}>{t('weekly.print')}</button>
      </div>
      <p className="muted no-print">{t('weekly.printHint')}</p>

      <div className="stats" role="status" aria-label={t('weekly.title')}>
        {stat(pct(totals.attendance_pct), t('weekly.pct'))}
        {stat(of(totals.class_days_surveyed, totals.class_days_expected), t('weekly.classDays'), totals.class_days_surveyed < totals.class_days_expected)}
        {stat(totals.absent, t('weekly.absent'))}
        {stat(totals.unexcused, t('weekly.unexcused'), totals.unexcused > 0)}
        {stat(totals.pending, t('weekly.pending'), totals.pending > 0)}
        {stat(totals.late, t('weekly.late'))}
        {stat(totals.left, t('weekly.left'))}
      </div>
      {totals.absent > 0 && (
        <p className="muted">{t('daySummary.absentBreakdown', { sick: totals.sick, excused: totals.excused, unexcused: totals.unexcused, pending: totals.pending })}</p>
      )}
      {totals.left > 0 && (
        <p className="muted">{t('daySummary.leftBreakdown', { permitted: totals.left_permitted, unpermitted: totals.left_unpermitted, pending: totals.left_pending })}</p>
      )}
      <p className="muted">{t('weekly.pctNote')}</p>

      {report.days.length > 0 && (
        <section className="group" aria-label={t('weekly.byDay')}>
          <h2 className="group__title">{t('weekly.byDay')}</h2>
          <div className="tablewrap">
            <table className="table">
              <thead>
                <tr>
                  <th>{t('weekly.colDate')}</th>
                  <th className="num">{t('weekly.colSurveyed')}</th>
                  <th className="num">{t('weekly.colPct')}</th>
                  <th className="num">{t('weekly.colAbsent')}</th>
                  <th className="num">{t('weekly.colLate')}</th>
                  <th className="num">{t('weekly.colLeft')}</th>
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

      <section className="group" aria-label={t('weekly.missingTitle')}>
        <h2 className="group__title">{t('weekly.missingTitle')}</h2>
        {missing.length === 0 ? (
          <p className="muted">{t('weekly.missingNone')}</p>
        ) : (
          <ul className="summary__list">
            {missing.map((c) => (
              <li key={c.id}>
                <b>{c.name}</b>: {c.days_surveyed === 0 && c.days_expected > 1 ? t('weekly.allDays') : c.missing_dates.map((d) => dayMonth(d, lang)).join(', ')}
                {c.homeroom !== null && <span className="muted"> · {t('weekly.homeroom', { name: c.homeroom })}</span>}
              </li>
            ))}
          </ul>
        )}
      </section>

      <section className="group" aria-label={t('weekly.byClass')}>
        <h2 className="group__title">{t('weekly.byClass')}</h2>
        <div className="tablewrap">
          <table className="table">
            <thead>
              <tr>
                <th>{t('weekly.colClass')}</th>
                <th className="num">{t('weekly.colStudents')}</th>
                <th className="num">{t('weekly.colDays')}</th>
                <th className="num">{t('weekly.colPct')}</th>
                <th className="num">{t('weekly.colSick')}</th>
                <th className="num">{t('weekly.colExcused')}</th>
                <th className="num">{t('weekly.colUnexcused')}</th>
                <th className="num">{t('weekly.colPending')}</th>
                <th className="num">{t('weekly.colLate')}</th>
                <th className="num">{t('weekly.colLeft')}</th>
              </tr>
            </thead>
            <tbody>
              {report.classes.map((c) => (
                <tr key={c.id}>
                  <th scope="row">{c.name}</th>
                  <td className="num">{c.students}</td>
                  <td className={c.days_surveyed < c.days_expected ? 'num summary__pending' : 'num'}>{of(c.days_surveyed, c.days_expected)}</td>
                  <td className="num">{pct(c.attendance_pct)}</td>
                  <td className="num">{c.sick}</td>
                  <td className="num">{c.excused}</td>
                  <td className={c.unexcused > 0 ? 'num summary__pending' : 'num'}>{c.unexcused}</td>
                  <td className={c.pending > 0 ? 'num summary__pending' : 'num'}>{c.pending}</td>
                  <td className="num">{c.late}</td>
                  <td className="num">{c.left}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      <section className="group" aria-label={t('weekly.studentsTitle')}>
        <h2 className="group__title">{t('weekly.studentsTitle')}</h2>
        <p className="muted">{t('weekly.studentsHint')}</p>
        {report.students.length === 0 ? (
          <p className="muted">{t('weekly.studentsNone')}</p>
        ) : (
          <div className="tablewrap">
            <table className="table">
              <thead>
                <tr>
                  <th>{t('weekly.colStudent')}</th>
                  <th>{t('weekly.colClass')}</th>
                  <th className="num">{t('weekly.colUnexcusedDays')}</th>
                  <th className="num">{t('weekly.colPendingDays')}</th>
                  <th>{t('weekly.colDates')}</th>
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
          <p className="muted">{t('weekly.studentsMore', { shown: report.students.length, total: report.students_total })}</p>
        )}
      </section>

      <section className="group" aria-label={t('weekly.eventsTitle')}>
        <h2 className="group__title">{t('weekly.eventsTitle')}</h2>
        <p className="muted">{t('weekly.eventsHint')}</p>
        {report.events.length === 0 ? (
          <p className="muted">{t('weekly.eventsNone')}</p>
        ) : (
          <div className="tablewrap">
            <table className="table">
              <thead>
                <tr>
                  <th>{t('weekly.colDate')}</th>
                  <th>{t('weekly.colClass')}</th>
                  <th>{t('weekly.colStudent')}</th>
                  <th>{t('weekly.colWhat')}</th>
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
