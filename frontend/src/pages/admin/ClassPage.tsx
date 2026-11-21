import { useCallback, useState } from 'react'
import type { FormEvent } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { adminApi } from '../../api/admin'
import type { Account, SchoolClass, Shift, Student, StudentNames } from '../../api/admin'
import { useErrorText } from '../../lib/errors'
import { useLoad } from '../../lib/useLoad'

const EMPTY: StudentNames = { last_name: '', first_name: '', patronymic: '' }

function fullName(s: Pick<Student, 'last_name' | 'first_name' | 'patronymic'>): string {
  return [s.last_name, s.first_name, s.patronymic].filter(Boolean).join(' ')
}

export function ClassPage() {
  const { t } = useTranslation()
  const errorText = useErrorText()
  const classId = Number(useParams().id)
  const [actionError, setError] = useState<string | null>(null)
  const [openId, setOpenId] = useState<number | null>(null)

  const fetcher = useCallback(async () => {
    const [roster, all, users] = await Promise.all([adminApi.students(classId), adminApi.classes(), adminApi.accounts()])
    return {
      cls: all.classes.find((c) => c.id === classId) ?? roster.class,
      students: roster.students,
      others: all.classes.filter((c) => c.id !== classId && c.is_active),
      accounts: users.filter((u) => u.is_active),
    }
  }, [classId])
  const { data, error: loadError, reload } = useLoad(fetcher)
  const error = actionError ?? (loadError === null ? null : errorText(loadError))

  /** Әрекетті орындап, тізімді жаңартады; қате болса көрсетеді. */
  async function run(action: () => Promise<unknown>): Promise<boolean> {
    setError(null)
    try {
      await action()
      await reload()
      return true
    } catch (caught) {
      setError(errorText(caught))
      return false
    }
  }

  // Басқа сыныпқа өткенде алдыңғы сыныптың тізімі бір сәтке де көрінбеуі керек.
  if (data === undefined || data.cls.id !== classId) {
    return error === null ? <p className="muted">{t('common.loading')}</p> : <p className="alert" role="alert">{error}</p>
  }
  const { cls, students, others, accounts } = data

  return (
    <section className="page">
      <header className="page__head">
        <div>
          <Link className="back" to="/admin/classes">← {t('nav.classes')}</Link>
          <h1 className="page__title">{t('classPage.title', { name: cls.name })}</h1>
          <p className="muted">
            {t('classPage.count', { count: students.length })} · {t(cls.shift === 'morning' ? 'classes.shiftMorning' : 'classes.shiftAfternoon')}
            {!cls.is_active && ` · ${t('classes.closed')}`}
          </p>
        </div>
      </header>

      {error !== null && <p className="alert" role="alert">{error}</p>}

      {students.length === 0 ? (
        <p className="muted">{t('classPage.empty')}</p>
      ) : (
        <ol className="roster">
          {students.map((student, index) => (
            <li key={student.id} className="roster__item">
              <div className="roster__row">
                <span className="roster__no">{index + 1}</span>
                <span className="roster__name">{fullName(student)}</span>
                <button className="btn" type="button" aria-expanded={openId === student.id}
                  aria-label={t('classPage.actions', { name: fullName(student) })}
                  onClick={() => setOpenId(openId === student.id ? null : student.id)}>
                  {t('common.edit')}
                </button>
              </div>
              {openId === student.id && (
                <StudentActions student={student} others={others} run={run} onClose={() => setOpenId(null)} />
              )}
            </li>
          ))}
        </ol>
      )}

      {cls.is_active && <AddStudent onAdd={(names) => run(() => adminApi.addStudent(classId, names))} />}
      <ClassSettings cls={cls} accounts={accounts} run={run} />
    </section>
  )
}

function NameFields({ value, onChange, idPrefix }: { value: StudentNames; onChange: (v: StudentNames) => void; idPrefix: string }) {
  const { t } = useTranslation()
  const set = (field: keyof StudentNames) => (e: { target: { value: string } }) => onChange({ ...value, [field]: e.target.value })

  return (
    <div className="row">
      <label className="field field--grow">
        <span className="field__label">{t('classPage.lastName')}</span>
        <input id={`${idPrefix}-last`} className="field__input" value={value.last_name} maxLength={60} required onChange={set('last_name')} />
      </label>
      <label className="field field--grow">
        <span className="field__label">{t('classPage.firstName')}</span>
        <input className="field__input" value={value.first_name} maxLength={60} required onChange={set('first_name')} />
      </label>
      <label className="field field--grow">
        <span className="field__label">{t('classPage.patronymic')}</span>
        <input className="field__input" value={value.patronymic ?? ''} maxLength={60} onChange={set('patronymic')} />
      </label>
    </div>
  )
}

function clean(names: StudentNames): StudentNames {
  const patronymic = (names.patronymic ?? '').trim()
  return { last_name: names.last_name.trim(), first_name: names.first_name.trim(), patronymic: patronymic === '' ? null : patronymic }
}

function AddStudent({ onAdd }: { onAdd: (names: StudentNames) => Promise<boolean> }) {
  const { t } = useTranslation()
  const [names, setNames] = useState<StudentNames>(EMPTY)
  const [busy, setBusy] = useState(false)

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    setBusy(true)
    if (await onAdd(clean(names))) setNames(EMPTY)
    setBusy(false)
  }

  return (
    <form className="panel form" onSubmit={onSubmit}>
      <h2 className="panel__title">{t('classPage.addTitle')}</h2>
      <NameFields value={names} onChange={setNames} idPrefix="add" />
      <button className="btn btn--strong" type="submit" disabled={busy || names.last_name.trim() === '' || names.first_name.trim() === ''}>
        {t('common.add')}
      </button>
    </form>
  )
}

interface ActionsProps {
  student: Student
  others: SchoolClass[]
  run: (action: () => Promise<unknown>) => Promise<boolean>
  onClose: () => void
}

function StudentActions({ student, others, run, onClose }: ActionsProps) {
  const { t } = useTranslation()
  const [names, setNames] = useState<StudentNames>({ last_name: student.last_name, first_name: student.first_name, patronymic: student.patronymic ?? '' })
  const [target, setTarget] = useState('')
  const [busy, setBusy] = useState(false)

  async function act(action: () => Promise<unknown>) {
    setBusy(true)
    if (await run(action)) onClose()
    setBusy(false)
  }

  return (
    <div className="roster__actions">
      <form className="form" onSubmit={(e) => { e.preventDefault(); void act(() => adminApi.updateStudent(student.id, clean(names))) }}>
        <NameFields value={names} onChange={setNames} idPrefix={`edit-${student.id}`} />
        <div className="row">
          <button className="btn btn--strong" type="submit" disabled={busy || names.last_name.trim() === '' || names.first_name.trim() === ''}>
            {t('common.save')}
          </button>
          <button className="btn" type="button" onClick={onClose}>{t('common.cancel')}</button>
        </div>
      </form>
      <div className="row row--end">
        <label className="field">
          <span className="field__label">{t('classPage.transferTo')}</span>
          <select className="field__input" value={target} onChange={(e) => setTarget(e.target.value)}>
            <option value="">—</option>
            {others.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
        </label>
        <button className="btn" type="button" disabled={busy || target === ''}
          onClick={() => void act(() => adminApi.transferStudent(student.id, Number(target)))}>
          {t('classPage.transferDo')}
        </button>
        <button className="btn btn--danger" type="button" disabled={busy}
          onClick={() => {
            if (window.confirm(t('classPage.withdrawConfirm', { name: `${student.last_name} ${student.first_name}` }))) {
              void act(() => adminApi.withdrawStudent(student.id))
            }
          }}>
          {t('classPage.withdraw')}
        </button>
      </div>
    </div>
  )
}

function ClassSettings({ cls, accounts, run }: { cls: SchoolClass; accounts: Account[]; run: (a: () => Promise<unknown>) => Promise<boolean> }) {
  const { t } = useTranslation()

  return (
    <section className="panel form" aria-label={t('classPage.settingsTitle')}>
      <h2 className="panel__title">{t('classPage.settingsTitle')}</h2>
      <div className="row row--end">
        <label className="field field--grow">
          <span className="field__label">{t('classes.homeroom')}</span>
          <select className="field__input" value={cls.homeroom?.id ?? ''}
            onChange={(e) => void run(() => adminApi.updateClass(cls.id, { homeroom_user_id: e.target.value === '' ? null : Number(e.target.value) }))}>
            <option value="">{t('classPage.noTeacher')}</option>
            {accounts.map((a) => <option key={a.id} value={a.id}>{a.full_name}</option>)}
          </select>
        </label>
        <label className="field">
          <span className="field__label">{t('classes.shift')}</span>
          <select className="field__input" value={cls.shift}
            onChange={(e) => void run(() => adminApi.updateClass(cls.id, { shift: e.target.value as Shift }))}>
            <option value="morning">{t('classes.shiftMorning')}</option>
            <option value="afternoon">{t('classes.shiftAfternoon')}</option>
          </select>
        </label>
        <button className="btn" type="button" onClick={() => void run(() => adminApi.updateClass(cls.id, { is_active: !cls.is_active }))}>
          {t(cls.is_active ? 'classPage.closeClass' : 'classPage.reopenClass')}
        </button>
      </div>
    </section>
  )
}
