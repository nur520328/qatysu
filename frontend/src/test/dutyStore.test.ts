import 'fake-indexeddb/auto'
import { IDBFactory } from 'fake-indexeddb'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { DutyState, Roster, Round } from '../api/duty'
import { dutyDb } from '../duty/db'
import { DutyStore } from '../duty/store'
import { mockServer } from './mockServer'
import type { Call, Handler } from './mockServer'

// Барлық дерек ойдан шығарылған. Сервер сағаты: 2026-11-20 08:10 (+05:00), смена 13:10-да бітеді.
const SERVER_NOW = '2026-11-20T08:10:00+05:00'
const ok = (body: unknown): Handler => () => ({ status: 200, body })

function state(): DutyState {
  return {
    date: '2026-11-20', shift: 'morning', expires_at: '2026-11-20T13:10:00+05:00', server_time: SERVER_NOW, device_number: 1,
    classes: [
      { id: 11, name: '5А', grade: 5, student_count: 3, round: null },
      { id: 12, name: '5Ә', grade: 5, student_count: 2, round: null },
    ],
  }
}

function roster(id: number, name: string, absent: number[] = [], round: Round | null = null): Roster {
  const base = id * 10
  return {
    class: { id, name },
    round,
    students: [1, 2, 3].slice(0, id === 12 ? 2 : 3).map((n) => ({
      id: base + n, last_name: `Сынақов${n}`, first_name: 'Оқушы', patronymic: null,
      status: absent.includes(base + n) ? ('absent' as const) : ('present' as const), locked: false,
    })),
  }
}

const saved = (uuid: string, revision: number, absent: number): Round => ({ client_uuid: uuid, revision, absent_count: absent, saved_at: SERVER_NOW, received_late: false })

/** Желі күйін басқаруға болатын жалған сервер: `offline = true` болса, сұраныстар жетпейді. */
function server(extra: Record<string, Handler> = {}) {
  const net = { offline: false }
  const calls: Call[] = mockServer({
    'POST /api/duty/login': ok(state()),
    'GET /api/duty/session': ok(state()),
    'GET /api/duty/rosters': ok({ rosters: [roster(11, '5А'), roster(12, '5Ә')] }),
    'POST /api/duty/logout': () => ({ status: 204 }),
    ...extra,
  })
  const online = globalThis.fetch
  vi.stubGlobal('fetch', vi.fn((input: RequestInfo | URL, init?: RequestInit) => (net.offline ? Promise.reject(new TypeError('offline')) : online(input, init))))
  return { net, calls, puts: () => calls.filter((c) => c.key.startsWith('PUT')) }
}

const view = (store: DutyStore, id: number) => store.getSnapshot().classes.find((c) => c.id === id)!

beforeEach(() => {
  globalThis.indexedDB = new IDBFactory()
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date(SERVER_NOW))
})
afterEach(() => vi.useRealTimers())

describe('планшет қоймасы', () => {
  it('кіргенде барлық тізім сақталады; кейін интернетсіз де смена бірден ашылады', async () => {
    const { net } = server()
    const first = new DutyStore()
    await first.login('123456')
    expect(first.getSnapshot()).toMatchObject({ phase: 'ready', pending: 0, closed: false, online: true })
    expect((await dutyDb.rosters()).map((r) => r.class.name)).toEqual(['5А', '5Ә'])

    // Планшет қайта жүктелді, интернет жоқ.
    net.offline = true
    const second = new DutyStore()
    await second.start()
    second.stop()

    expect(second.getSnapshot()).toMatchObject({ phase: 'ready', online: false })
    expect(second.getSnapshot().classes.map((c) => [c.name, c.sync])).toEqual([['5А', 'none'], ['5Ә', 'none']])
    expect((await second.roster(11))?.roster.students).toHaveLength(3)
  })

  it('планшетте смена жоқ және сервер де танымаса, код сұралады', async () => {
    server({ 'GET /api/duty/session': () => ({ status: 401, body: { code: 'duty_unauthenticated' } }) })
    const store = new DutyStore()
    await store.start()
    store.stop()
    expect(store.getSnapshot()).toMatchObject({ phase: 'code', notice: null })
  })
})

describe('интернетсіз белгілеу және жіберу', () => {
  it('интернет жоқта белгілер планшетте қалады, байланыс шыққанда серверге жетеді', async () => {
    let uuidSeen = ''
    const { net, puts } = server({
      'PUT /api/duty/classes/11/round': (_init, body) => {
        uuidSeen = (body as { client_uuid: string }).client_uuid
        return { status: 200, body: { ...roster(11, '5А', [112], saved(uuidSeen, 1, 1)), stored_as: 'round', kept_locked: [] } }
      },
    })
    const store = new DutyStore()
    await store.login('123456')

    net.offline = true
    await store.save(11, [112])
    await store.sync()

    expect(store.getSnapshot()).toMatchObject({ pending: 1, online: false })
    expect(view(store, 11)).toMatchObject({ sync: 'pending', absent_count: 1 })
    expect((await store.roster(11))?.roster.students.map((s) => s.status)).toEqual(['present', 'absent', 'present'])
    expect(await store.logout()).toBe(false) // жіберілмеген дерекпен шығуға болмайды
    expect(await dutyDb.rosters()).toHaveLength(2)

    net.offline = false
    await store.sync()

    expect(store.getSnapshot()).toMatchObject({ pending: 0, online: true })
    expect(view(store, 11)).toMatchObject({ sync: 'synced', absent_count: 1 })
    expect(await dutyDb.outbox()).toEqual([])
    expect((await dutyDb.roster(11))?.round?.client_uuid).toBe(uuidSeen)
    expect(puts()[0].body).toMatchObject({ absent_student_ids: [112], revision: null, client_time: '2026-11-20T03:10:00.000Z' })
  })

  it('жіберілмеген сынып қайта сақталса, сол нөмірмен соңғы белгілер ғана жіберіледі', async () => {
    const { net, puts } = server({
      'PUT /api/duty/classes/11/round': (_init, body) => {
        const sent = body as { client_uuid: string; absent_student_ids: number[] }
        return { status: 200, body: { ...roster(11, '5А', sent.absent_student_ids, saved(sent.client_uuid, 1, sent.absent_student_ids.length)), stored_as: 'round', kept_locked: [] } }
      },
    })
    const store = new DutyStore()
    await store.login('123456')
    net.offline = true
    await store.save(11, [112])
    await store.sync()
    const firstUuid = (await dutyDb.outbox())[0].client_uuid
    await store.save(11, [111, 113])
    await store.sync()

    net.offline = false
    await store.sync()

    expect(puts()).toHaveLength(1)
    expect(puts()[0].body).toMatchObject({ client_uuid: firstUuid, absent_student_ids: [111, 113] })
    expect(view(store, 11)).toMatchObject({ sync: 'synced', absent_count: 2 })
  })

  it('сақталған сынып сервердегі нөмірі және нұсқасымен түзетіледі', async () => {
    const existing = saved('11111111-1111-4111-8111-111111111111', 3, 1)
    const { puts } = server({
      'GET /api/duty/rosters': ok({ rosters: [roster(11, '5А', [112], existing), roster(12, '5Ә')] }),
      'PUT /api/duty/classes/11/round': ok({ ...roster(11, '5А', [], { ...existing, revision: 4, absent_count: 0 }), stored_as: 'round', kept_locked: [] }),
    })
    const store = new DutyStore()
    await store.login('123456')
    expect(view(store, 11)).toMatchObject({ sync: 'synced', absent_count: 1 })

    await store.save(11, [])
    await store.sync()

    expect(puts()[0].body).toMatchObject({ client_uuid: existing.client_uuid, revision: 3, absent_student_ids: [] })
    expect((await dutyDb.roster(11))?.round?.revision).toBe(4)
  })

  it('басқа планшет сол сыныпты белгілеп қойса, дерек завучқа кетеді, планшет сервердегі күйді көрсетеді', async () => {
    const other = saved('99999999-9999-4999-8999-999999999999', 1, 1)
    server({ 'PUT /api/duty/classes/11/round': ok({ ...roster(11, '5А', [113], other), stored_as: 'conflict', kept_locked: [] }) })
    const store = new DutyStore()
    await store.login('123456')
    await store.save(11, [111])
    await store.sync()

    expect(store.getSnapshot().pending).toBe(0)
    expect(view(store, 11)).toMatchObject({ sync: 'conflict', absent_count: 1 })
    expect((await store.roster(11))?.roster.students.map((s) => s.status)).toEqual(['present', 'present', 'absent'])
    await store.sync() // қайта жіберілмейді
    expect((await dutyDb.outbox())[0].state).toBe('conflict')
  })

  it('серверде белгілер өзгерген болса, сынып «қайта тексеріңіз» күйіне түседі', async () => {
    const theirs = saved('11111111-1111-4111-8111-111111111111', 2, 1)
    const { puts } = server({
      'PUT /api/duty/classes/11/round': () => ({ status: 409, body: { code: 'round_changed' } }),
      'GET /api/duty/classes/11': ok(roster(11, '5А', [113], theirs)),
    })
    const store = new DutyStore()
    await store.login('123456')
    await store.save(11, [111])
    await store.sync()
    await store.sync()

    expect(puts()).toHaveLength(1)
    expect(view(store, 11)).toMatchObject({ sync: 'review', absent_count: 1 })
    expect((await store.roster(11))?.roster.round?.revision).toBe(2)
    expect((await store.roster(11))?.roster.students.map((s) => s.status)).toEqual(['present', 'present', 'absent'])
  })

  it('серверден жаңарту жіберілмеген сыныптың белгілерін баспайды', async () => {
    const { net } = server()
    const store = new DutyStore()
    await store.login('123456')
    net.offline = true
    await store.save(11, [112])
    await store.sync()

    net.offline = false
    // Тек жаңарту келді (жіберу әлі жүрген жоқ): серверде 5А әлі «аралынбаған».
    await store.refresh()

    expect(view(store, 11)).toMatchObject({ sync: 'pending', absent_count: 1 })
    expect((await store.roster(11))?.roster.students[1].status).toBe('absent')
  })
})

describe('смена соңы', () => {
  it('смена уақыты сервер сағатымен есептеледі, планшеттің сағаты қате болса да', async () => {
    server()
    // Планшеттің сағаты 6 сағат алда тұр (14:10), ал сервер бойынша 08:10.
    vi.setSystemTime(new Date('2026-11-20T14:10:00+05:00'))
    const store = new DutyStore()
    await store.login('123456')

    expect(store.getSnapshot().closed).toBe(false)
    await store.save(12, [])
    expect((await dutyDb.outbox())[0].client_time).toBe('2026-11-20T03:10:00.000Z')
  })

  it('смена біткен соң белгілеу жабық, ал жіберілмеген сынып кейін де жіберіледі; бәрі жеткенде планшет тазаланады', async () => {
    const { net, puts } = server({
      'PUT /api/duty/classes/11/round': (_init, body) => ({
        status: 200,
        body: { ...roster(11, '5А', [112], { ...saved((body as { client_uuid: string }).client_uuid, 1, 1), received_late: true }), stored_as: 'round', kept_locked: [] },
      }),
    })
    const store = new DutyStore()
    await store.login('123456')
    net.offline = true
    await store.save(11, [112])
    await store.sync()

    // Смена бітті (13:10), интернет әлі жоқ.
    vi.setSystemTime(new Date('2026-11-20T13:10:01+05:00'))
    await store.refresh()
    expect(store.getSnapshot()).toMatchObject({ phase: 'ready', closed: true, pending: 1 })
    await expect(store.save(12, [])).rejects.toMatchObject({ code: 'duty_code_ended' })
    expect(await dutyDb.rosters()).toHaveLength(2) // жіберілмеген дерек бар: ештеңе өшірілмейді

    // Интернет шықты: смена кезінде белгіленген сынып қабылданады, содан кейін планшет тазаланады.
    net.offline = false
    await store.sync()

    expect(puts()[0].body).toMatchObject({ client_time: '2026-11-20T03:10:00.000Z' })
    expect(store.getSnapshot()).toMatchObject({ phase: 'code', notice: 'ended' })
    expect(await dutyDb.rosters()).toEqual([])
    expect(await dutyDb.session()).toBeUndefined()
  })

  it('код жойылса, жіберілмеген дерек «қабылданбады» болып планшетте қалады', async () => {
    const { net } = server({
      'PUT /api/duty/classes/11/round': () => ({ status: 401, body: { code: 'duty_code_ended' } }),
    })
    const store = new DutyStore()
    await store.login('123456')
    net.offline = true
    await store.save(11, [112])
    await store.sync()
    net.offline = false
    await store.sync()

    expect(store.getSnapshot()).toMatchObject({ phase: 'ready', closed: true, pending: 0, rejected: 1 })
    expect(view(store, 11)).toMatchObject({ sync: 'rejected' })
    // Кезекші завучқа көрсеткен соң шыға алады; сонда ғана дерек өшеді.
    expect(await store.logout()).toBe(true)
    expect(await dutyDb.outbox()).toEqual([])
  })

  it('шыққанда планшеттегі оқушы дерегі өшіріледі', async () => {
    const { calls } = server()
    const store = new DutyStore()
    await store.login('123456')

    expect(await store.logout()).toBe(true)
    expect(store.getSnapshot().phase).toBe('code')
    expect(await dutyDb.rosters()).toEqual([])
    expect(calls.some((c) => c.key === 'POST /api/duty/logout')).toBe(true)
  })
})
