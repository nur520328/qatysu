import { useCallback, useState } from 'react'
import type { FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { adminApi } from '../../api/admin'
import type { Settings } from '../../api/admin'
import { aboutApi } from '../../api/about'
import type { AboutState } from '../../api/about'
import { ApiError } from '../../api/client'
import { telegramApi } from '../../api/telegram'
import type { TelegramAdminState } from '../../api/telegram'
import { TelegramPanel } from '../../components/TelegramPanel'
import { dayMonthTime } from '../../lib/dates'
import { useErrorText } from '../../lib/errors'
import { useLoad } from '../../lib/useLoad'

// Шектер сервердегі Setting::DEFAULTS-пен бірдей; сервер бәрібір өзі тексереді.
const FIELDS: { key: keyof Settings; label: string; min: number; max: number }[] = [
  { key: 'max_lessons_per_shift', label: 'maxLessons', min: 1, max: 12 },
  { key: 'homeroom_edit_days', label: 'editDays', min: 0, max: 90 },
  { key: 'pending_reason_days', label: 'pendingDays', min: 1, max: 30 },
]

export function SettingsPage() {
  const { t } = useTranslation()
  const errorText = useErrorText()
  const fetcher = useCallback(() => adminApi.settings(), [])
  const { data, error: loadError } = useLoad(fetcher)

  if (data === undefined) {
    return loadError === null
      ? <p className="muted">{t('common.loading')}</p>
      : <p className="alert" role="alert">{errorText(loadError)}</p>
  }
  return <SettingsForm initial={data} />
}

const TIMES: { key: 'morning_shift_end' | 'afternoon_shift_end'; label: string }[] = [
  { key: 'morning_shift_end', label: 'morningEnd' },
  { key: 'afternoon_shift_end', label: 'afternoonEnd' },
]

/** Серверде уақыт түн ортасынан бастап минутпен сақталады (790 = 13:10). */
function toClock(minutes: number): string {
  return `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`
}

function toMinutes(clock: string): number {
  const [h, m] = clock.split(':').map(Number)
  return h * 60 + m
}

function SettingsForm({ initial }: { initial: Settings }) {
  const { t } = useTranslation()
  const errorText = useErrorText()
  const [values, setValues] = useState<Record<keyof Settings, string>>({
    max_lessons_per_shift: String(initial.max_lessons_per_shift),
    homeroom_edit_days: String(initial.homeroom_edit_days),
    pending_reason_days: String(initial.pending_reason_days),
    morning_shift_end: toClock(initial.morning_shift_end),
    afternoon_shift_end: toClock(initial.afternoon_shift_end),
  })
  const [busy, setBusy] = useState(false)
  const [saved, setSaved] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [botVersion, setBotVersion] = useState(0)

  function change(key: keyof Settings, value: string) {
    setSaved(false)
    setValues({ ...values, [key]: value })
  }

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    setBusy(true)
    setSaved(false)
    setError(null)
    try {
      await adminApi.updateSettings({
        max_lessons_per_shift: Number(values.max_lessons_per_shift),
        homeroom_edit_days: Number(values.homeroom_edit_days),
        pending_reason_days: Number(values.pending_reason_days),
        morning_shift_end: toMinutes(values.morning_shift_end),
        afternoon_shift_end: toMinutes(values.afternoon_shift_end),
      })
      setSaved(true)
    } catch (caught) {
      setError(errorText(caught))
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className="page">
      <h1 className="page__title">{t('settings.title')}</h1>
      <form className="panel form" onSubmit={onSubmit}>
        <div className="row">
          {TIMES.map(({ key, label }) => (
            <label key={key} className="field">
              <span className="field__label">{t(`settingsTime.${label}`)}</span>
              <input className="field__input field__input--num" type="time" required value={values[key]}
                onChange={(e) => change(key, e.target.value)} />
            </label>
          ))}
        </div>
        <p className="field__hint">{t('settingsTime.hint')}</p>
        {FIELDS.map(({ key, label, min, max }) => (
          <label key={key} className="field">
            <span className="field__label">{t(`settings.${label}`)}</span>
            <input className="field__input field__input--num" type="number" inputMode="numeric" required
              min={min} max={max} step={1} value={values[key]}
              onChange={(e) => change(key, e.target.value)} />
            <span className="field__hint">{t(`settings.${label}Hint`)} {t('settings.range', { min, max })}</span>
          </label>
        ))}
        {error !== null && <p className="alert" role="alert">{error}</p>}
        {saved && <p className="note" role="status">{t('settings.saved')}</p>}
        <button className="btn btn--strong" type="submit" disabled={busy}>{busy ? t('common.saving') : t('common.save')}</button>
      </form>
      <BackupPanel />
      <AuthorPhotoPanel />
      {/* Бот қосылған сәтте жеке «Байланыстыру» бөлігі бетті жаңартпай-ақ пайда болуы үшін. */}
      <TelegramAdminPanel onSetup={() => setBotVersion((v) => v + 1)} />
      <TelegramPanel key={botVersion} />
    </section>
  )
}

function BackupPanel() {
  const { t, i18n } = useTranslation()
  const errorText = useErrorText()
  const fetcher = useCallback(() => adminApi.backups(), [])
  const { data, error: loadError, reload } = useLoad(fetcher)
  const [busy, setBusy] = useState(false)
  const [created, setCreated] = useState(false)
  const [actionError, setError] = useState<string | null>(null)
  const error = actionError ?? (loadError === null ? null : errorText(loadError))
  const when = (iso: string) => dayMonthTime(iso, i18n.language)

  async function create() {
    setBusy(true)
    setCreated(false)
    setError(null)
    try {
      await adminApi.createBackup()
      await reload()
      setCreated(true)
    } catch (caught) {
      setError(errorText(caught))
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className="panel form" aria-label={t('backup.title')}>
      <h2 className="panel__title">{t('backup.title')}</h2>
      <p className="muted">{t('backup.lead')}</p>
      {data !== undefined && (
        <>
          {data.stale && <p className="alert" role="alert">{t('backup.stale')}</p>}
          <p className="panel__lead">{data.last_at === null ? t('backup.none') : t('backup.last', { when: when(data.last_at) })}</p>
        </>
      )}
      {error !== null && <p className="alert" role="alert">{error}</p>}
      {created && <p className="note" role="status">{t('backup.created')}</p>}
      <button className="btn btn--strong" type="button" disabled={busy} onClick={() => void create()}>
        {busy ? t('backup.creating') : t('backup.create')}
      </button>
      {data !== undefined && data.backups.length > 0 && (
        <>
          <p className="muted">{t('backup.downloadNote')}</p>
          <div className="tablewrap">
            <table className="table">
              <thead>
                <tr>
                  <th scope="col">{t('backup.when')}</th>
                  <th scope="col" className="num">{t('backup.rows')}</th>
                  <th scope="col" className="num">{t('backup.size')}</th>
                  <th scope="col">{t('backup.download')}</th>
                </tr>
              </thead>
              <tbody>
                {data.backups.map((backup) => (
                  <tr key={backup.name}>
                    <th scope="row">{when(backup.created_at)}</th>
                    <td className="num">{backup.rows}</td>
                    <td className="num">{t('backup.sizeKb', { kb: Math.max(1, Math.round(backup.size / 1024)) })}</td>
                    <td>
                      <a className="back" href={adminApi.backupUrl(backup.name)} download
                        aria-label={t('backup.downloadName', { when: when(backup.created_at) })}>
                        {t('backup.download')}
                      </a>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
      <p className="muted">{t('backup.restoreNote')}</p>
    </section>
  )
}

/** Ботты қосу және оның күйі. Токен сайтта енгізілмейді: ол тек сервердегі .env файлында (docs/кезең-10-жоспар.md, 2 және 3-шешім). */
function TelegramAdminPanel({ onSetup }: { onSetup: () => void }) {
  const { t, i18n } = useTranslation()
  const errorText = useErrorText()
  const { data: loaded, error } = useLoad(telegramApi.adminState)
  const [updated, setUpdated] = useState<TelegramAdminState | null>(null)
  const [busy, setBusy] = useState(false)
  const [failure, setFailure] = useState<unknown>(null)
  const [done, setDone] = useState(false)
  const data = updated ?? loaded

  if (data === undefined) {
    return error === null ? null : <p className="alert" role="alert">{errorText(error)}</p>
  }

  const setup = async () => {
    setBusy(true)
    setFailure(null)
    setDone(false)
    try {
      setUpdated(await telegramApi.adminSetup())
      setDone(true)
      onSetup()
    } catch (e) {
      setFailure(e)
    } finally {
      setBusy(false)
    }
  }
  const detail = failure instanceof ApiError ? failure.detail : null

  return (
    <section className="panel form" aria-label={t('telegram.adminTitle')}>
      <h2 className="panel__title">{t('telegram.adminTitle')}</h2>
      <p className="muted">{t('telegram.adminIntro')}</p>
      {failure !== null && (
        <p className="alert" role="alert">
          {errorText(failure)}{detail !== null && <> {t('telegram.rejected', { detail })}</>}
        </p>
      )}
      {done && <p className="note" role="status">{t('telegram.setupDone')}</p>}

      {!data.token_set ? (
        <p className="alert">{t('telegram.tokenMissing')}</p>
      ) : (
        <>
          {data.username === null ? (
            <p>{t('telegram.notSetUp')}</p>
          ) : (
            <ul className="summary__list">
              <li>{t('telegram.botName', { bot: data.username })}</li>
              {data.webhook_at !== null && <li>{t('telegram.webhookAt', { when: dayMonthTime(data.webhook_at, i18n.language) })}</li>}
              <li>{t('telegram.linkedUsers', { linked: data.linked_users, total: data.active_users })}</li>
              <li>{t('telegram.sent', { sent: data.sent_24h, failed: data.failed_24h })}</li>
            </ul>
          )}
          <div className="row">
            <button className={data.username === null ? 'btn btn--strong' : 'btn'} type="button" disabled={busy} onClick={() => void setup()}>
              {t(data.username === null ? 'telegram.setup' : 'telegram.setupAgain')}
            </button>
          </div>
        </>
      )}
    </section>
  )
}

/** «Платформа туралы» бетіндегі автордың суретін жүктеу не алып тастау. Сурет серверде кішірейтіліп, ішкі дерегінен тазартылады. */
function AuthorPhotoPanel() {
  const { t } = useTranslation()
  const errorText = useErrorText()
  const { data: loaded, error } = useLoad(aboutApi.state)
  const [updated, setUpdated] = useState<AboutState | null>(null)
  const [file, setFile] = useState<File | null>(null)
  const [busy, setBusy] = useState(false)
  const [failure, setFailure] = useState<unknown>(null)
  const [notice, setNotice] = useState<'photoSaved' | 'photoRemoved' | null>(null)
  // Жүктелгеннен кейін файл өрісі тазаруы үшін оны қайта жасаймыз.
  const [inputKey, setInputKey] = useState(0)
  const data = updated ?? loaded

  const run = async (action: () => Promise<AboutState>, done: 'photoSaved' | 'photoRemoved') => {
    setBusy(true)
    setFailure(null)
    setNotice(null)
    try {
      setUpdated(await action())
      setNotice(done)
      setFile(null)
      setInputKey((key) => key + 1)
    } catch (e) {
      setFailure(e)
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className="panel form" aria-label={t('about.photoPanel')}>
      <h2 className="panel__title">{t('about.photoPanel')}</h2>
      <p className="muted">{t('about.photoIntro')} <Link to="/about">{t('about.openPage')}</Link></p>
      {(failure !== null || error !== null) && <p className="alert" role="alert">{errorText(failure ?? error)}</p>}
      {notice !== null && <p className="note" role="status">{t(`about.${notice}`)}</p>}

      {data !== undefined && (data.photo === null
        ? <p className="muted">{t('about.photoNone')}</p>
        : <img className="about__photo" src={data.photo} alt={t('about.photoCurrent')} width={160} height={160} />)}

      <label className="field">
        <span className="field__label">{t('about.photoChoose')}</span>
        <input key={inputKey} className="field__input" type="file" accept="image/jpeg,image/png,image/webp" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
      </label>
      <div className="row">
        <button className="btn btn--strong" type="button" disabled={busy || file === null} onClick={() => file !== null && void run(() => aboutApi.uploadPhoto(file), 'photoSaved')}>
          {t('about.photoUpload')}
        </button>
        {data?.photo != null && (
          <button className="btn" type="button" disabled={busy} onClick={() => void run(aboutApi.removePhoto, 'photoRemoved')}>{t('about.photoRemove')}</button>
        )}
      </div>
    </section>
  )
}
