import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { adminApi } from '../../api/admin'
import type { Credential, TeacherImportResult } from '../../api/admin'
import { useCodeText, useErrorText } from '../../lib/errors'

/** Екі қадам: алдын ала қарау (ештеңе сақталмайды) және растау. Парольдер бір-ақ рет көрсетіледі. */
export function ImportTeachersPage() {
  const { t } = useTranslation()
  const errorText = useErrorText()
  const codeText = useCodeText()
  const [file, setFile] = useState<File | null>(null)
  const [preview, setPreview] = useState<TeacherImportResult | null>(null)
  const [credentials, setCredentials] = useState<Credential[] | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  function reset() {
    setFile(null)
    setPreview(null)
    setCredentials(null)
    setError(null)
  }

  async function send(digest?: string) {
    if (file === null) return
    setBusy(true)
    setError(null)
    try {
      const result = await adminApi.importTeachers(file, digest)
      setPreview(result)
      if (digest !== undefined) setCredentials(result.credentials ?? [])
    } catch (caught) {
      setError(errorText(caught))
    } finally {
      setBusy(false)
    }
  }

  function onSubmit(event: FormEvent) {
    event.preventDefault()
    void send()
  }

  if (credentials !== null) return <CredentialSheet credentials={credentials} onClose={reset} />

  return (
    <section className="page">
      <header className="page__head">
        <div>
          <Link className="back" to="/admin/users">← {t('nav.users')}</Link>
          <h1 className="page__title">{t('importT.title')}</h1>
        </div>
      </header>
      <p className="muted">{t('importT.intro')}</p>

      {preview === null && (
        <form className="panel form" onSubmit={onSubmit}>
          <a className="back" href="/templates/mugalimder-ulgi.xlsx" download>{t('importT.template')}</a>
          <label className="field">
            <span className="field__label">{t('import.file')}</span>
            <input className="field__input field__input--file" type="file" accept=".xlsx"
              onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
          </label>
          {error !== null && <p className="alert" role="alert">{error}</p>}
          <button className="btn btn--strong" type="submit" disabled={busy || file === null}>
            {busy ? t('import.checking') : t('import.preview')}
          </button>
        </form>
      )}

      {preview !== null && (
        <>
          <div className="stats" role="status">
            <div className="stat"><b>{preview.summary.new}</b><span>{t('import.new')}</span></div>
            <div className="stat"><b>{preview.summary.exists}</b><span>{t('import.exists')}</span></div>
            <div className={preview.summary.warnings > 0 ? 'stat stat--warn' : 'stat'}><b>{preview.summary.warnings}</b><span>{t('importT.warnings')}</span></div>
            <div className={preview.summary.errors > 0 ? 'stat stat--warn' : 'stat'}><b>{preview.summary.errors}</b><span>{t('import.errors')}</span></div>
          </div>

          <div className="panel form">
            {preview.summary.new === 0 ? (
              <p className="muted">{t('importT.nothingNew')}</p>
            ) : (
              <button className="btn btn--strong" type="button" disabled={busy} onClick={() => void send(preview.digest)}>
                {busy ? t('common.saving') : t('importT.confirm', { count: preview.summary.new })}
              </button>
            )}
            {error !== null && <p className="alert" role="alert">{error}</p>}
            <button className="btn" type="button" onClick={reset}>{t('import.another')}</button>
          </div>

          {preview.problems.length > 0 && (
            <section aria-label={t('import.problemsTitle')}>
              <h2 className="group__title">{t('import.problemsTitle')}</h2>
              <div className="tablewrap">
                <table className="table">
                  <thead>
                    <tr>
                      <th scope="col" className="num">{t('import.row')}</th>
                      <th scope="col">{t('importT.teacher')}</th>
                      <th scope="col">{t('import.reason')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {preview.problems.map((row) => (
                      <tr key={row.row}>
                        <td className="num">{row.row}</td>
                        <td>{row.full_name ?? ([row.last_name, row.first_name, row.patronymic].filter(Boolean).join(' ') || '—')}</td>
                        <td className="warn">{codeText(row.code)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </section>
          )}

          {preview.new.length > 0 && (
            <section aria-label={t('importT.newTitle')}>
              <h2 className="group__title">{t('importT.newTitle')}</h2>
              <div className="tablewrap">
                <table className="table">
                  <thead>
                    <tr>
                      <th scope="col" className="num">{t('import.row')}</th>
                      <th scope="col">{t('importT.teacher')}</th>
                      <th scope="col">{t('users.login')}</th>
                      <th scope="col">{t('importT.homeroom')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {preview.new.map((row) => (
                      <tr key={row.row}>
                        <td className="num">{row.row}</td>
                        <td>{row.full_name}</td>
                        <td>{row.login}</td>
                        <td>
                          {row.homeroom ?? '—'}
                          {row.warning !== null && <span className="warn"> · {codeText(row.warning)}</span>}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </section>
          )}
        </>
      )}
    </section>
  )
}

/**
 * Парольдер тізімі мәзірді жауып тұрады: кездейсоқ басқа бетке өтіп кетсе,
 * 130 парольді қайта шығаруға тура келер еді.
 */
function CredentialSheet({ credentials, onClose }: { credentials: Credential[]; onClose: () => void }) {
  const { t } = useTranslation()

  useEffect(() => {
    const warn = (event: BeforeUnloadEvent) => event.preventDefault()
    window.addEventListener('beforeunload', warn)
    return () => window.removeEventListener('beforeunload', warn)
  }, [])

  return (
    <section className="sheet" aria-label={t('importT.credentialsTitle')}>
      <div className="sheet__head">
        <h1 className="page__title">{t('importT.credentialsTitle')}</h1>
        <p className="note" role="status">{t('importT.credentialsDone', { count: credentials.length })}</p>
        <p className="alert">{t('importT.credentialsNote')}</p>
        <div className="row">
          <button className="btn btn--strong" type="button" onClick={() => window.print()}>{t('importT.print')}</button>
          <button className="btn" type="button" onClick={() => { if (window.confirm(t('importT.closeConfirm'))) onClose() }}>
            {t('importT.closeList')}
          </button>
        </div>
      </div>
      <table className="table sheet__table">
        <thead>
          <tr>
            <th scope="col">{t('importT.teacher')}</th>
            <th scope="col">{t('importT.homeroom')}</th>
            <th scope="col">{t('users.login')}</th>
            <th scope="col">{t('users.password')}</th>
          </tr>
        </thead>
        <tbody>
          {credentials.map((row) => (
            <tr key={row.login}>
              <td>{row.full_name}</td>
              <td>{row.homeroom ?? '—'}</td>
              <td className="mono">{row.login}</td>
              <td className="mono">{row.password}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  )
}
