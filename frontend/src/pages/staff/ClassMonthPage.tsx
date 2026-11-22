import { useCallback } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { monthApi } from '../../api/staff'
import { dayMonth, monthYear } from '../../lib/dates'
import { useErrorText } from '../../lib/errors'
import { useLoad } from '../../lib/useLoad'

/**
 * Сынып жетекшісінің айлық қорытындысы: өз сыныбының әр оқушысы бойынша. Завуч кез келген сыныптікін ашады.
 * Әрқашан қазіргі күй (бекітілмейді); басып шығаруға бейімделген (docs/кезең-9-жоспар.md, 5 және 6-шешім).
 */
export function ClassMonthPage({ backTo, admin = false }: { backTo: string; admin?: boolean }) {
  const { t, i18n } = useTranslation()
  const errorText = useErrorText()
  const classId = Number(useParams().classId)
  const [params, setParams] = useSearchParams()
  const date = /^\d{4}-\d{2}-\d{2}$/.test(params.get('date') ?? '') ? params.get('date') : null
  const fetcher = useCallback(() => monthApi.classMonth(classId, date), [classId, date])
  const { data: loaded, error } = useLoad(fetcher)
  // Ай ауысқанда жаңа жауап келгенше ескі айдың дерегі жаңа тақырыппен көрінбеуі тиіс.
  const data = loaded !== undefined && loaded.class.id === classId
    && (date === null ? loaded.month.is_current : loaded.month.from <= date && date <= loaded.month.to) ? loaded : undefined

  if (data === undefined) {
    return error === null ? <p className="muted">{t('common.loading')}</p> : <p className="alert" role="alert">{errorText(error)}</p>
  }

  const lang = i18n.language
  const go = (to: string | null) => setParams(to === null ? {} : { date: to })
  const pct = (value: number | null) => (value === null ? t('report.noData') : `${value.toLocaleString(lang === 'zh' ? 'zh' : 'ru')}%`)
  const back = admin ? `/admin/classes/${classId}` : backTo
  const warn = (value: number) => (value > 0 ? 'num summary__pending' : 'num')

  return (
    <section className="page report">
      <header className="page__head">
        <div>
          <Link className="back no-print" to={back}>← {t(admin ? 'classPage.title' : 'common.back', { name: data.class.name })}</Link>
          <h1 className="page__title">{t('classMonth.title')} · {data.class.name}</h1>
          <p className="muted">{monthYear(data.month.from, lang)}</p>
        </div>
        <div className="row no-print">
          <button className="btn" type="button" onClick={() => go(data.month.previous)}>{t('classMonth.previous')}</button>
          <button className="btn" type="button" disabled={data.month.next === null} onClick={() => go(data.month.next)}>{t('classMonth.next')}</button>
          {!data.month.is_current && <button className="btn" type="button" onClick={() => go(null)}>{t('classMonth.current')}</button>}
          <button className="btn" type="button" onClick={() => window.print()}>{t('classMonth.print')}</button>
        </div>
      </header>

      {!data.complete && <p className="alert">{t('classMonth.incomplete')}</p>}

      <div className="stats" role="status" aria-label={t('classMonth.title')}>
        <div className="stat"><b>{pct(data.totals.attendance_pct)}</b><span>{t('classMonth.pct')}</span></div>
        <div className={data.days_surveyed < data.days_expected ? 'stat stat--warn' : 'stat'}>
          <b>{t('report.of', { done: data.days_surveyed, total: data.days_expected })}</b><span>{t('classMonth.surveyed')}</span>
        </div>
        <div className={data.totals.unexcused > 0 ? 'stat stat--warn' : 'stat'}><b>{data.totals.unexcused}</b><span>{t('report.unexcused')}</span></div>
        <div className={data.totals.pending > 0 ? 'stat stat--warn' : 'stat'}><b>{data.totals.pending}</b><span>{t('report.pending')}</span></div>
        <div className="stat"><b>{data.totals.late}</b><span>{t('report.late')}</span></div>
        <div className="stat"><b>{data.totals.left}</b><span>{t('report.left')}</span></div>
      </div>
      {data.missing_dates.length > 0 && (
        <p className="muted">{t('classMonth.missing', { dates: data.missing_dates.map((d) => dayMonth(d, lang)).join(', ') })}</p>
      )}

      {data.students.length === 0 ? (
        <p className="muted">{t('classMonth.empty')}</p>
      ) : (
        <div className="tablewrap">
          <table className="table">
            <thead>
              <tr>
                <th>{t('classMonth.colStudent')}</th>
                <th className="num">{t('classMonth.colMarked')}</th>
                <th className="num">{t('report.colPct')}</th>
                <th className="num">{t('classMonth.colAbsent')}</th>
                <th className="num">{t('report.colSick')}</th>
                <th className="num">{t('report.colExcused')}</th>
                <th className="num">{t('report.colUnexcused')}</th>
                <th className="num">{t('report.colPending')}</th>
                <th className="num">{t('report.colLate')}</th>
                <th className="num">{t('report.colLeft')}</th>
                <th>{t('classMonth.colDates')}</th>
              </tr>
            </thead>
            <tbody>
              {data.students.map((s) => (
                <tr key={s.id}>
                  <th scope="row">
                    {s.name}
                    {!s.in_class_now && <>{' · '}<span className="muted">{t('classMonth.left')}</span></>}
                  </th>
                  <td className="num">{s.marked}</td>
                  <td className="num">{pct(s.attendance_pct)}</td>
                  <td className="num">{s.absent}</td>
                  <td className="num">{s.sick}</td>
                  <td className="num">{s.excused}</td>
                  <td className={warn(s.unexcused)}>{s.unexcused}</td>
                  <td className={warn(s.pending)}>{s.pending}</td>
                  <td className="num">{s.late}</td>
                  <td className="num">{s.left}</td>
                  <td>
                    {s.dates.map((d, index) => (
                      <span key={d}>
                        {index > 0 && ', '}
                        <Link to={admin ? `/admin/attendance/${classId}/${d}` : `/homeroom/${classId}/${d}`}>{dayMonth(d, lang)}</Link>
                      </span>
                    ))}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <p className="muted">{t('classMonth.note')}</p>
    </section>
  )
}
