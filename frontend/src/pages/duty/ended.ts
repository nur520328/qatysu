import { ApiError } from '../../api/client'

/** Сеанстың аяқталғанын білдіретін қате ме (код жойылды, смена бітті, кірмеген). */
export function isDutyEnded(error: unknown): boolean {
  return error instanceof ApiError && (error.code === 'duty_code_ended' || error.code === 'duty_unauthenticated')
}
