import { vi } from 'vitest'

export type Handler = (init: RequestInit | undefined, body: unknown) => { status: number; body?: unknown }

export interface Call {
  key: string
  body: unknown
}

function readBody(init: RequestInit | undefined): unknown {
  if (typeof init?.body === 'string') return JSON.parse(init.body)
  if (init?.body instanceof FormData) return Object.fromEntries(init.body.entries())
  return undefined
}

/** fetch-ті жолы бойынша жауап беретін жалған сервермен алмастырады. */
export function mockServer(routes: Record<string, Handler>): Call[] {
  const calls: Call[] = []
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const key = `${init?.method ?? 'GET'} ${String(input)}`
      const body = readBody(init)
      calls.push({ key, body })
      if (key === 'GET /sanctum/csrf-cookie') {
        document.cookie = 'XSRF-TOKEN=test-token'
        return new Response(null, { status: 204 })
      }
      const handler = routes[key]
      if (handler === undefined) throw new Error(`unexpected request: ${key}`)
      const result = handler(init, body)
      return new Response(result.body === undefined ? null : JSON.stringify(result.body), { status: result.status })
    }),
  )
  return calls
}
