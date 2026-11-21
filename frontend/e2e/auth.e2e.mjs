// Нақты браузерде интерфейс пен серверді бірге тексереді.
// Алдын ала: backend `php artisan migrate:fresh --seed` + `php artisan serve`, frontend `npm run dev`.
// Іске қосу: npm run e2e
import { chromium } from 'playwright-core'

const BASE = process.env.E2E_BASE ?? 'http://localhost:5173'
const SHOT = process.env.E2E_SHOT

const browser = await chromium.launch({ channel: 'chrome', args: ['--no-sandbox'] })
const page = await browser.newPage({ viewport: { width: 390, height: 844 } })
const step = (text) => console.log(`✓ ${text}`)

try {
  await page.goto(BASE)
  await page.getByRole('heading', { name: 'Кіру' }).waitFor()
  step('кіру беті ашылды')

  await page.getByLabel('Логин').fill('zhana')
  await page.getByLabel('Пароль').fill('qate-parol-0')
  await page.getByRole('button', { name: 'Кіру' }).click()
  await page.getByRole('alert').getByText('Логин не пароль қате.').waitFor()
  step('қате пароль қабылданбады')

  await page.getByLabel('Пароль').fill('synaq-parol-1')
  await page.getByRole('button', { name: 'Кіру' }).click()
  await page.getByRole('heading', { name: 'Жаңа пароль қойыңыз' }).waitFor()
  step('бір реттік парольмен кірген адам пароль ауыстыру бетіне түсті')

  await page.getByLabel('Қазіргі пароль').fill('synaq-parol-1')
  await page.getByLabel('Жаңа пароль', { exact: true }).fill('zhana-parol-9')
  await page.getByLabel('Жаңа парольді қайталаңыз').fill('zhana-parol-9')
  await page.getByRole('button', { name: 'Сақтау' }).click()
  await page.getByRole('heading', { name: 'Сәлеметсіз бе, Сынақ Жаңа Мұғалім!' }).waitFor()
  step('пароль ауысты, бастапқы бет ашылды')

  await page.getByRole('button', { name: 'Русский' }).click()
  await page.getByRole('heading', { name: 'Здравствуйте, Сынақ Жаңа Мұғалім!' }).waitFor()
  await page.reload()
  await page.getByRole('heading', { name: 'Здравствуйте, Сынақ Жаңа Мұғалім!' }).waitFor()
  step('тіл орысшаға ауысты және бетті жаңартқаннан кейін де сақталды (сеанс те сақталды)')
  if (SHOT) await page.screenshot({ path: SHOT })

  await page.getByRole('button', { name: 'Выйти' }).click()
  await page.getByRole('heading', { name: 'Вход' }).waitFor()
  await page.reload()
  await page.getByRole('heading', { name: 'Вход' }).waitFor()
  step('шығу жұмыс істеді, бетті жаңартқанда кіру беті қалды')

  await page.getByLabel('Логин').fill('ZHANA')
  await page.getByLabel('Пароль').fill('zhana-parol-9')
  await page.getByRole('button', { name: 'Войти' }).click()
  await page.getByRole('heading', { name: 'Здравствуйте, Сынақ Жаңа Мұғалім!' }).waitFor()
  step('жаңа парольмен қайта кіру сәтті (логин бас әріппен)')
} finally {
  await browser.close()
}
