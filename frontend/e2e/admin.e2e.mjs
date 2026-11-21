// Завуч беттерін нақты браузерде серверімен бірге тексереді.
// Алдын ала: backend `php artisan migrate:fresh --seed` + `php artisan serve`, frontend `npm run dev`.
// E2E_TEACHERS — ойдан шығарылған мұғалімдер файлы (.xlsx), E2E_SHOTS — скриншот қалтасы (міндетті емес).
import { chromium } from 'playwright-core'

const BASE = process.env.E2E_BASE ?? 'http://localhost:5173'
const SHOTS = process.env.E2E_SHOTS
const TEACHERS = process.env.E2E_TEACHERS

const browser = await chromium.launch({ channel: 'chrome', args: ['--no-sandbox'] })
const page = await browser.newPage({ viewport: { width: 1180, height: 820 } })
page.on('dialog', (dialog) => dialog.accept())
const step = (text) => console.log(`✓ ${text}`)
const shot = async (name) => {
  if (SHOTS) await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: true })
}

try {
  await page.goto(BASE)
  await page.getByLabel('Логин').fill('akimshi')
  await page.getByLabel('Пароль').fill('synaq-parol-1')
  await page.getByRole('button', { name: 'Кіру' }).click()
  await page.getByRole('alert').getByText('Алдымен оқу жылын ашыңыз.').waitFor()
  step('завуч кірді; оқу жылы жоқ екені айтылды')

  await page.getByRole('link', { name: 'Оқу жылын ашу' }).click()
  await page.getByRole('button', { name: 'Оқу жылын ашу' }).click()
  await page.getByRole('heading', { name: 'Оқу күнтізбесі' }).waitFor()
  step('оқу жылы ашылды, күнтізбе жасалды')

  await page.getByRole('button', { name: /^2 қараша/ }).click()
  await page.getByRole('button', { name: /^6 қараша/ }).click()
  await page.getByLabel(/Ескертпе/).fill('күзгі каникул')
  await page.getByRole('button', { name: 'Оқу күні емес деп белгілеу' }).click()
  await page.getByRole('button', { name: '4 қараша: оқу күні емес (күзгі каникул)' }).waitFor()
  step('каникул бес күнге белгіленді')
  await shot('calendar')

  await page.getByRole('link', { name: 'Сыныптар мен оқушылар' }).click()
  await page.getByLabel('Сынып (параллель)').selectOption('5')
  await page.getByLabel('Литералар').fill('а ә б в')
  await page.getByRole('button', { name: 'Сыныптарды ашу' }).click()
  await page.getByRole('status').getByText('Ашылды: 5А, 5Ә, 5Б, 5В').waitFor()
  await page.getByLabel('Сынып (параллель)').selectOption('7')
  await page.getByLabel('Литералар').fill('А Ә')
  await page.getByLabel('Смена').selectOption('afternoon')
  await page.getByRole('button', { name: 'Сыныптарды ашу' }).click()
  await page.getByRole('status').getByText('Ашылды: 7А, 7Ә').waitFor()
  step('алты сынып екі параллельде ашылды')

  await page.getByRole('link', { name: /^5Ә/ }).click()
  await page.getByRole('heading', { name: '5Ә сыныбы' }).waitFor()
  for (const [last, first] of [['Сынақов', 'Бірінші'], ['Әлібеков', 'Екінші'], ['Сынақов', 'Бірінші']]) {
    await page.getByLabel('Тегі').fill(last)
    await page.getByLabel('Аты', { exact: true }).fill(first)
    await page.getByRole('button', { name: 'Қосу' }).click()
    // Форма сақталған соң тазаланады; келесі оқушыны содан кейін ғана теру керек.
    if (page.url().includes('/admin/classes/') && (await page.locator('.roster__name').count()) < 2) {
      await page.locator('.roster__name', { hasText: `${last} ${first}` }).waitFor()
    }
  }
  await page.getByRole('alert').getByText('Бұл оқушы осы сыныпта бар.').waitFor()
  const names = await page.locator('.roster__name').allTextContents()
  if (names.join('|') !== 'Әлібеков Екінші|Сынақов Бірінші') throw new Error(`тізім реті қате: ${names.join('|')}`)
  step('оқушылар қосылды (қазақ әліпбиі ретімен), қайталанған оқушы қабылданбады')

  await page.getByRole('button', { name: 'Әлібеков Екінші: әрекеттер' }).click()
  await page.getByLabel('Қай сыныпқа').selectOption({ label: '5В' })
  await page.getByRole('button', { name: 'Бүгіннен бастап көшіру' }).click()
  await page.getByText('1 оқушы').waitFor()
  step('оқушы басқа сыныпқа көшірілді')
  await shot('class')

  await page.getByRole('link', { name: 'Мұғалімдер' }).click()
  await page.getByRole('link', { name: 'Excel-ден жүктеу' }).click()
  await page.getByLabel('Excel файл (.xlsx)').setInputFiles(TEACHERS)
  await page.getByRole('button', { name: 'Алдын ала қарау' }).click()
  await page.getByRole('button', { name: '3 аккаунт ашу' }).waitFor()
  await page.getByText('Сынып табылмады.').waitFor()
  await shot('teachers-preview')
  await page.getByRole('button', { name: '3 аккаунт ашу' }).click()
  const sheet = page.getByRole('region', { name: 'Логиндер мен бір реттік парольдер' })
  await sheet.getByText('3 аккаунт ашылды.').waitFor()
  const password = await sheet.locator('tbody tr').first().locator('td').nth(3).textContent()
  const login = await sheet.locator('tbody tr').first().locator('td').nth(2).textContent()
  if (!/^[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}$/.test(password ?? '')) throw new Error('пароль пішімі қате')
  step('мұғалімдер файлдан жүктелді, парольдер парағы шықты')
  await shot('credentials')
  await page.emulateMedia({ media: 'print' })
  await shot('credentials-print')
  await page.emulateMedia({ media: 'screen' })
  await sheet.getByRole('button', { name: 'Басып шығардым, тізімді жабу' }).click()

  await page.getByRole('link', { name: 'Мұғалімдер', exact: true }).click()
  await page.getByText(/5А жетекшісі/).waitFor()
  await page.getByRole('searchbox').fill('сынақ мұғалім')
  await page.getByRole('button', { name: 'Сынақ Мұғалім: әрекеттер' }).click()
  await page.getByRole('button', { name: 'Аккаунтты өшіру' }).click()
  await page.getByText('Өшірілген').waitFor()
  step('жетекшілік тағайындалды; аккаунт өшірілді')
  await page.getByRole('searchbox').fill('')
  await shot('users')

  await page.getByRole('link', { name: 'Баптау' }).click()
  await page.getByLabel(/Бір сменадағы ең көп сабақ саны/).fill('8')
  await page.getByRole('button', { name: 'Сақтау' }).click()
  await page.getByRole('status').getByText('Сақталды.').waitFor()
  await page.reload()
  if ((await page.getByLabel(/Бір сменадағы ең көп сабақ саны/).inputValue()) !== '8') throw new Error('баптау сақталмады')
  step('баптау сақталды және бетті жаңартқанда қалды')
  await shot('settings')

  await page.getByRole('link', { name: 'Сыныптар мен оқушылар' }).click()
  await page.getByRole('heading', { name: 'Сыныптар мен оқушылар' }).waitFor()
  await shot('classes')
  await page.setViewportSize({ width: 390, height: 844 })
  await shot('classes-phone')
  await page.getByRole('button', { name: 'Шығу' }).click()

  // Жаңа ашылған мұғалім бір реттік парольмен кіреді; әкімші беттері оған ашылмайды.
  await page.getByLabel('Логин').fill(login)
  await page.getByLabel('Пароль').fill(password)
  await page.getByRole('button', { name: 'Кіру' }).click()
  await page.getByRole('heading', { name: 'Жаңа пароль қойыңыз' }).waitFor()
  const status = await page.evaluate(async () => (await fetch('/api/admin/users', { headers: { Accept: 'application/json' } })).status)
  if (status !== 403) throw new Error(`мұғалімге әкімші API ашық: ${status}`)
  step('жаңа мұғалім бір реттік парольмен кірді; әкімші API оған жабық (403)')
} catch (error) {
  if (SHOTS) await page.screenshot({ path: `${SHOTS}/FAILED.png`, fullPage: true })
  throw error
} finally {
  await browser.close()
}
