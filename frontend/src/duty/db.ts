import type { DutyState, Roster } from '../api/duty'

/**
 * Планшеттегі қойма (IndexedDB). Мұнда тек осы сменаның сынып тізімдері мен бүгінгі белгілер
 * сақталады; бәрі серверге жеткен соң смена біткенде не шыққанда өшіріледі.
 */

/** Серверге жіберілуі тиіс (не жіберіліп, ерекше жауап алған) бір сыныптың белгілері. */
export interface OutboxEntry {
  class_id: number
  client_uuid: string
  /** Сақталған аралауды түзеу болса, оның серверден алынған нұсқасы; жаңа аралауда null. */
  revision: number | null
  absent_student_ids: number[]
  /** Белгіленген уақыт (сервер сағатына түзетілген), ISO. */
  client_time: string
  /**
   * pending: әлі жіберілмеген; conflict: сервер қақтығыс ретінде сақтады (завучқа жетті);
   * review: серверде басқа планшет өзгерткен, кезекші қайта тексеруі тиіс;
   * rejected: сервер қабылдамады (код жойылған не тым кеш).
   */
  state: 'pending' | 'conflict' | 'review' | 'rejected'
}

export interface StoredSession {
  state: DutyState
  /** Сервер сағаты минус планшет сағаты, мс. Планшеттің сағаты қате болуы мүмкін. */
  clock_offset: number
}

const NAME = 'qatysu-duty'
const STORES = ['kv', 'rosters', 'outbox'] as const
type StoreName = (typeof STORES)[number]

function open(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(NAME, 1)
    request.onupgradeneeded = () => {
      const db = request.result
      db.createObjectStore('kv')
      db.createObjectStore('rosters', { keyPath: 'class.id' })
      db.createObjectStore('outbox', { keyPath: 'class_id' })
    }
    request.onsuccess = () => resolve(request.result)
    request.onerror = () => reject(request.error ?? new Error('indexeddb'))
  })
}

async function run<T>(stores: StoreName[], mode: IDBTransactionMode, work: (tx: IDBTransaction) => IDBRequest<T> | null): Promise<T> {
  const db = await open()
  try {
    return await new Promise<T>((resolve, reject) => {
      const tx = db.transaction(stores, mode)
      const request = work(tx)
      tx.oncomplete = () => resolve((request?.result ?? undefined) as T)
      tx.onerror = () => reject(tx.error ?? new Error('indexeddb'))
      tx.onabort = () => reject(tx.error ?? new Error('indexeddb'))
    })
  } finally {
    db.close()
  }
}

export const dutyDb = {
  session: () => run<StoredSession | undefined>(['kv'], 'readonly', (tx) => tx.objectStore('kv').get('session')),
  setSession: (value: StoredSession) => run(['kv'], 'readwrite', (tx) => tx.objectStore('kv').put(value, 'session')),

  rosters: () => run<Roster[]>(['rosters'], 'readonly', (tx) => tx.objectStore('rosters').getAll()),
  roster: (classId: number) => run<Roster | undefined>(['rosters'], 'readonly', (tx) => tx.objectStore('rosters').get(classId)),
  putRosters: (rosters: Roster[]) =>
    run(['rosters'], 'readwrite', (tx) => {
      for (const roster of rosters) tx.objectStore('rosters').put(roster)
      return null
    }),

  outbox: () => run<OutboxEntry[]>(['outbox'], 'readonly', (tx) => tx.objectStore('outbox').getAll()),
  putOutbox: (entry: OutboxEntry) => run(['outbox'], 'readwrite', (tx) => tx.objectStore('outbox').put(entry)),
  deleteOutbox: (classId: number) => run(['outbox'], 'readwrite', (tx) => tx.objectStore('outbox').delete(classId)),

  /** Сервер жауабын қабылдау: тізім жаңарады, кезектегі жазба өшеді не күйі өзгереді (бір транзакцияда). */
  settle: (roster: Roster, entry: OutboxEntry | null) =>
    run(['rosters', 'outbox'], 'readwrite', (tx) => {
      tx.objectStore('rosters').put(roster)
      if (entry === null) tx.objectStore('outbox').delete(roster.class.id)
      else tx.objectStore('outbox').put(entry)
      return null
    }),

  /** Планшеттегі барлық оқушы дерегін өшіреді. */
  clear: () =>
    run([...STORES], 'readwrite', (tx) => {
      for (const store of STORES) tx.objectStore(store).clear()
      return null
    }),
}
