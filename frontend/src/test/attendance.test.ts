import { describe, expect, it } from 'vitest'
import { displayNames } from '../lib/attendance'

const student = (id: number, last: string, first: string, patronymic: string | null, classId?: number) => ({
  id, last_name: last, first_name: first, patronymic, ...(classId === undefined ? {} : { class: { id: classId } }),
})

describe('тізімдегі аттар', () => {
  it('аттас оқушыларға ғана әкесінің аты қосылады', () => {
    const names = displayNames([
      student(1, 'Жобабай', 'Ерлан', 'Алмасұлы'),
      student(2, 'Жобабай', 'Ерлан', 'Мақсатұлы'),
      student(3, 'Кестеев', 'Тимур', 'Берікұлы'),
    ])
    expect([...names.values()]).toEqual(['Жобабай Ерлан Алмасұлы', 'Жобабай Ерлан Мақсатұлы', 'Кестеев Тимур'])
  })

  it('әртүрлі сыныптағы аттастар (іздеу нәтижесі) араласпайды: оларды сыныбы ажыратады', () => {
    const names = displayNames([student(1, 'Сынақов', 'Оқушы', 'Аұлы', 5), student(2, 'Сынақов', 'Оқушы', 'Бұлы', 9)])
    expect([...names.values()]).toEqual(['Сынақов Оқушы', 'Сынақов Оқушы'])
  })

  it('әкесінің аты жазылмаған болса, ештеңе қосылмайды', () => {
    const names = displayNames([student(1, 'Сынақов', 'Оқушы', null), student(2, 'Сынақов', 'Оқушы', 'Бұлы')])
    expect([...names.values()]).toEqual(['Сынақов Оқушы', 'Сынақов Оқушы Бұлы'])
  })
})
