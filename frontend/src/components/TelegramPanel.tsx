import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { telegramApi } from '../api/telegram'
import type { TelegramLink } from '../api/telegram'
import { dayMonthTime } from '../lib/dates'
import { useErrorText } from '../lib/errors'
import { useLoad } from '../lib/useLoad'

/**
 * Өз аккаунтын Telegram ботпен байланыстыру (мұғалімге де, завучқа да). Бот қосылмаған болса бөлік көрсетілмейді.
 * Сілтеме 15 минутқа жарамды; Telegram-да «Start» басылғанын бет өзі байқайды (docs/кезең-10-жоспар.md, 5 және 6-шешім).
 */
export function TelegramPanel() {
  const { t, i18n } = useTranslation()
  const errorText = useErrorText()
  const { data, error, reload } = useLoad(telegramApi.state)
  const [link, setLink] = useState<TelegramLink | null>(null)
  const [busy, setBusy] = useState(false)
  const [failure, setFailure] = useState<unknown>(null)
  const waiting = link !== null && data?.linked !== true

  // Сілтеме берілген соң пайдаланушы Telegram-ға өтеді: қайтып келгенде күй жаңарып тұруы үшін тексеріп тұрамыз.
  useEffect(() => {
    if (!waiting) return undefined
    const timer = window.setInterval(() => void reload(), 4000)
    return () => window.clearInterval(timer)
  }, [waiting, reload])

  if (data === undefined || !data.ready) return null

  const run = async (action: () => Promise<void>) => {
    setBusy(true)
    setFailure(null)
    try {
      await action()
    } catch (e) {
      setFailure(e)
    } finally {
      setBusy(false)
    }
  }
  const connect = () => run(async () => setLink(await telegramApi.createLink()))
  const disconnect = () => run(async () => {
    await telegramApi.unlink()
    setLink(null)
    await reload()
  })

  return (
    <section className="panel form" aria-label={t('telegram.title')}>
      <h2 className="panel__title">{t('telegram.title')}</h2>
      {(failure !== null || error !== null) && <p className="alert" role="alert">{errorText(failure ?? error)}</p>}

      {data.linked ? (
        <>
          <p role="status">
            <b>{t('telegram.linked')}</b>
            {data.linked_at !== null && <span className="muted"> · {dayMonthTime(data.linked_at, i18n.language)}</span>}
          </p>
          <p className="muted">{t('telegram.linkedHint')}</p>
          <div className="row">
            <button className="btn" type="button" disabled={busy} onClick={() => void disconnect()}>{t('telegram.unlink')}</button>
          </div>
        </>
      ) : link === null ? (
        <>
          <p className="muted">{t('telegram.intro')}</p>
          <div className="row">
            <button className="btn btn--strong" type="button" disabled={busy} onClick={() => void connect()}>{t('telegram.link')}</button>
          </div>
        </>
      ) : (
        <>
          <p>{t('telegram.step1')}</p>
          <div className="row">
            <a className="btn btn--strong" href={link.url} target="_blank" rel="noreferrer">{t('telegram.open', { bot: data.bot_username })}</a>
          </div>
          <p className="muted" role="status">{t('telegram.waiting')}</p>
          <p className="muted">{t('telegram.privacy')}</p>
        </>
      )}
    </section>
  )
}
