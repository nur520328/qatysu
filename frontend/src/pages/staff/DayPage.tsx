import { useCallback, useState } from 'react'
import type { FormEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { homeroomApi } from '../../api/staff'
import type { Day, DayPatch, DayRow, HistoryChange, Permission, Reason } from '../../api/staff'
import { displayNames, fullStatus } from '../../lib/attendance'
import { clockTime, dayMonth, dayMonthTime, parseDate } from '../../lib/dates'
import { useErrorText } from '../../lib/errors'
import { useLoad } from '../../lib/useLoad'

function shift(date: string, days: number): string {
  const moved = parseDate(date)
  moved.setUTCDate(moved.getUTCDate() + days)
  return moved.toISOString().slice(0, 10)
}

/**
 * Сыныптың бір күнгі қатысуы: жетекші (өз сыныбы) мен завуч (кез келген сынып) себеп, рұқсат,
 * түсініктеме қояды, қате белгіні түзейді, тарихын көреді. `base` — осы беттің мекенжай басы.
 */
export function DayPage({ base, backTo, backLabel }: { base: string; backTo: string; backLabel: string }) {
  const { t, i18n } = useTranslation()
  const errorText = useErrorText()
  const navigate = useNavigate()
  const params = useParams()
  const classId = Number(params.classId)
  const date = params.date ?? ''
  const fetcher = useCallback(() => homeroomApi.day(classId, date), [classId, date])
  const { data, error: loadError, reload } = useLoad(fetcher)
  const [openId, setOpenId] = useState<number | null>(null)

  if (data === undefined || data.class.id !== classId || data.date !== date) {
    return loadError === null ? <p className="muted">{t('common.loading')}</p> : <p className="alert" role="alert">{errorText(loadError)}</p>
  }

  const pending = data.students.filter((s) => s.pending !== null).length
  const names = displayNames(data.students)
  const go = (target: string) => { setOpenId(null); navigate(`${base}/${classId}/${target}`) }

  return (
    <section className="page">
      <header>
        <Link className="back" to={backTo}>← {backLabel}</Link>
        <h1 className="page__title">{t('day.title', { name: data.class.name, date: dayMonth(data.date, i18n.language) })}</h1>
        <div className="row row--end">
          <button className="btn" type="button" onClick={() => go(shift(date, -1))}>← {t('day.prev')}</button>
          <label className="field">
            <span className="field__label">{t('day.pickDate')}</span>
            <input className="field__input" type="date" value={date} onChange={(e) => { if (e.target.value !== '') go(e.target.value) }} />
          </label>
          <button className="btn" type="button" onClick={() => go(shift(date, 1))}>{t('day.next')} →</button>
        </div>
      </header>

      {!data.is_school_day ? (
        <p className="note" role="status">{t('day.notSchoolDay')}</p>
      ) : (
        <>
          <p className="muted">
            {data.duty === null ? t('day.dutyMissing') : t('day.dutyDone', { time: clockTime(data.duty.saved_at) })}
            {data.duty?.received_late === true && ` ${t('day.dutyLate')}`}
          </p>
          {!data.editable && <p className="note" role="status">{t('day.readOnly')}</p>}
          <p className={pending > 0 ? 'syncbar syncbar--warn' : 'syncbar syncbar--ok'} role="status">
            {pending > 0 ? t('day.pendingCount', { count: pending }) : t('day.nothingPending')}
          </p>

          <ol className="roster">
            {data.students.map((student, index) => (
              <li key={student.id} className="roster__item">
                <div className="roster__row">
                  <span className="roster__no">{index + 1}</span>
                  <span className="roster__name">
                    {names.get(student.id)}
                    <span className={student.pending !== null ? 'roster__sub warn' : 'roster__sub'}>
                      {fullStatus(student, t)}{student.note !== null && ` · ${student.note}`}
                    </span>
                  </span>
                  <button className="btn" type="button" aria-expanded={openId === student.id}
                    aria-label={t('day.edit', { name: names.get(student.id) })}
                    onClick={() => setOpenId(openId === student.id ? null : student.id)}>
                    {t('common.edit')}
                  </button>
                </div>
                {openId === student.id && (
                  <Editor day={data} student={student} onSaved={async () => { await reload(); setOpenId(null) }} />
                )}
              </li>
            ))}
          </ol>
        </>
      )}
    </section>
  )
}

function Editor({ day, student, onSaved }: { day: Day; student: DayRow; onSaved: () => Promise<void> }) {
  const { t } = useTranslation()
  const errorText = useErrorText()
  const [status, setStatus] = useState(student.round_status ?? 'present')
  const [arrived, setArrived] = useState(student.arrived_lesson)
  const [left, setLeft] = useState(student.left_lesson)
  const [reason, setReason] = useState<Reason | null>(student.absence_reason)
  const [permission, setPermission] = useState<Permission | null>(student.leave_permission)
  const [note, setNote] = useState(student.note ?? '')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [history, setHistory] = useState<{ created: null | { at: string; by_duty: boolean }; changes: HistoryChange[] } | null>(null)

  const lessons = Array.from({ length: day.max_lessons }, (_, i) => i + 1)
  // Себеп тек күні бойы болмаған оқушыға, рұқсат тек кеткен оқушыға қойылады.
  const fullAbsence = status === 'absent' && arrived === null && left === null
  const disabled = busy || !day.editable

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    const patch: DayPatch = {}
    if (status !== student.round_status) patch.round_status = status
    if (arrived !== student.arrived_lesson) patch.arrived_lesson = arrived
    if (left !== student.left_lesson) patch.left_lesson = left
    const nextReason = fullAbsence ? reason : null
    if (nextReason !== student.absence_reason && fullAbsence) patch.absence_reason = nextReason
    const nextPermission = left === null ? null : permission
    if (nextPermission !== student.leave_permission && left !== null) patch.leave_permission = nextPermission
    if (note.trim() !== (student.note ?? '')) patch.note = note.trim() === '' ? null : note.trim()

    setBusy(true)
    setError(null)
    try {
      await homeroomApi.update(day.class.id, day.date, student.id, patch)
      await onSaved()
    } catch (caught) {
      setError(errorText(caught))
      setBusy(false)
    }
  }

  async function toggleHistory() {
    if (history !== null) {
      setHistory(null)
      return
    }
    try {
      setHistory(await homeroomApi.history(day.class.id, day.date, student.id))
    } catch (caught) {
      setError(errorText(caught))
    }
  }

  const lessonSelect = (label: string, value: number | null, set: (n: number | null) => void, from: number) => (
    <label className="field">
      <span className="field__label">{label}</span>
      <select className="field__input" value={value ?? ''} disabled={disabled} onChange={(e) => set(e.target.value === '' ? null : Number(e.target.value))}>
        <option value="">{t('day.none')}</option>
        {lessons.filter((n) => n >= from).map((n) => <option key={n} value={n}>{t('att.lesson', { n })}</option>)}
      </select>
    </label>
  )

  return (
    <form className="roster__actions" onSubmit={onSubmit}>
      <div className="row row--end">
        <label className="field">
          <span className="field__label">{t('day.mark')}</span>
          <select className="field__input" value={status} disabled={disabled} onChange={(e) => setStatus(e.target.value === 'absent' ? 'absent' : 'present')}>
            <option value="present">{t('att.present')}</option>
            <option value="absent">{t('att.absent')}</option>
          </select>
        </label>
        {lessonSelect(t('day.arrivedLesson'), arrived, setArrived, 1)}
        {lessonSelect(t('day.leftLesson'), left, setLeft, Math.max(2, arrived ?? 2))}
      </div>

      {fullAbsence && (
        <fieldset className="choice" disabled={disabled}>
          <legend className="field__label">{t('day.reason')}</legend>
          {([null, 'sick', 'excused', 'unexcused'] as const).map((value) => (
            <label key={value ?? 'none'} className="choice__item">
              <input type="radio" name={`reason-${student.id}`} checked={reason === value} onChange={() => setReason(value)} />
              {value === null ? t('day.reasonNone') : t(`att.reason_${value}`)}
            </label>
          ))}
        </fieldset>
      )}

      {left !== null && (
        <fieldset className="choice" disabled={disabled}>
          <legend className="field__label">{t('day.permission')}</legend>
          {([null, 'permitted', 'unpermitted'] as const).map((value) => (
            <label key={value ?? 'none'} className="choice__item">
              <input type="radio" name={`permission-${student.id}`} checked={permission === value} onChange={() => setPermission(value)} />
              {value === null ? t('day.permissionNone') : t(`att.perm_${value}`)}
            </label>
          ))}
        </fieldset>
      )}

      <label className="field">
        <span className="field__label">{t('day.note')}</span>
        <input className="field__input" value={note} maxLength={500} disabled={disabled} onChange={(e) => setNote(e.target.value)} />
      </label>

      {error !== null && <p className="alert" role="alert">{error}</p>}
      <div className="row">
        {day.editable && <button className="btn btn--strong" type="submit" disabled={busy}>{busy ? t('common.saving') : t('day.save')}</button>}
        <button className="btn" type="button" onClick={() => void toggleHistory()}>{t(history === null ? 'day.history' : 'day.historyHide')}</button>
      </div>

      {history !== null && <History data={history} />}
    </form>
  )
}

function History({ data }: { data: { created: null | { at: string; by_duty: boolean }; changes: HistoryChange[] } }) {
  const { t, i18n } = useTranslation()
  const when = (iso: string) => dayMonthTime(iso, i18n.language)

  function value(change: HistoryChange, raw: string | null): string {
    if (raw === null) return t('day.empty')
    if (change.field === 'round_status') return t(raw === 'present' ? 'att.present' : 'att.absent')
    if (change.field === 'arrived_lesson' || change.field === 'left_lesson') return t('att.lesson', { n: raw })
    if (change.field === 'absence_reason') return t(`att.reason_${raw}`)
    if (change.field === 'leave_permission') return t(`att.perm_${raw}`)
    return raw
  }

  function who(change: HistoryChange): string {
    if (change.role === 'duty') return change.device_number === null ? t('day.who_dutyUnknown') : t('day.who_duty', { n: change.device_number })
    return t(`day.who_${change.role}`, { name: change.by ?? '' })
  }

  return (
    <ul className="history" aria-label={t('day.history')}>
      {data.created !== null && <li>{t(data.created.by_duty ? 'day.createdByDuty' : 'day.createdByStaff', { when: when(data.created.at) })}</li>}
      {data.changes.length === 0 && <li>{t('day.historyEmpty')}</li>}
      {data.changes.map((change, index) => (
        <li key={index}>
          {t('day.change', { field: t(`day.field_${change.field}`), old: value(change, change.old), new: value(change, change.new) })}
          <span className="muted"> · {who(change)}, {when(change.at)}</span>
        </li>
      ))}
    </ul>
  )
}
