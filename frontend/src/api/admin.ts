import { request, upload } from './client'
import type { Role } from './client'

// Келісім: docs/api.md, «Әкімші API».

export type Shift = 'morning' | 'afternoon'
export type ClassLanguage = 'kk' | 'ru'

export interface Year {
  id: number
  name: string
  start_date: string
  end_date: string
  is_current: boolean
}

export interface Day {
  date: string
  is_school_day: boolean
  note: string | null
}

export interface SchoolClass {
  id: number
  name: string
  grade: number
  letter: string
  shift: Shift
  language: ClassLanguage
  is_active: boolean
  homeroom: { id: number; full_name: string } | null
  student_count: number | null
}

export interface Student {
  id: number
  last_name: string
  first_name: string
  patronymic: string | null
  class_id: number | null
}

export interface Account {
  id: number
  full_name: string
  login: string
  role: Role
  is_active: boolean
  must_change_password: boolean
  homeroom_classes: string[]
}

export interface ImportRow {
  sheet?: string
  row: number
  last_name?: string | null
  first_name?: string | null
  patronymic?: string | null
  full_name?: string
  class?: string
  homeroom?: string | null
  current_class?: string
  code?: string
}

export interface StudentImportResult {
  digest: string
  imported: number | null
  summary: { new: number; exists: number; other_class: number; errors: number }
  sheets: { name: string; new: number; exists: number; other_class: number; errors: number; error: string | null }[]
  problems: ImportRow[]
  other_class: ImportRow[]
}

export interface Credential {
  full_name: string
  login: string
  password: string
  homeroom: string | null
}

export interface TeacherImportResult {
  digest: string
  summary: { new: number; exists: number; errors: number; warnings: number }
  new: { row: number; full_name: string; login: string; homeroom: string | null; warning: string | null }[]
  problems: ImportRow[]
  credentials: Credential[] | null
}

export type Settings = Record<
  'max_lessons_per_shift' | 'homeroom_edit_days' | 'pending_reason_days' | 'morning_shift_end' | 'afternoon_shift_end',
  number
>

export interface StudentNames {
  last_name: string
  first_name: string
  patronymic: string | null
}

function fileForm(file: File, digest?: string): FormData {
  const form = new FormData()
  form.append('file', file)
  if (digest !== undefined) form.append('digest', digest)
  return form
}

export interface Backup {
  name: string
  size: number
  created_at: string
  version: string
  tables: number
  rows: number
}

export interface BackupList {
  last_at: string | null
  stale: boolean
  backups: Backup[]
}

export interface DutyShift {
  shift: Shift
  ends_at: string
  ended: boolean
  classes_total: number
  classes_done: number
  code: null | { id: number; code: string | null; issued_at: string; issued_by: string; devices: number }
}

export interface DutyDay {
  date: string
  is_today: boolean
  shifts: DutyShift[]
}

export interface ConflictSide {
  device_number: number | null
  absent: { id: number; name: string }[]
}

export interface RoundConflict {
  id: number
  class: { id: number; name: string }
  date: string
  student_count: number
  current: ConflictSide & { saved_at: string }
  incoming: ConflictSide & { marked_at: string | null; received_at: string }
}

/** Жиынтықта аты аталатын оқушы: күні бойы жоқ, кешіккен не кетіп қалған. */
export interface SummaryPerson {
  id: number
  name: string
  round_status: 'present' | 'absent'
  full_absence: boolean
  arrived_lesson: number | null
  left_lesson: number | null
  absence_reason: 'sick' | 'excused' | 'unexcused' | null
  leave_permission: 'permitted' | 'unpermitted' | null
  note: string | null
  pending: 'reason' | 'permission' | null
}

export interface SummaryClass {
  id: number
  name: string
  homeroom: string | null
  students: number
  surveyed: boolean
  saved_at: string | null
  received_late: boolean
  unmarked: number
  absent: number
  late: number
  left: number
  pending: number
  people: SummaryPerson[]
}

export interface SummaryShift {
  shift: Shift
  ended: boolean
  classes_total: number
  classes_surveyed: number
  students: number
  unmarked: number
  absent: number
  absent_by_reason: { sick: number; excused: number; unexcused: number; pending: number }
  late: number
  left: number
  left_by_permission: { permitted: number; unpermitted: number; pending: number }
  classes: SummaryClass[]
}

export interface DailySummary {
  date: string
  is_today: boolean
  is_school_day: boolean
  previous_school_day: string | null
  next_school_day: string | null
  open_conflicts: number
  shifts: SummaryShift[]
}

/** Апталық есептің санауыштары. `marked`, `absent_marked` тек кезекші аралаған күндер бойынша. */
export interface ReportCounters {
  marked: number
  absent_marked: number
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
}

export interface ReportClass extends ReportCounters {
  id: number
  name: string
  shift: Shift
  homeroom: string | null
  students: number
  days_expected: number
  days_surveyed: number
  missing_dates: string[]
}

export interface ReportDay extends ReportCounters {
  date: string
  class_days_expected: number
  class_days_surveyed: number
}

export interface ReportStudent {
  id: number
  name: string
  class_id: number
  class: string
  unexcused: number
  pending: number
  dates: string[]
}

export interface ReportEvent {
  date: string
  class_id: number
  class: string
  student_id: number
  name: string
  arrived_lesson: number | null
  left_lesson: number | null
  leave_permission: 'permitted' | 'unpermitted' | null
}

export interface WeeklyReport {
  from: string
  to: string
  school_days: string[]
  complete: boolean
  totals: ReportCounters & { classes: number; students: number; class_days_expected: number; class_days_surveyed: number }
  days: ReportDay[]
  classes: ReportClass[]
  students: ReportStudent[]
  students_total: number
  events: ReportEvent[]
}

export interface WeeklyReportResponse {
  week: { from: string; to: string; previous: string; next: string | null; is_current: boolean }
  version: 'live' | 'snapshot'
  snapshot: { generated_at: string; changed_since: number } | null
  report: WeeklyReport
}

function weekQuery(week: string | null, snapshot: boolean, extra = ''): string {
  const parts = [week === null ? '' : `week=${week}`, snapshot ? 'version=snapshot' : '', extra].filter((part) => part !== '')
  return parts.length === 0 ? '' : `?${parts.join('&')}`
}

export const adminApi = {
  weeklyReport: (week: string | null, snapshot: boolean) =>
    request<WeeklyReportResponse>('GET', `/admin/reports/weekly${weekQuery(week, snapshot)}`),
  /** Excel файлының мекенжайы; `names: false` аты-жөнсіз жиынтық. */
  weeklyReportExportUrl: (week: string, snapshot: boolean, names: boolean) =>
    `/api/admin/reports/weekly/export${weekQuery(week, snapshot, names ? '' : 'names=0')}`,

  dailySummary: (date: string | null) =>
    request<DailySummary>('GET', date === null ? '/admin/daily-summary' : `/admin/daily-summary?date=${date}`),

  roundConflicts: () => request<{ conflicts: RoundConflict[] }>('GET', '/admin/round-conflicts').then((r) => r.conflicts),
  resolveConflict: (id: number, resolution: 'keep' | 'replace') =>
    request<{ conflicts: RoundConflict[] }>('POST', `/admin/round-conflicts/${id}/resolve`, { resolution }).then((r) => r.conflicts),
  dutyCodes: () => request<{ days: DutyDay[]; login_blocked: boolean }>('GET', '/admin/duty-codes'),
  issueDutyCode: (date: string, shift: Shift) => request<{ code: string; shift: DutyShift }>('POST', '/admin/duty-codes', { date, shift }),
  revokeDutyCode: (id: number) => request<{ shift: DutyShift }>('DELETE', `/admin/duty-codes/${id}`),

  backups: () => request<BackupList>('GET', '/admin/backups'),
  createBackup: () => request<{ backup: Backup }>('POST', '/admin/backups'),
  backupUrl: (name: string) => `/api/admin/backups/${encodeURIComponent(name)}/download`,

  years: () => request<{ years: Year[] }>('GET', '/admin/academic-years').then((r) => r.years),
  createYear: (data: Pick<Year, 'name' | 'start_date' | 'end_date'>) =>
    request<{ year: Year }>('POST', '/admin/academic-years', data).then((r) => r.year),
  calendar: (yearId: number) =>
    request<{ year: Year; days: Day[]; school_day_count: number }>('GET', `/admin/academic-years/${yearId}/calendar`),
  setCalendarRange: (yearId: number, data: { from: string; to: string; is_school_day: boolean; note: string | null }) =>
    request<{ changed: number }>('PUT', `/admin/academic-years/${yearId}/calendar`, data),

  classes: () => request<{ year: Year | null; classes: SchoolClass[] }>('GET', '/admin/classes'),
  createClasses: (data: { grade: number; letters: string[]; shift: Shift }) =>
    request<{ created: string[]; skipped: string[] }>('POST', '/admin/classes/bulk', data),
  updateClass: (id: number, data: Partial<{ name: string; shift: Shift; homeroom_user_id: number | null; is_active: boolean }>) =>
    request<{ class: SchoolClass }>('PATCH', `/admin/classes/${id}`, data).then((r) => r.class),

  students: (classId: number) =>
    request<{ class: SchoolClass; date: string; students: Student[] }>('GET', `/admin/classes/${classId}/students`),
  addStudent: (classId: number, names: StudentNames) =>
    request<{ student: Student }>('POST', `/admin/classes/${classId}/students`, names).then((r) => r.student),
  updateStudent: (id: number, names: StudentNames) =>
    request<{ student: Student }>('PATCH', `/admin/students/${id}`, names).then((r) => r.student),
  transferStudent: (id: number, classId: number) =>
    request<{ student: Student }>('POST', `/admin/students/${id}/transfer`, { class_id: classId }).then((r) => r.student),
  withdrawStudent: (id: number) => request<{ student: Student }>('POST', `/admin/students/${id}/withdraw`, {}),

  importStudents: (file: File, digest?: string) =>
    upload<StudentImportResult>('/admin/import/students', fileForm(file, digest)),
  importTeachers: (file: File, digest?: string) =>
    upload<TeacherImportResult>('/admin/import/teachers', fileForm(file, digest)),

  accounts: () => request<{ users: Account[] }>('GET', '/admin/users').then((r) => r.users),
  createAccount: (data: { last_name: string; first_name: string; patronymic: string | null; role: Role }) =>
    request<{ user: Account; one_time_password: string }>('POST', '/admin/users', data),
  updateAccount: (id: number, data: Partial<{ full_name: string; is_active: boolean }>) =>
    request<{ user: Account }>('PATCH', `/admin/users/${id}`, data).then((r) => r.user),
  resetPassword: (id: number) =>
    request<{ user: Account; one_time_password: string }>('POST', `/admin/users/${id}/reset-password`),

  settings: () => request<{ settings: Settings }>('GET', '/admin/settings').then((r) => r.settings),
  updateSettings: (data: Partial<Settings>) =>
    request<{ settings: Settings }>('PATCH', '/admin/settings', data).then((r) => r.settings),
}
