import { describe, expect, it } from 'vitest'
import kk from '../i18n/locales/kk.json'
import ru from '../i18n/locales/ru.json'
import zh from '../i18n/locales/zh.json'

type Tree = { [key: string]: string | Tree }

function flatten(tree: Tree, prefix = ''): Map<string, string> {
  const out = new Map<string, string>()
  for (const [key, value] of Object.entries(tree)) {
    const path = prefix === '' ? key : `${prefix}.${key}`
    if (typeof value === 'string') out.set(path, value)
    else for (const [k, v] of flatten(value, path)) out.set(k, v)
  }
  return out
}

function placeholders(text: string): string[] {
  return [...text.matchAll(/\{\{\s*(\w+)\s*\}\}/g)].map((m) => m[1]).sort()
}

// Көпше түр жалғаулары тілге қарай әртүрлі (қазақша: one/other, орысша: one/few/many/other,
// қытайша: other), сондықтан кілттер салыстырылғанда жалғау алынады.
function pluralBase(key: string): string {
  return key.replace(/_(zero|one|two|few|many|other)$/, '')
}

function baseKeys(entries: Map<string, string>): string[] {
  return [...new Set([...entries.keys()].map(pluralBase))].sort()
}

const locales = { kk: flatten(kk), ru: flatten(ru), zh: flatten(zh) }

// Қызыл сызық: бір тілде ғана мәтін қосуға болмайды. Бұл тест соны бақылайды.
describe('аударма файлдары', () => {
  it('үш тілде кілттер бірдей', () => {
    const reference = baseKeys(locales.kk)
    expect(baseKeys(locales.ru)).toEqual(reference)
    expect(baseKeys(locales.zh)).toEqual(reference)
  })

  it('бос мәтін жоқ', () => {
    for (const [name, entries] of Object.entries(locales)) {
      for (const [key, value] of entries) {
        expect(value.trim(), `${name}:${key}`).not.toBe('')
      }
    }
  })

  it('айнымалылар үш тілде бірдей', () => {
    const expected = new Map<string, string[]>()
    for (const [key, value] of locales.kk) expected.set(pluralBase(key), placeholders(value))
    for (const [name, entries] of [['ru', locales.ru], ['zh', locales.zh]] as const) {
      for (const [key, value] of entries) {
        expect(placeholders(value), `${name}:${key}`).toEqual(expected.get(pluralBase(key)))
      }
    }
  })
})
