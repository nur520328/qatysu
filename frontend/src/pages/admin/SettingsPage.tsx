import { useCallback, useState } from 'react'
import type { FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { adminApi } from '../../api/admin'
import type { Settings } from '../../api/admin'
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

function SettingsForm({ initial }: { initial: Settings }) {
  const { t } = useTranslation()
  const errorText = useErrorText()
  const [values, setValues] = useState<Record<keyof Settings, string>>({
    max_lessons_per_shift: String(initial.max_lessons_per_shift),
    homeroom_edit_days: String(initial.homeroom_edit_days),
    pending_reason_days: String(initial.pending_reason_days),
  })
  const [busy, setBusy] = useState(false)
  const [saved, setSaved] = useState(false)
  const [error, setError] = useState<string | null>(null)

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
        {FIELDS.map(({ key, label, min, max }) => (
          <label key={key} className="field">
            <span className="field__label">{t(`settings.${label}`)}</span>
            <input className="field__input field__input--num" type="number" inputMode="numeric" required
              min={min} max={max} step={1} value={values[key]}
              onChange={(e) => { setSaved(false); setValues({ ...values, [key]: e.target.value }) }} />
            <span className="field__hint">{t(`settings.${label}Hint`)} {t('settings.range', { min, max })}</span>
          </label>
        ))}
        {error !== null && <p className="alert" role="alert">{error}</p>}
        {saved && <p className="note" role="status">{t('settings.saved')}</p>}
        <button className="btn btn--strong" type="submit" disabled={busy}>{busy ? t('common.saving') : t('common.save')}</button>
      </form>
      <BackupPanel />
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
