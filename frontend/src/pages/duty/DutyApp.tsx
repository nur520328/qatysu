import { useEffect, useState, useSyncExternalStore } from 'react'
import type { FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Shell } from '../../components/Shell'
import { DutyStore } from '../../duty/store'
import type { ClassView, DutySnapshot } from '../../duty/store'
import { clockTime, dayMonth } from '../../lib/dates'
import { useErrorText } from '../../lib/errors'
import { DutyClassPage } from './DutyClassPage'

/**
 * Кезекшінің планшеттегі жұмысы: код енгізу → сыныптар → белгілеу.
 * Экрандар планшеттегі қоймадан оқиды, сондықтан интернетсіз де жұмыс істейді (docs/кезең-5-жоспар.md).
 * Беттер мекенжайға байланбаған: бір экран, ішкі күй.
 */
export function DutyApp() {
  const { t } = useTranslation()
  const [store] = useState(() => new DutyStore())
  const snapshot = useSyncExternalStore(store.subscribe, store.getSnapshot)
  const [open, setOpen] = useState<ClassView | null>(null)
  const [flash, setFlash] = useState<string | null>(null)

  useEffect(() => {
    void store.start()
    return () => store.stop()
  }, [store])

  if (snapshot.phase === 'loading') return <p className="splash" role="status">{t('common.loading')}</p>
  if (snapshot.phase === 'code') return <CodeEntry store={store} notice={snapshot.notice} />

  if (open !== null) {
    return (
      <DutyClassPage
        store={store}
        target={open}
        readOnly={snapshot.closed}
        onDone={(message) => {
          setFlash(message)
          setOpen(null)
        }}
      />
    )
  }

  return <ClassList store={store} snapshot={snapshot} flash={flash} onOpen={(target) => { setFlash(null); setOpen(target) }} />
}

function CodeEntry({ store, notice }: { store: DutyStore; notice: 'ended' | null }) {
  const { t } = useTranslation()
  const errorText = useErrorText()
  const [code, setCode] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (busy || code.length !== 6) return
    setBusy(true)
    setError(null)
    try {
      await store.login(code)
    } catch (caught) {
      setError(errorText(caught))
      setBusy(false)
    }
  }

  return (
    <Shell about={false}>
      <form className="card" onSubmit={onSubmit} noValidate>
        <h1 className="card__title">{t('duty.codeTitle')}</h1>
        <p className="card__text">{t('duty.codeLead')}</p>
        {notice === 'ended' && error === null && <p className="note" role="status">{t('duty.ended')}</p>}
        <label className="field">
          <span className="field__label">{t('duty.codeLabel')}</span>
          <input
            className="field__input field__input--code"
            inputMode="numeric"
            autoComplete="off"
            maxLength={6}
            value={code}
            onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
          />
        </label>
        {error !== null && <p className="alert" role="alert">{error}</p>}
        <button className="btn btn--primary" type="submit" disabled={busy || code.length !== 6}>
          {busy ? t('duty.entering') : t('duty.enter')}
        </button>
        <Link className="back" to="/">{t('duty.staffLink')}</Link>
      </form>
    </Shell>
  )
}

interface ListProps {
  store: DutyStore
  snapshot: DutySnapshot
  flash: string | null
  onOpen: (target: ClassView) => void
}

const SYNC_LABEL = { pending: 'duty.syncPending', conflict: 'duty.syncConflict', review: 'duty.syncReview', rejected: 'duty.syncRejected' } as const

function ClassList({ store, snapshot, flash, onOpen }: ListProps) {
  const { t, i18n } = useTranslation()
  const [sending, setSending] = useState(false)
  const [blocked, setBlocked] = useState(false)
  const session = snapshot.session
  if (session === null) return null

  const done = snapshot.classes.filter((c) => c.absent_count !== null).length
  const total = snapshot.classes.length
  const grades = [...new Set(snapshot.classes.map((c) => c.grade))]

  async function leave() {
    if (snapshot.pending > 0) {
      setBlocked(true)
      return
    }
    const question = snapshot.rejected > 0 ? t('duty.logoutRejectedConfirm') : t('duty.logoutConfirm')
    if (!window.confirm(question)) return
    await store.logout()
  }

  async function sendNow() {
    setSending(true)
    await store.sync()
    setSending(false)
  }

  return (
    <Shell wide about={false} actions={<button className="btn" type="button" onClick={() => void leave()}>{t('duty.logout')}</button>}>
      <section className="page">
        <header>
          <h1 className="page__title">
            {t('duty.header', { shift: t(session.shift === 'morning' ? 'duty.shiftMorning' : 'duty.shiftAfternoon'), date: dayMonth(session.date, i18n.language) })}
          </h1>
          <p className="muted">
            {snapshot.closed ? t('duty.closed') : t('duty.until', { time: clockTime(session.expires_at) })} · {t('duty.device', { n: session.device_number })}
          </p>
        </header>

        {blocked && snapshot.pending > 0 && <p className="alert" role="alert">{t('duty.logoutBlocked')}</p>}
        {snapshot.rejected > 0 && <p className="alert" role="alert">{t('duty.rejected', { count: snapshot.rejected })}</p>}

        {snapshot.pending > 0 ? (
          <div className="syncbar syncbar--warn" role="status">
            <div>
              <p className="syncbar__main">{t('duty.pending', { count: snapshot.pending })}</p>
              <p className="syncbar__hint">{snapshot.online ? t('duty.sending') : t('duty.pendingHint')}</p>
            </div>
            <button className="btn" type="button" disabled={sending} onClick={() => void sendNow()}>
              {sending ? t('duty.sending') : t('duty.sendNow')}
            </button>
          </div>
        ) : !snapshot.online ? (
          <p className="syncbar" role="status">{t('duty.offline')}</p>
        ) : done > 0 && snapshot.rejected === 0 ? (
          <p className="syncbar syncbar--ok" role="status">{t('duty.allSynced')}</p>
        ) : null}
        {snapshot.pending > 0 && !snapshot.online && <p className="muted">{t('duty.offline')}</p>}

        {flash !== null && <p className="note">{flash}</p>}
        <p className={done === total && done > 0 ? 'duty-progress duty-progress--done' : 'duty-progress'}>
          {t('duty.progress', { done, total })}
          {done === total && done > 0 && ` ${t('duty.allDone')}`}
        </p>

        {grades.map((grade) => (
          <section key={grade} className="group" aria-label={t('duty.grade', { grade })}>
            <h2 className="group__title">{t('duty.grade', { grade })}</h2>
            <ul className="tiles tiles--duty">
              {snapshot.classes.filter((c) => c.grade === grade).map((cls) => {
                const flagged = cls.sync !== 'none' && cls.sync !== 'synced'
                return (
                  <li key={cls.id}>
                    <button type="button" onClick={() => onOpen(cls)}
                      className={cls.absent_count === null ? 'tile tile--todo' : flagged ? 'tile tile--flag' : 'tile tile--done'}>
                      <span className="tile__name">{cls.name}</span>
                      <span className="tile__line">
                        {cls.absent_count === null
                          ? t('duty.notDone')
                          : cls.absent_count === 0 ? t('duty.nobodyAbsent') : t('duty.absent', { count: cls.absent_count })}
                      </span>
                      <span className="tile__line tile__line--small">
                        {flagged ? t(SYNC_LABEL[cls.sync as keyof typeof SYNC_LABEL]) : t('duty.students', { count: cls.student_count })}
                      </span>
                    </button>
                  </li>
                )
              })}
            </ul>
          </section>
        ))}
      </section>
    </Shell>
  )
}
