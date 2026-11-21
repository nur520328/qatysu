import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'
import kk from './locales/kk.json'
import ru from './locales/ru.json'
import zh from './locales/zh.json'

export const LOCALES = ['kk', 'ru', 'zh'] as const
export type Locale = (typeof LOCALES)[number]

/** Тіл атаулары аударылмайды: әр тіл өз атауымен көрсетіледі. */
export const LOCALE_NAMES: Record<Locale, string> = {
  kk: 'Қазақша',
  ru: 'Русский',
  zh: '中文',
}

export const DEFAULT_LOCALE: Locale = 'kk'
const STORAGE_KEY = 'qatysu.locale'

export function isLocale(value: unknown): value is Locale {
  return typeof value === 'string' && (LOCALES as readonly string[]).includes(value)
}

function readStoredLocale(): Locale {
  try {
    const stored = window.localStorage.getItem(STORAGE_KEY)
    return isLocale(stored) ? stored : DEFAULT_LOCALE
  } catch {
    return DEFAULT_LOCALE
  }
}

export function setLocale(locale: Locale): void {
  void i18n.changeLanguage(locale)
  document.documentElement.lang = locale
  try {
    window.localStorage.setItem(STORAGE_KEY, locale)
  } catch {
    // Сақтау мүмкін болмаса, тіл тек осы сеанста өзгереді.
  }
}

const initialLocale = readStoredLocale()

void i18n.use(initReactI18next).init({
  resources: {
    kk: { translation: kk },
    ru: { translation: ru },
    zh: { translation: zh },
  },
  lng: initialLocale,
  fallbackLng: DEFAULT_LOCALE,
  interpolation: { escapeValue: false },
})

document.documentElement.lang = initialLocale

export default i18n
