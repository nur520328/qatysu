import { useCallback, useMemo, useState } from 'react'
import type { FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { adminApi } from '../../api/admin'
import type { Day, Year } from '../../api/admin'
import { dayMonth, monthYear, weekdayIndex, weekdayNames } from '../../lib/dates'
import { useErrorText } from '../../lib/errors'
import { useLoad } from '../../lib/useLoad'

type Loaded = { year: Year; days: Day[]; schoolDays: number } | { year: null }

export function CalendarPage() {
  const { t, i18n } = useTranslation()
  const errorText = useErrorText()
  const [actionError, setError] = useState<string | null>(null)
  const [from, setFrom] = useState<string | null>(null)
  const [to, setTo] = useState<string | null>(null)
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)

  const fetcher = useCallback(async (): Promise<Loaded> => {
    const years = await adminApi.years()
    const year = years.find((y) => y.is_current) ?? years[0]
    if (year === undefined) return { year: null }
    const calendar = await adminApi.calendar(year.id)
    return { year: calendar.year, days: calendar.days, schoolDays: calendar.school_day_count }
  }, [])
  const { data, error: loadError, reload } = useLoad(fetcher)
  const error = actionError ?? (loadError === null ? null : errorText(loadError))

  const months = useMemo(() => {
    if (data === undefined || data.year === null) return []
    const groups = new Map<string, Day[]>()
    for (const day of data.days) {
      const key = day.date.slice(0, 7)
      groups.set(key, [...(groups.get(key) ?? []), day])
    }
    return [...groups.entries()]
  }, [data])

  function pick(date: string) {
    if (from === null || to !== null) {
      setFrom(date)
      setTo(null)
    } else if (date < from) {
      setTo(from)
      setFrom(date)
    } else {
      setTo(date)
    }
  }

  async function mark(isSchoolDay: boolean) {
    if (data === undefined || data.year === null || from === null || busy) return
    setBusy(true)
    setError(null)
    try {
      await adminApi.setCalendarRange(data.year.id, {
        from,
        to: to ?? from,
        is_school_day: isSchoolDay,
        note: isSchoolDay || note.trim() === '' ? null : note.trim(),
      })
      setFrom(null)
      setTo(null)
      setNote('')
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
  if (data.year === null) return <CreateYear onCreated={reload} />

  const end = to ?? from
  const names = weekdayNames(i18n.language)

  return (
    <section className="page">
      <header className="page__head">
        <div>
          <h1 className="page__title">{t('calendar.title')}</h1>
          <p className="muted">
            {data.year.name} · {t('calendar.schoolDays', { count: data.schoolDays })}
          </p>
        </div>
        <div className="legend">
          <span className="legend__item"><i className="swatch" />{t('calendar.legendSchool')}</span>
          <span className="legend__item"><i className="swatch swatch--off" />{t('calendar.legendOff')}</span>
        </div>
      </header>

      <div className="panel panel--sticky">
        {from === null ? (
          <p className="muted">{t('calendar.hint')}</p>
        ) : (
          <>
            <p className="panel__lead" role="status">
              {to === null
                ? t('calendar.selectedOne', { from: dayMonth(from, i18n.language) })
                : t('calendar.selected', { from: dayMonth(from, i18n.language), to: dayMonth(to, i18n.language) })}
            </p>
            <div className="row">
              <label className="field field--grow">
                <span className="field__label">{t('calendar.note')}</span>
                <input className="field__input" value={note} maxLength={100} onChange={(e) => setNote(e.target.value)} />
              </label>
              <button className="btn btn--strong" type="button" disabled={busy} onClick={() => void mark(false)}>
                {t('calendar.markOff')}
              </button>
              <button className="btn" type="button" disabled={busy} onClick={() => void mark(true)}>
                {t('calendar.markOn')}
              </button>
              <button className="btn" type="button" disabled={busy} onClick={() => { setFrom(null); setTo(null) }}>
                {t('calendar.clear')}
              </button>
            </div>
          </>
        )}
        {error !== null && <p className="alert" role="alert">{error}</p>}
      </div>

      <div className="months">
        {months.map(([key, days]) => (
          <section key={key} className="month" aria-label={monthYear(key, i18n.language)}>
            <h2 className="month__title">{monthYear(key, i18n.language)}</h2>
            <div className="month__grid">
              {names.map((name) => (
                <span key={name} className="month__wd" aria-hidden="true">{name}</span>
              ))}
              {Array.from({ length: weekdayIndex(days[0].date) }, (_, i) => <span key={`pad${i}`} />)}
              {days.map((day) => {
                const selected = from !== null && end !== null && day.date >= from && day.date <= end
                const label = t(day.is_school_day ? 'calendar.dayOn' : 'calendar.dayOff', { date: dayMonth(day.date, i18n.language) })
                return (
                  <button
                    key={day.date}
                    type="button"
                    className={`day${day.is_school_day ? '' : ' day--off'}${selected ? ' day--sel' : ''}`}
                    aria-label={day.note ? `${label} (${day.note})` : label}
                    aria-pressed={selected}
                    title={day.note ?? undefined}
                    onClick={() => pick(day.date)}
                  >
                    {Number(day.date.slice(8))}
                  </button>
                )
              })}
            </div>
          </section>
        ))}
      </div>
    </section>
  )
}

function CreateYear({ onCreated }: { onCreated: () => Promise<void> }) {
  const { t } = useTranslation()
  const errorText = useErrorText()
  // Қазақстанда оқу жылы 1 қыркүйекте басталып, 25 мамырда аяқталады; әкімші түзете алады.
  const [startYear] = useState(() => {
    const now = new Date()
    return now.getMonth() >= 5 ? now.getFullYear() : now.getFullYear() - 1
  })
  const [name, setName] = useState(`${startYear}–${startYear + 1}`)
  const [start, setStart] = useState(`${startYear}-09-01`)
  const [end, setEnd] = useState(`${startYear + 1}-05-25`)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await adminApi.createYear({ name: name.trim(), start_date: start, end_date: end })
      await onCreated()
    } catch (caught) {
      setError(errorText(caught))
      setBusy(false)
    }
  }

  return (
    <section className="page">
      <h1 className="page__title">{t('calendar.title')}</h1>
      <p className="muted">{t('calendar.noYear')}</p>
      <form className="panel form" onSubmit={onSubmit}>
        <h2 className="panel__title">{t('calendar.createTitle')}</h2>
        <div className="row">
          <label className="field field--grow">
            <span className="field__label">{t('calendar.name')}</span>
            <input className="field__input" value={name} maxLength={30} required onChange={(e) => setName(e.target.value)} />
          </label>
          <label className="field">
            <span className="field__label">{t('calendar.start')}</span>
            <input className="field__input" type="date" value={start} required onChange={(e) => setStart(e.target.value)} />
          </label>
          <label className="field">
            <span className="field__label">{t('calendar.end')}</span>
            <input className="field__input" type="date" value={end} required onChange={(e) => setEnd(e.target.value)} />
          </label>
        </div>
        {error !== null && <p className="alert" role="alert">{error}</p>}
        <button className="btn btn--strong" type="submit" disabled={busy || name.trim() === ''}>
          {t('calendar.create')}
        </button>
      </form>
    </section>
  )
}
