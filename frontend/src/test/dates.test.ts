import { describe, expect, it } from 'vitest'
import { dayMonth, monthYear, weekdayIndex, weekdayNames } from '../lib/dates'

describe('күн атаулары браузердің тіл қорына тәуелсіз', () => {
  it('күн мен ай үш тілде', () => {
    expect(dayMonth('2026-11-04', 'kk')).toBe('4 қараша')
    expect(dayMonth('2026-11-04', 'ru')).toBe('4 ноября')
    expect(dayMonth('2026-11-04', 'zh')).toBe('11月4日')
  })

  it('ай мен жыл үш тілде', () => {
    expect(monthYear('2026-09', 'kk')).toBe('Қыркүйек 2026')
    expect(monthYear('2027-05', 'ru')).toBe('Май 2027')
    expect(monthYear('2027-01', 'zh')).toBe('2027年1月')
  })

  it('апта дүйсенбіден басталады', () => {
    expect(weekdayIndex('2026-11-02')).toBe(0)
    expect(weekdayIndex('2026-11-01')).toBe(6)
    for (const language of ['kk', 'ru', 'zh']) expect(weekdayNames(language)).toHaveLength(7)
    expect(weekdayNames('kk')[0]).toBe('Дс')
  })
})
