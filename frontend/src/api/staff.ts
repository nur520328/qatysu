import { request } from './client'
import type { Shift } from './admin'

// Келісім: docs/api.md, «Пән мұғалімі» және «Сынып жетекшісі және завуч».

export type RoundStatus = 'present' | 'absent'
export type Reason = 'sick' | 'excused' | 'unexcused'
export type Permission = 'permitted' | 'unpermitted'

interface Person {
  id: number
  last_name: string
  first_name: string
  patronymic: string | null
  /** null: сол күні бұл оқушыға әлі белгі жоқ. */
  round_status: RoundStatus | null
  arrived_lesson: number | null
  left_lesson: number | null
}

/** Мұғалімге көрінетін жол: себеп, рұқсат, түсініктеме жоқ. */
export interface TeacherRow extends Person {
  class: { id: number; name: string }
  can_undo_arrival: boolean
  can_undo_leave: boolean
}

/** Жетекші мен завучқа көрінетін толық жол. */
export interface DayRow extends Person {
  absence_reason: Reason | null
  leave_permission: Permission | null
  note: string | null
  pending: 'reason' | 'permission' | null
}

export interface TeacherContext {
  date: string
  is_school_day: boolean
  max_lessons: number
  classes: { id: number; name: string; grade: number; shift: Shift }[]
}

export interface Day {
  class: { id: number; name: string }
  date: string
  is_school_day: boolean
  editable: boolean
  max_lessons: number
  duty: null | { saved_at: string; received_late: boolean }
  students: DayRow[]
}

export interface PendingItem {
  class: { id: number; name: string }
  date: string
  student: { id: number; name: string }
  kind: 'reason' | 'permission'
}

export interface HistoryChange {
  field: 'round_status' | 'arrived_lesson' | 'left_lesson' | 'absence_reason' | 'leave_permission' | 'note'
  old: string | null
  new: string | null
  role: 'duty' | 'teacher' | 'homeroom' | 'admin'
  by: string | null
  device_number: number | null
  at: string
}

export type DayPatch = Partial<Pick<DayRow, 'round_status' | 'arrived_lesson' | 'left_lesson' | 'absence_reason' | 'leave_permission' | 'note'>>

export const teacherApi2 = {
  context: () => request<TeacherContext>('GET', '/teacher/classes'),
  classToday: (classId: number) => request<{ class: { id: number; name: string }; students: TeacherRow[] }>('GET', `/teacher/classes/${classId}`),
  search: (q: string) => request<{ students: TeacherRow[] }>('GET', `/teacher/students?q=${encodeURIComponent(q)}`).then((r) => r.students),
  register: (studentId: number, kind: 'arrival' | 'leave', lesson: number) =>
    request<{ student: TeacherRow }>('POST', `/teacher/students/${studentId}/${kind}`, { lesson }).then((r) => r.student),
  undo: (studentId: number, kind: 'arrival' | 'leave') =>
    request<{ student: TeacherRow }>('DELETE', `/teacher/students/${studentId}/${kind}`).then((r) => r.student),
}

export const homeroomApi = {
  pending: () => request<{ items: PendingItem[] }>('GET', '/homeroom/pending').then((r) => r.items),
  day: (classId: number, date: string) => request<Day>('GET', `/homeroom/classes/${classId}/days/${date}`),
  update: (classId: number, date: string, studentId: number, patch: DayPatch) =>
    request<{ student: DayRow }>('PATCH', `/homeroom/classes/${classId}/days/${date}/students/${studentId}`, patch).then((r) => r.student),
  history: (classId: number, date: string, studentId: number) =>
    request<{ created: null | { at: string; by_duty: boolean }; changes: HistoryChange[] }>(
      'GET', `/homeroom/classes/${classId}/days/${date}/students/${studentId}/history`,
    ),
}

/** Жетекшінің айлық қорытындысындағы бір оқушы. Санауыштар апталық/айлық есептегідей. */
export interface MonthStudent {
  id: number
  name: string
  in_class_now: boolean
  marked: number
  absent: number
  sick: number
  excused: number
  unexcused: number
  pending: number
  late: number
  left: number
  left_permitted: number
  left_unpermitted: number
  left_pending: number
  attendance_pct: number | null
  dates: string[]
}

export interface ClassMonth {
  class: { id: number; name: string }
  month: { from: string; to: string; previous: string; next: string | null; is_current: boolean }
  complete: boolean
  days_expected: number
  days_surveyed: number
  missing_dates: string[]
  totals: Omit<MonthStudent, 'id' | 'name' | 'in_class_now' | 'dates'>
  students: MonthStudent[]
}

export const monthApi = {
  classMonth: (classId: number, date: string | null) =>
    request<ClassMonth>('GET', `/homeroom/classes/${classId}/month${date === null ? '' : `?date=${date}`}`),
}
