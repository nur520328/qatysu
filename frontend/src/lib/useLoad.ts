import { useCallback, useEffect, useRef, useState } from 'react'

interface LoadState<T> {
  data: T | undefined
  error: unknown
}

/**
 * Бет ашылғанда деректі жүктейді; `reload` өзгерістен кейін қайта жүктейді.
 * Кешігіп келген ескі жауап жаңасын баспайды (мысалы, бір сыныптан екіншісіне тез өткенде).
 * `fetcher` тұрақты болуы керек (useCallback).
 */
export function useLoad<T>(fetcher: () => Promise<T>): LoadState<T> & { reload: () => Promise<void> } {
  const [state, setState] = useState<LoadState<T>>({ data: undefined, error: null })
  const latest = useRef(0)

  const reload = useCallback(() => {
    const mine = ++latest.current
    return fetcher().then(
      (data) => {
        if (latest.current === mine) setState({ data, error: null })
      },
      (error: unknown) => {
        if (latest.current === mine) setState((previous) => ({ data: previous.data, error }))
      },
    )
  }, [fetcher])

  useEffect(() => {
    void reload()
    return () => {
      latest.current += 1
    }
  }, [reload])

  return { ...state, reload }
}
