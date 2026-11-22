import { useState } from 'react'
import type { FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { adminApi } from '../../api/admin'
import type { StudentImportResult } from '../../api/admin'
import { useCodeText, useErrorText } from '../../lib/errors'

/** Екі қадам: алдын ала қарау (ештеңе сақталмайды) және растау. */
export function ImportStudentsPage() {
  const { t } = useTranslation()
  const errorText = useErrorText()
  const codeText = useCodeText()
  const [file, setFile] = useState<File | null>(null)
  const [preview, setPreview] = useState<StudentImportResult | null>(null)
  const [imported, setImported] = useState<number | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  function reset() {
    setFile(null)
    setPreview(null)
    setImported(null)
    setError(null)
  }

  async function send(digest?: string) {
    if (file === null) return
    setBusy(true)
    setError(null)
    try {
      const result = await adminApi.importStudents(file, digest)
      setPreview(result)
      if (digest !== undefined) setImported(result.imported ?? 0)
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

  return (
    <section className="page">
      <header className="page__head">
        <div>
          <Link className="back" to="/admin/classes">← {t('nav.classes')}</Link>
          <h1 className="page__title">{t('import.studentsTitle')}</h1>
        </div>
        <div className="row">
          <a className="back" href="/templates/oqushylar-ulgi.xlsx" download>{t('importT.template')}</a>
        </div>
      </header>
      <p className="muted">{t('import.studentsIntro')}</p>

      {preview === null && (
        <form className="panel form" onSubmit={onSubmit}>
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
            <div className={preview.summary.other_class > 0 ? 'stat stat--warn' : 'stat'}><b>{preview.summary.other_class}</b><span>{t('import.otherClass')}</span></div>
            <div className={preview.summary.errors > 0 ? 'stat stat--warn' : 'stat'}><b>{preview.summary.errors}</b><span>{t('import.errors')}</span></div>
          </div>

          <div className="panel form">
            {imported !== null ? (
              <p className="note" role="status">{t('import.done', { count: imported })}</p>
            ) : preview.summary.new === 0 ? (
              <p className="muted">{t('import.nothingNew')}</p>
            ) : (
              <button className="btn btn--strong" type="button" disabled={busy} onClick={() => void send(preview.digest)}>
                {busy ? t('common.saving') : t('import.confirm', { count: preview.summary.new })}
              </button>
            )}
            {error !== null && <p className="alert" role="alert">{error}</p>}
            <div className="row">
              <button className="btn" type="button" onClick={reset}>{t('import.another')}</button>
              {imported !== null && <Link className="btn" to="/admin/classes">{t('nav.classes')}</Link>}
            </div>
          </div>

          <div className="tablewrap">
            <table className="table">
              <thead>
                <tr>
                  <th scope="col">{t('import.sheet')}</th>
                  <th scope="col" className="num">{t('import.new')}</th>
                  <th scope="col" className="num">{t('import.exists')}</th>
                  <th scope="col" className="num">{t('import.otherClass')}</th>
                  <th scope="col" className="num">{t('import.errors')}</th>
                </tr>
              </thead>
              <tbody>
                {preview.sheets.map((sheet) => (
                  <tr key={sheet.name}>
                    <th scope="row">{sheet.name}</th>
                    {sheet.error !== null ? (
                      <td colSpan={4} className="warn">{codeText(sheet.error)}</td>
                    ) : (
                      <>
                        <td className="num">{sheet.new}</td>
                        <td className="num">{sheet.exists}</td>
                        <td className="num">{sheet.other_class}</td>
                        <td className={sheet.errors > 0 ? 'num warn' : 'num'}>{sheet.errors}</td>
                      </>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {preview.problems.length > 0 && (
            <section aria-label={t('import.problemsTitle')}>
              <h2 className="group__title">{t('import.problemsTitle')}</h2>
              <div className="tablewrap">
                <table className="table">
                  <thead>
                    <tr>
                      <th scope="col">{t('import.sheet')}</th>
                      <th scope="col" className="num">{t('import.row')}</th>
                      <th scope="col">{t('import.who')}</th>
                      <th scope="col">{t('import.reason')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {preview.problems.map((row) => (
                      <tr key={`${row.sheet}-${row.row}`}>
                        <td>{row.sheet}</td>
                        <td className="num">{row.row}</td>
                        <td>{[row.last_name, row.first_name, row.patronymic].filter(Boolean).join(' ') || '—'}{row.class ? ` · ${row.class}` : ''}</td>
                        <td className="warn">{codeText(row.code)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </section>
          )}

          {preview.other_class.length > 0 && (
            <section aria-label={t('import.otherTitle')}>
              <h2 className="group__title">{t('import.otherTitle')}</h2>
              <p className="muted">{t('import.otherIntro')}</p>
              <div className="tablewrap">
                <table className="table">
                  <thead>
                    <tr>
                      <th scope="col">{t('import.who')}</th>
                      <th scope="col">{t('import.currentClass')}</th>
                      <th scope="col">{t('import.fileClass')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {preview.other_class.map((row) => (
                      <tr key={`${row.sheet}-${row.row}`}>
                        <td>{[row.last_name, row.first_name, row.patronymic].filter(Boolean).join(' ')}</td>
                        <td>{row.current_class}</td>
                        <td>{row.class}</td>
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
