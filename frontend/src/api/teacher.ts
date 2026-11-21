import { request } from './client'
import type { SchoolClass, Student } from './admin'

// Келісім: docs/api.md, «Мұғалім API».

export interface HomeroomClass {
  class: SchoolClass
  students: Student[]
}

export const teacherApi = {
  homeroom: () => request<{ date: string; classes: HomeroomClass[] }>('GET', '/teacher/homeroom'),
}
