import { ApiError } from '../api/client'
import { dutyApi } from '../api/duty'
import type { DutyState, Roster } from '../api/duty'
import { uuid } from '../lib/uuid'
import { dutyDb } from './db'
import type { OutboxEntry } from './db'

/** Бір сыныптың кезекшіге көрінетін күйі. */
export interface ClassView {
  id: number
  name: string
  grade: number
  student_count: number
  /** Аралынбаған болса null. */
  absent_count: number | null
  /** synced: серверде; pending: планшетте, әлі жіберілмеген; қалғаны OutboxEntry.state-пен бірдей. */
  sync: 'none' | 'synced' | 'pending' | 'conflict' | 'review' | 'rejected'
}

export interface DutySnapshot {
  /** loading: қойма оқылуда; code: кодпен кіру керек; ready: жұмыс істеуге болады. */
  phase: 'loading' | 'code' | 'ready'
  /** Код бетінде көрсетілетін себеп. */
  notice: 'ended' | null
  session: DutyState | null
  classes: ClassView[]
  /** Серверге соңғы жүгіну сәтті болды ма. */
  online: boolean
  pending: number
  rejected: number
  /** Смена бітті (уақыты өтті не сервер сеансты жапты): белгілеу жабық, тек жіберу жүреді. */
  closed: boolean
}

const EMPTY: DutySnapshot = { phase: 'loading', notice: null, session: null, classes: [], online: true, pending: 0, rejected: 0, closed: false }

function ended(error: unknown): boolean {
  return error instanceof ApiError && (error.code === 'duty_code_ended' || error.code === 'duty_unauthenticated')
}

function temporary(error: unknown): boolean {
  return error instanceof ApiError && (error.code === 'network' || error.status >= 500 || error.status === 429 || error.status === 419)
}

/** Тізімге кезектегі (жіберілмеген) белгілерді қолданады: кезекші өз сақтағанын көреді. */
function withLocalMarks(roster: Roster, entry: OutboxEntry | undefined): Roster {
  // Қабылданбаған белгілер де көрсетіледі: кезекші оларды завучқа көрсетуі тиіс.
  if (entry === undefined || (entry.state !== 'pending' && entry.state !== 'rejected')) return roster
  const absent = new Set(entry.absent_student_ids)
  return {
    ...roster,
    students: roster.students.map((s) => (s.locked ? s : { ...s, status: absent.has(s.id) ? ('absent' as const) : ('present' as const) })),
  }
}

/**
 * Кезекші планшетінің күйі. Экрандар әрқашан осы қоймадан оқиды (интернетті күтпейді), ал серверге
 * жіберу артқы жақта жүреді. Ережелер: docs/кезең-5-жоспар.md.
 */
export class DutyStore {
  private snapshot: DutySnapshot = EMPTY
  private listeners = new Set<() => void>()
  private offset = 0
  private serverClosed = false
  private syncing: Promise<void> | null = null
  private timers: number[] = []

  subscribe = (listener: () => void): (() => void) => {
    this.listeners.add(listener)
    return () => this.listeners.delete(listener)
  }

  getSnapshot = (): DutySnapshot => this.snapshot

  /** Сервер сағаты бойынша қазіргі уақыт (мс). */
  now(): number {
    return Date.now() + this.offset
  }

  /** Бет ашылғанда: планшетте сақталған смена болса, интернетсіз де бірден ашылады. */
  async start(): Promise<void> {
    const stored = await dutyDb.session().catch(() => undefined)
    if (stored !== undefined) {
      this.offset = stored.clock_offset
      await this.publish(stored.state)
    }
    await this.refresh()
    if (this.snapshot.phase === 'loading') this.set({ ...EMPTY, phase: 'code' })

    this.timers.push(window.setInterval(() => void this.tick(), 20_000))
    window.addEventListener('online', this.onOnline)
  }

  stop(): void {
    for (const timer of this.timers) window.clearInterval(timer)
    this.timers = []
    window.removeEventListener('online', this.onOnline)
  }

  private onOnline = (): void => {
    void this.tick()
  }

  /** Кодпен кіру (интернет керек): смена күйі мен барлық сынып тізімі планшетке сақталады. */
  async login(code: string): Promise<void> {
    const state = await dutyApi.login(code)
    this.offset = new Date(state.server_time).getTime() - Date.now()
    this.serverClosed = false
    const { rosters } = await dutyApi.rosters()
    await dutyDb.clear()
    await dutyDb.putRosters(rosters)
    await dutyDb.setSession({ state, clock_offset: this.offset })
    await this.publish(state, true)
  }

  /** Сыныптың тізімі: планшеттегі дерек, кезекшінің жіберілмеген белгілерімен бірге. */
  async roster(classId: number): Promise<{ roster: Roster; entry: OutboxEntry | undefined } | null> {
    const [roster, outbox] = await Promise.all([dutyDb.roster(classId), dutyDb.outbox()])
    if (roster === undefined) return null
    const entry = outbox.find((e) => e.class_id === classId)
    return { roster: withLocalMarks(roster, entry), entry }
  }

  /** «Сақтау»: белгілер бірден планшетке жазылады, серверге жіберу бөлек жүреді. */
  async save(classId: number, absentIds: number[]): Promise<void> {
    if (this.snapshot.closed) throw new ApiError('duty_code_ended', 401)
    const [roster, outbox] = await Promise.all([dutyDb.roster(classId), dutyDb.outbox()])
    if (roster === undefined) throw new ApiError('class_not_in_shift', 404)
    const previous = outbox.find((e) => e.class_id === classId && e.state === 'pending')

    await dutyDb.putOutbox({
      class_id: classId,
      // Сақталған аралау түзетілсе, оның нөмірі; жіберілмеген аралау қайта сақталса, сол бұрынғы нөмір;
      // әйтпесе жаңа нөмір. Сонда сервер бір аралауды қанша рет алса да бір-ақ рет жазады.
      client_uuid: roster.round?.client_uuid ?? previous?.client_uuid ?? uuid(),
      revision: roster.round?.revision ?? null,
      absent_student_ids: absentIds,
      client_time: new Date(this.now()).toISOString(),
      state: 'pending',
    })
    await this.publish()
    void this.sync()
  }

  /** Жіберілмеген сыныптарды серверге жібереді. Бір уақытта бір-ақ жіберу жүреді. */
  sync(): Promise<void> {
    this.syncing ??= this.runSync().finally(() => {
      this.syncing = null
    })
    return this.syncing
  }

  private async runSync(): Promise<void> {
    for (const entry of (await dutyDb.outbox()).filter((e) => e.state === 'pending')) {
      try {
        const result = await dutyApi.saveRound(entry.class_id, {
          client_uuid: entry.client_uuid,
          absent_student_ids: entry.absent_student_ids,
          revision: entry.revision,
          client_time: entry.client_time,
        })
        // Кезекші осы арада сол сыныпты қайта сақтаған болса, жаңа белгілері кезекте қалады (жаңа нұсқамен).
        const latest = (await dutyDb.outbox()).find((e) => e.class_id === entry.class_id)
        const resaved = latest !== undefined && latest.client_time !== entry.client_time
        if (result.stored_as === 'conflict') {
          await dutyDb.settle(result, resaved ? null : { ...entry, state: 'conflict' })
        } else {
          await dutyDb.settle(result, resaved ? { ...latest, client_uuid: result.round!.client_uuid, revision: result.round!.revision } : null)
        }
        this.online = true
      } catch (caught) {
        if (temporary(caught)) {
          this.online = false
          break // байланыс жоқ: қалғаны да жетпейді, кейін қайталанады
        }
        this.online = true
        if (caught instanceof ApiError && (caught.code === 'round_changed' || caught.code === 'unknown_students')) {
          // Серверде тізім не белгілер өзгерген: жаңасын алып, кезекшіге қайта тексертеміз.
          const fresh = await dutyApi.roster(entry.class_id).catch(() => null)
          if (fresh !== null) await dutyDb.settle(fresh, { ...entry, state: 'review' })
          else await dutyDb.putOutbox({ ...entry, state: 'review' })
        } else {
          if (ended(caught)) this.serverClosed = true
          await dutyDb.putOutbox({ ...entry, state: 'rejected' })
        }
      }
    }
    await this.publish()
    await this.cleanupIfFinished()
  }

  private online = true

  /** Серверден смена күйі мен тізімдерді жаңартады (басқа планшеттің аралаулары көрінуі үшін). */
  async refresh(): Promise<void> {
    try {
      const state = await dutyApi.session()
      const { rosters } = await dutyApi.rosters()
      this.offset = new Date(state.server_time).getTime() - Date.now()
      // Кезекте тұрған сыныптың тізімін серверден келген ескі күй баспауы тиіс.
      const busy = new Set((await dutyDb.outbox()).filter((e) => e.state === 'pending').map((e) => e.class_id))
      await dutyDb.putRosters(rosters.filter((r) => !busy.has(r.class.id)))
      await dutyDb.setSession({ state, clock_offset: this.offset })
      this.online = true
      this.serverClosed = false
      await this.publish(state)
    } catch (caught) {
      if (ended(caught)) {
        this.online = true
        this.serverClosed = true
        await this.publish()
        await this.cleanupIfFinished()
      } else {
        this.online = false
        await this.publish()
        // Интернетсіз де: смена уақыты өтіп, жіберілмеген ештеңе қалмаса, оқушы дерегі планшетте тұрмайды.
        await this.cleanupIfFinished()
      }
    }
  }

  /** Шығу: жіберілмеген сынып тұрса мүмкін емес. Қабылданбаған дерек бар болса, шақырушы алдын ала растатады. */
  async logout(): Promise<boolean> {
    if (this.snapshot.pending > 0) return false
    await dutyApi.logout().catch(() => undefined)
    await dutyDb.clear()
    this.set({ ...EMPTY, phase: 'code' })
    return true
  }

  private async tick(): Promise<void> {
    if (this.snapshot.phase !== 'ready') return
    await this.sync()
    if (this.snapshot.phase === 'ready' && this.snapshot.pending === 0) await this.refresh()
    else await this.publish()
  }

  /** Смена бітіп, бәрі серверге жеткен болса, планшеттегі оқушы дерегі өшіріледі. */
  private async cleanupIfFinished(): Promise<void> {
    const snapshot = this.snapshot
    if (snapshot.phase !== 'ready' || !snapshot.closed || snapshot.pending > 0 || snapshot.rejected > 0) return
    await dutyDb.clear()
    this.set({ ...EMPTY, phase: 'code', notice: 'ended' })
  }

  private async publish(state?: DutyState, justLoggedIn = false): Promise<void> {
    const session = state ?? this.snapshot.session ?? (await dutyDb.session())?.state ?? null
    if (session === null) return
    const [rosters, outbox] = await Promise.all([dutyDb.rosters(), dutyDb.outbox()])
    const entries = new Map(outbox.map((e) => [e.class_id, e]))
    const byClass = new Map(rosters.map((r) => [r.class.id, r]))

    const classes = session.classes.map((cls): ClassView => {
      const entry = entries.get(cls.id)
      const roster = byClass.get(cls.id)
      const view = roster === undefined ? undefined : withLocalMarks(roster, entry)
      const marked = entry?.state === 'pending' || entry?.state === 'rejected' || (roster?.round ?? null) !== null
      return {
        id: cls.id,
        name: cls.name,
        grade: cls.grade,
        student_count: view?.students.length ?? cls.student_count,
        absent_count: marked && view !== undefined ? view.students.filter((s) => s.status === 'absent').length : null,
        sync: entry?.state ?? (roster?.round ? 'synced' : 'none'),
      }
    })

    this.set({
      phase: 'ready',
      notice: null,
      session,
      classes,
      online: justLoggedIn ? true : this.online,
      pending: outbox.filter((e) => e.state === 'pending').length,
      rejected: outbox.filter((e) => e.state === 'rejected').length,
      closed: this.serverClosed || this.now() > new Date(session.expires_at).getTime(),
    })
  }

  private set(next: DutySnapshot): void {
    this.snapshot = next
    for (const listener of this.listeners) listener()
  }
}
