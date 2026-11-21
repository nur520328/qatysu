import { request } from './client'
import type { Shift } from './admin'

// Келісім: docs/api.md, «Кезекші API».

export interface Round {
  client_uuid: string
  revision: number
  absent_count: number
  saved_at: string
  received_late: boolean
}

export interface DutyClass {
  id: number
  name: string
  grade: number
  student_count: number
  round: Round | null
}

export interface DutyState {
  date: string
  shift: Shift
  expires_at: string
  server_time: string
  device_number: number
  classes: DutyClass[]
}

export interface DutyStudent {
  id: number
  last_name: string
  first_name: string
  patronymic: string | null
  status: 'present' | 'absent'
  locked: boolean
}

export interface Roster {
  class: { id: number; name: string }
  round: Round | null
  students: DutyStudent[]
}

export interface SaveResult extends Roster {
  stored_as: 'round' | 'conflict'
  kept_locked: number[]
}

export const dutyApi = {
  login: (code: string) => request<DutyState>('POST', '/duty/login', { code }),
  session: () => request<DutyState>('GET', '/duty/session'),
  logout: () => request<void>('POST', '/duty/logout'),
  rosters: () => request<{ rosters: Roster[] }>('GET', '/duty/rosters'),
  roster: (classId: number) => request<Roster>('GET', `/duty/classes/${classId}`),
  saveRound: (classId: number, body: { client_uuid: string; absent_student_ids: number[]; revision: number | null; client_time: string }) =>
    request<SaveResult>('PUT', `/duty/classes/${classId}/round`, body),
}
