// Интернетсіз жұмысты нақты браузерде тексереді (желі шынымен өшіріліп-қосылады):
// кіру → желі үзілді → бет жаңартылды (интерфейс те, тізімдер де планшеттен ашылады) → белгілеу →
// желі шықты → серверге жетті; екінші планшетпен қақтығыс → завуч шешті.
// Service Worker тек жиналған нұсқада жұмыс істейді, сондықтан: `npm run build`, `npx vite preview --port 4173`.
// Алдын ала: `php artisan migrate:fresh --seed`, `php artisan db:seed --class=DemoSeeder`, `php artisan serve`.
import { chromium } from 'playwright-core'

const BASE = process.env.E2E_BASE ?? 'http://localhost:4173'
const SHOTS = process.env.E2E_SHOTS
const step = (text) => console.log(`✓ ${text}`)

const browser = await chromium.launch({ channel: 'chrome', args: ['--no-sandbox'] })
const adminPage = await (await browser.newContext({ viewport: { width: 1180, height: 820 } })).newPage()
const firstContext = await browser.newContext({ viewport: { width: 1280, height: 800 }, hasTouch: true })
const first = await firstContext.newPage()
const second = await (await browser.newContext({ viewport: { width: 1280, height: 800 }, hasTouch: true })).newPage()
for (const page of [adminPage, first, second]) page.on('dialog', (dialog) => dialog.accept())
const shot = async (page, name) => {
  if (SHOTS) await page.screenshot({ path: `${SHOTS}/${name}.png` })
}
const enter = async (page, code) => {
  await page.goto(`${BASE}/kezekshi`)
  await page.getByLabel('Код').fill(code)
  await page.getByRole('button', { name: 'Кіру' }).click()
  await page.getByText(/^Аралынды:/).waitFor()
}
const mark = async (page, name, indexes) => {
  await page.getByRole('button', { name: new RegExp(`^${name}`) }).click()
  await page.getByRole('heading', { name: `${name} сыныбы` }).waitFor()
  const picked = []
  for (const index of indexes) {
    const row = page.locator('.mark').nth(index)
    picked.push(await row.locator('.mark__name').textContent())
    await row.click()
  }
  await page.getByRole('button', { name: 'Сақтау' }).click()
  await page.getByText(`${name} планшетте сақталды.`).waitFor()
  return picked
}

try {
  await adminPage.goto(BASE)
  await adminPage.getByLabel('Логин').fill('akimshi')
  await adminPage.getByLabel('Пароль').fill('synaq-parol-1')
  await adminPage.getByRole('button', { name: 'Кіру' }).click()
  await adminPage.getByRole('navigation', { name: 'Негізгі мәзір' }).waitFor()
  // Сценарий кез келген күні, кез келген сағатта жүруі үшін (тек сынақ дерекқорында).
  await adminPage.evaluate(async () => {
    const xsrf = () => decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN=')).slice(11))
    const call = (method, path, body) =>
      fetch(`/api/admin/${path}`, {
        method, body: JSON.stringify(body), credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf() },
      }).then((r) => { if (!r.ok) throw new Error(`${path}: ${r.status}`) })
    const now = new Date(Date.now() + 5 * 3600 * 1000).toISOString().slice(0, 10)
    const years = await (await fetch('/api/admin/academic-years', { headers: { Accept: 'application/json' } })).json()
    await call('PUT', `academic-years/${years.years[0].id}/calendar`, { from: now, to: now, is_school_day: true, note: null })
    await call('PATCH', 'settings', { afternoon_shift_end: 1380 })
  })
  await adminPage.getByRole('link', { name: 'Кезекші', exact: true }).click()
  const card = adminPage.getByRole('region', { name: /^Бүгін/ }).getByRole('region', { name: 'Түстен кейінгі смена' })
  await card.getByRole('button', { name: 'Код беру' }).click()
  const code = (await card.getByLabel('Код').textContent()).replace(/\s/g, '')

  await enter(first, code)
  await first.evaluate(() => navigator.serviceWorker.ready.then(() => undefined))
  // Бет Service Worker бақылауына өтуі үшін бір рет қайта ашылады (нақты өмірде: планшет бетті екінші рет ашқанда).
  await first.reload()
  await first.getByText('Аралынды: 0 / 19 сынып').waitFor()
  step('1-планшет кодпен кірді, 19 сыныптың тізімі планшетке сақталды')

  await firstContext.setOffline(true)
  await first.reload()
  await first.getByText('Аралынды: 0 / 19 сынып').waitFor()
  await first.getByText(/Интернет жоқ\. Белгілей беріңіз/).waitFor()
  step('желі өшірілді, бет жаңартылды: интерфейс те, сыныптар да планшеттен ашылды')

  const offlineAbsent = await mark(first, '6А', [2, 9])
  await mark(first, '6Ә', [])
  await mark(first, '7В', [5])
  await first.getByText('3 сынып серверге жіберілмеді').waitFor()
  await first.getByRole('button', { name: /^6А.*2 оқушы жоқ.*жіберілмеген/ }).waitFor()
  await first.getByRole('button', { name: 'Шығу' }).click()
  await first.getByText(/Жіберілмеген сынып бар/).waitFor()
  await shot(first, 'offline-pending')
  step('интернетсіз үш сынып белгіленді; «3 сынып серверге жіберілмеді» көрініп тұр, шығу жабық')

  // Планшет өшіп қосылды деп есептейік: бет қайта ашылады, дерек орнында.
  await first.reload()
  await first.getByText('3 сынып серверге жіберілмеді').waitFor()
  step('бет қайта ашылғанда жіберілмеген белгілер жоғалған жоқ')

  // Осы арада интернеті бар 2-планшет 6А-ны өзінше белгілеп қойды.
  await enter(second, code)
  const onlineAbsent = await mark(second, '6А', [0])
  await second.getByText('Барлық белгі серверге жетті.').waitFor()
  step(`2-планшет (интернеті бар) 6А-ны бөлек белгіледі: ${onlineAbsent.join(', ')} жоқ`)

  await firstContext.setOffline(false)
  await first.evaluate(() => window.dispatchEvent(new Event('online')))
  await first.getByText('Барлық белгі серверге жетті.').waitFor()
  await first.getByRole('button', { name: /^6А.*басқа планшет белгілеген/ }).waitFor()
  await first.getByRole('button', { name: /^6Ә.*бәрі бар.*25 оқушы/ }).waitFor()
  await shot(first, 'offline-synced')
  step('желі қосылды: белгілер өзі жіберілді; 6А «басқа планшет белгілеген» деп көрсетілді')

  await adminPage.reload()
  const conflicts = adminPage.getByRole('region', { name: 'Шешілмеген қақтығыстар' })
  const current = conflicts.getByRole('region', { name: 'Қазір сақтаулы тұрғаны' })
  const incoming = conflicts.getByRole('region', { name: 'Кейін жеткені' })
  await current.getByText(onlineAbsent[0]).waitFor()
  for (const name of offlineAbsent) await incoming.getByText(name).waitFor()
  await current.getByText(/2-планшет/).waitFor()
  await incoming.getByText(/1-планшет/).waitFor()
  await card.getByText('Аралынды: 3 / 19 сынып').waitFor()
  await shot(adminPage, 'offline-conflict')
  step('завуч екі нұсқаны қатар көрді (қай планшет, кімдер жоқ)')

  await incoming.getByRole('button', { name: 'Осыны қолдану' }).click()
  await conflicts.waitFor({ state: 'detached' })
  await second.reload()
  await second.getByRole('button', { name: /^6А.*2 оқушы жоқ/ }).click()
  for (const name of offlineAbsent) {
    const row = second.locator('.mark', { hasText: name })
    if ((await row.getAttribute('aria-pressed')) !== 'true') throw new Error(`${name} «жоқ» болуы тиіс еді`)
    if (!(await row.isDisabled())) throw new Error(`${name}: завуч шешкен белгі құлыптаулы болуы тиіс`)
  }
  step('завуч кейін жеткен нұсқаны таңдады: белгілер ауысты, кезекші оларды енді өзгерте алмайды')

  // Интерфейс файлдары кэште, ал оқушы дерегі API кэшінде жоқ екенін тексереміз.
  const cached = await first.evaluate(async () => {
    const urls = []
    for (const name of await caches.keys()) for (const request of await (await caches.open(name)).keys()) urls.push(new URL(request.url).pathname)
    return urls
  })
  if (cached.some((path) => path.startsWith('/api/'))) throw new Error('API жауабы кэшке түскен')
  if (!cached.some((path) => path === '/index.html')) throw new Error('интерфейс кэште жоқ')
  step(`кэште тек интерфейс файлдары (${cached.length}), API жауаптары жоқ`)
} catch (error) {
  if (SHOTS) {
    await first.screenshot({ path: `${SHOTS}/FAILED-first.png`, fullPage: true })
    await second.screenshot({ path: `${SHOTS}/FAILED-second.png`, fullPage: true })
    await adminPage.screenshot({ path: `${SHOTS}/FAILED-admin.png`, fullPage: true })
  }
  throw error
} finally {
  await browser.close()
}
