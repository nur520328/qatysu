import type { TFunction } from 'i18next'
import type { DayRow, TeacherRow } from '../api/staff'

/** Оқушының күнгі күйі қысқа мәтінмен: «жоқ», «2-сабақтан келді», «бар · 4-сабақтан кетіп қалды». */
export function shortStatus(row: Pick<TeacherRow, 'round_status' | 'arrived_lesson' | 'left_lesson'>, t: TFunction): string {
  const parts: string[] = []
  if (row.arrived_lesson !== null) parts.push(t('att.arrived', { n: row.arrived_lesson }))
  else if (row.round_status !== null) parts.push(t(row.round_status === 'present' ? 'att.present' : 'att.absent'))
  else if (row.left_lesson === null) parts.push(t('att.noMark'))
  if (row.left_lesson !== null) parts.push(t('att.left', { n: row.left_lesson }))
  return parts.join(' · ')
}

/** Жетекшіге: қысқа күйге себеп пен рұқсат қосылады. */
export function fullStatus(row: DayRow, t: TFunction): string {
  const parts = [shortStatus(row, t)]
  if (row.absence_reason !== null) parts.push(t(`att.reason_${row.absence_reason}`))
  else if (row.pending === 'reason') parts.push(t('att.reasonMissing'))
  if (row.leave_permission !== null) parts.push(t(`att.perm_${row.leave_permission}`))
  else if (row.pending === 'permission') parts.push(t('att.permMissing'))
  return parts.join(' · ')
}

interface Named {
  id: number
  last_name: string
  first_name: string
  patronymic: string | null
  class?: { id: number }
}

/**
 * Тізімде көрсетілетін аттар: «Тегі Аты». Сол сыныпта тегі мен аты бірдей екі оқушы болса,
 * әкесінің аты қосылады: әйтпесе кезекші де, мұғалім де оларды ажырата алмайды.
 */
export function displayNames(rows: Named[]): Map<number, string> {
  const key = (row: Named) => `${row.class?.id ?? 0}|${row.last_name} ${row.first_name}`.toLocaleLowerCase()
  const counts = new Map<string, number>()
  for (const row of rows) counts.set(key(row), (counts.get(key(row)) ?? 0) + 1)

  return new Map(rows.map((row) => {
    const name = `${row.last_name} ${row.first_name}`
    const twin = (counts.get(key(row)) ?? 0) > 1 && row.patronymic !== null
    return [row.id, twin ? `${name} ${row.patronymic}` : name]
  }))
}
