// Ай мен апта күндерінің атаулары осында сақталады: браузердің Intl қорында қазақ тілі
// жиі болмайды (Chrome «қыркүйек» орнына «M09» деп шығарады), сондықтан оған сүйенбейміз.

type Language = 'kk' | 'ru' | 'zh'

const MONTHS: Record<Language, string[]> = {
  kk: ['қаңтар', 'ақпан', 'наурыз', 'сәуір', 'мамыр', 'маусым', 'шілде', 'тамыз', 'қыркүйек', 'қазан', 'қараша', 'желтоқсан'],
  ru: ['январь', 'февраль', 'март', 'апрель', 'май', 'июнь', 'июль', 'август', 'сентябрь', 'октябрь', 'ноябрь', 'декабрь'],
  zh: ['1月', '2月', '3月', '4月', '5月', '6月', '7月', '8月', '9月', '10月', '11月', '12月'],
}

// Орыс тілінде күнмен бірге ілік септік қолданылады: «4 ноября».
const MONTHS_RU_GENITIVE = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря']

const WEEKDAYS: Record<Language, string[]> = {
  kk: ['Дс', 'Сс', 'Ср', 'Бс', 'Жм', 'Сб', 'Жс'],
  ru: ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'],
  zh: ['一', '二', '三', '四', '五', '六', '日'],
}

function lang(language: string): Language {
  return language === 'ru' || language === 'zh' ? language : 'kk'
}

function capitalize(text: string): string {
  return text.charAt(0).toUpperCase() + text.slice(1)
}

/** `YYYY-MM-DD` мәтінін уақыт белдеуіне тәуелсіз күнге айналдырады. */
export function parseDate(value: string): Date {
  const [y, m, d] = value.split('-').map(Number)
  return new Date(Date.UTC(y, m - 1, d))
}

/** «4 қараша» / «4 ноября» / «11月4日». */
export function dayMonth(value: string, language: string): string {
  const [, m, d] = value.split('-').map(Number)
  const l = lang(language)
  if (l === 'zh') return `${MONTHS.zh[m - 1]}${d}日`
  return `${d} ${l === 'ru' ? MONTHS_RU_GENITIVE[m - 1] : MONTHS.kk[m - 1]}`
}

/** «Қараша 2026» / «Ноябрь 2026» / «2026年11月»; кіріс — `YYYY-MM` не толық күн. */
export function monthYear(value: string, language: string): string {
  const [y, m] = value.split('-').map(Number)
  const l = lang(language)
  return l === 'zh' ? `${y}年${MONTHS.zh[m - 1]}` : `${capitalize(MONTHS[l][m - 1])} ${y}`
}

/** Дүйсенбі = 0 … жексенбі = 6. */
export function weekdayIndex(value: string): number {
  return (parseDate(value).getUTCDay() + 6) % 7
}

/** Аптаның қысқа күн атаулары, дүйсенбіден бастап. */
export function weekdayNames(language: string): string[] {
  return WEEKDAYS[lang(language)]
}

/** Серверден келген уақытты «21 қараша, 02:30» түрінде, Қазақстан уақытымен (UTC+5) көрсетеді. */
export function dayMonthTime(iso: string, language: string): string {
  // Мектеп те, сервер де бір уақыт белдеуінде; браузердің белдеуі басқа болса да, уақыт өзгермеуі тиіс.
  const local = new Date(new Date(iso).getTime() + 5 * 3600 * 1000).toISOString()
  return `${dayMonth(local.slice(0, 10), language)}, ${local.slice(11, 16)}`
}

/** Серверден келген уақыттың сағаты мен минуты, Қазақстан уақытымен: «13:10». */
export function clockTime(iso: string): string {
  return new Date(new Date(iso).getTime() + 5 * 3600 * 1000).toISOString().slice(11, 16)
}
