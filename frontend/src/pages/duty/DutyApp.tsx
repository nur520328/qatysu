import { useCallback, useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { dutyApi } from '../../api/duty'
import type { DutyClass, DutyState } from '../../api/duty'
import { Shell } from '../../components/Shell'
import { clockTime, dayMonth } from '../../lib/dates'
import { useErrorText } from '../../lib/errors'
import { DutyClassPage } from './DutyClassPage'
import { isDutyEnded } from './ended'

type Phase =
  | { name: 'loading' }
  | { name: 'code'; notice: 'ended' | null }
  | { name: 'classes'; state: DutyState; flash: string | null }
  | { name: 'class'; state: DutyState; target: DutyClass }

/**
 * Кезекшінің планшеттегі жұмысы: код енгізу → сыныптар → белгілеу.
 * Беттер мекенжайға байланбаған: бір экран, ішкі күй (5-кезеңде интернетсіз де жұмыс істеуі үшін).
 */
export function DutyApp() {
  const { t } = useTranslation()
  const [phase, setPhase] = useState<Phase>({ name: 'loading' })

  const refresh = useCallback((flash: string | null = null) => {
    return dutyApi.session().then(
      // Жауап кешігіп келгенде кезекші келесі сыныпты ашып үлгеруі мүмкін: ондайда оны сыныптан шығарып жібермейміз.
      (state) => setPhase((p) => (p.name === 'classes' ? { name: 'classes', state, flash: flash ?? p.flash } : p)),
      (caught: unknown) => {
        // Байланыс уақытша үзілсе, экран өзгермейді; сеанс біткенде ғана код бетіне қайтады.
        if (isDutyEnded(caught)) setPhase({ name: 'code', notice: 'ended' })
        else if (flash !== null) setPhase((p) => (p.name === 'classes' ? { ...p, flash } : p))
      },
    )
  }, [])

  // Бет ашылғанда: планшет бұрын кірген болса, бірден сыныптарға өтеді; әйтпесе код сұралады.
  useEffect(() => {
    let alive = true
    dutyApi.session().then(
      (state) => { if (alive) setPhase({ name: 'classes', state, flash: null }) },
      () => { if (alive) setPhase({ name: 'code', notice: null }) },
    )
    return () => { alive = false }
  }, [])

  // Басқа планшеттің аралаулары мен смена соңы минут сайын тексеріледі (тек сыныптар бетінде).
  const onList = phase.name === 'classes'
  useEffect(() => {
    if (!onList) return
    const timer = window.setInterval(() => void refresh(), 60_000)
    return () => window.clearInterval(timer)
  }, [onList, refresh])

  if (phase.name === 'loading') return <p className="splash" role="status">{t('common.loading')}</p>

  if (phase.name === 'code') {
    return <CodeEntry notice={phase.notice} onEntered={(state) => setPhase({ name: 'classes', state, flash: null })} />
  }

  if (phase.name === 'class') {
    return (
      <DutyClassPage
        target={phase.target}
        onDone={(flash) => {
          // Алдымен бұрынғы тізім көрсетіледі, содан кейін серверден жаңасы келеді.
          setPhase({ name: 'classes', state: phase.state, flash })
          void refresh(flash)
        }}
        onEnded={() => setPhase({ name: 'code', notice: 'ended' })}
      />
    )
  }

  return (
    <ClassList
      state={phase.state}
      flash={phase.flash}
      onOpen={(target) => setPhase({ name: 'class', state: phase.state, target })}
      onLeft={() => setPhase({ name: 'code', notice: null })}
    />
  )
}

function CodeEntry({ notice, onEntered }: { notice: 'ended' | null; onEntered: (state: DutyState) => void }) {
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
      onEntered(await dutyApi.login(code))
    } catch (caught) {
      setError(errorText(caught))
      setBusy(false)
    }
  }

  return (
    <Shell>
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
  state: DutyState
  flash: string | null
  onOpen: (target: DutyClass) => void
  onLeft: () => void
}

function ClassList({ state, flash, onOpen, onLeft }: ListProps) {
  const { t, i18n } = useTranslation()
  const done = state.classes.filter((c) => c.round !== null).length
  const grades = [...new Set(state.classes.map((c) => c.grade))]

  async function leave() {
    if (!window.confirm(t('duty.logoutConfirm'))) return
    await dutyApi.logout().catch(() => undefined)
    onLeft()
  }

  return (
    <Shell wide actions={<button className="btn" type="button" onClick={() => void leave()}>{t('duty.logout')}</button>}>
      <section className="page">
        <header>
          <h1 className="page__title">
            {t('duty.header', { shift: t(state.shift === 'morning' ? 'duty.shiftMorning' : 'duty.shiftAfternoon'), date: dayMonth(state.date, i18n.language) })}
          </h1>
          <p className="muted">
            {t('duty.until', { time: clockTime(state.expires_at) })} · {t('duty.device', { n: state.device_number })}
          </p>
        </header>

        {flash !== null && <p className="note" role="status">{flash}</p>}
        <p className={done === state.classes.length && done > 0 ? 'duty-progress duty-progress--done' : 'duty-progress'}>
          {t('duty.progress', { done, total: state.classes.length })}
          {done === state.classes.length && done > 0 && ` ${t('duty.allDone')}`}
        </p>

        {grades.map((grade) => (
          <section key={grade} className="group" aria-label={t('duty.grade', { grade })}>
            <h2 className="group__title">{t('duty.grade', { grade })}</h2>
            <ul className="tiles tiles--duty">
              {state.classes.filter((c) => c.grade === grade).map((cls) => (
                <li key={cls.id}>
                  <button type="button" className={cls.round === null ? 'tile tile--todo' : 'tile tile--done'} onClick={() => onOpen(cls)}>
                    <span className="tile__name">{cls.name}</span>
                    <span className="tile__line">
                      {cls.round === null
                        ? t('duty.notDone')
                        : cls.round.absent_count === 0 ? t('duty.nobodyAbsent') : t('duty.absent', { count: cls.round.absent_count })}
                    </span>
                    <span className="tile__line tile__line--small">{t('duty.students', { count: cls.student_count })}</span>
                  </button>
                </li>
              ))}
            </ul>
          </section>
        ))}
      </section>
    </Shell>
  )
}
