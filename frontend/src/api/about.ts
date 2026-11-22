import { request, upload } from './client'

/** «Платформа туралы»: нұсқа және автор суретінің мекенжайы (сурет жүктелмеген болса null). */
export interface AboutState {
  version: string
  photo: string | null
}

export const aboutApi = {
  state: () => request<AboutState>('GET', '/about'),
  uploadPhoto: (file: File) => {
    const form = new FormData()
    form.append('photo', file)
    return upload<AboutState>('/admin/about/photo', form)
  },
  removePhoto: () => request<AboutState>('DELETE', '/admin/about/photo'),
}
