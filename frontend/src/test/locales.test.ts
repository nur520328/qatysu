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

const locales = { kk: flatten(kk), ru: flatten(ru), zh: flatten(zh) }

// Қызыл сызық: бір тілде ғана мәтін қосуға болмайды. Бұл тест соны бақылайды.
describe('аударма файлдары', () => {
  it('үш тілде кілттер бірдей', () => {
    const reference = [...locales.kk.keys()].sort()
    expect([...locales.ru.keys()].sort()).toEqual(reference)
    expect([...locales.zh.keys()].sort()).toEqual(reference)
  })

  it('бос мәтін жоқ', () => {
    for (const [name, entries] of Object.entries(locales)) {
      for (const [key, value] of entries) {
        expect(value.trim(), `${name}:${key}`).not.toBe('')
      }
    }
  })

  it('айнымалылар үш тілде бірдей', () => {
    for (const [key, value] of locales.kk) {
      const expected = placeholders(value)
      expect(placeholders(locales.ru.get(key) ?? ''), `ru:${key}`).toEqual(expected)
      expect(placeholders(locales.zh.get(key) ?? ''), `zh:${key}`).toEqual(expected)
    }
  })
})
