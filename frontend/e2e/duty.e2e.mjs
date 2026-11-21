// Кезекшінің бүкіл жұмысын нақты браузерде тексереді: завуч код береді, планшет кіріп, сменаның
// барлық сыныбын белгілейді, түзейді; код ауыстырылғанда планшет шығарылады.
// Алдын ала: `php artisan migrate:fresh --seed`, `php artisan db:seed --class=DemoSeeder`, serve + vite.
import { chromium } from 'playwright-core'

const BASE = process.env.E2E_BASE ?? 'http://localhost:5173'
const SHOTS = process.env.E2E_SHOTS
const step = (text) => console.log(`✓ ${text}`)

const browser = await chromium.launch({ channel: 'chrome', args: ['--no-sandbox'] })
const adminPage = await (await browser.newContext({ viewport: { width: 1180, height: 820 } })).newPage()
// Мектеп планшетіне жақын өлшем (10 дюйм, көлденең).
const tablet = await (await browser.newContext({ viewport: { width: 1280, height: 800 }, hasTouch: true })).newPage()
for (const page of [adminPage, tablet]) page.on('dialog', (dialog) => dialog.accept())
const shot = async (page, name) => {
  if (SHOTS) await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: true })
}

try {
  await adminPage.goto(BASE)
  await adminPage.getByLabel('Логин').fill('akimshi')
  await adminPage.getByLabel('Пароль').fill('synaq-parol-1')
  await adminPage.getByRole('button', { name: 'Кіру' }).click()
  await adminPage.getByRole('navigation', { name: 'Негізгі мәзір' }).waitFor()

  // Сценарий кез келген күні, кез келген сағатта жүруі үшін: бүгін оқу күні деп белгіленеді,
  // түстен кейінгі смена 23:00-ге дейін созылады (тек сынақ дерекқорында).
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
  if (!/^\d{6}$/.test(code)) throw new Error(`код пішімі қате: ${code}`)
  await card.getByText('Әлі ешкім кірген жоқ').waitFor()
  step('завуч түстен кейінгі сменаға код берді, код бетте көрініп тұр')
  await shot(adminPage, 'duty-admin-issued')

  await tablet.goto(BASE)
  await tablet.getByRole('link', { name: 'Кезекшісіз бе? Кодпен кіру' }).click()
  await tablet.getByLabel('Код').fill('000000')
  await tablet.getByRole('button', { name: 'Кіру' }).click()
  await tablet.getByRole('alert').getByText(/Код қате не мерзімі өткен/).waitFor()
  await tablet.getByLabel('Код').fill(code)
  await tablet.getByRole('button', { name: 'Кіру' }).click()
  await tablet.getByText('Аралынды: 0 / 19 сынып').waitFor()
  step('планшет: қате код қабылданбады, дұрыс кодпен 19 сынып ашылды (6, 7, 8-сыныптар)')
  await shot(tablet, 'duty-classes-start')

  const names = await tablet.locator('.tile__name').allTextContents()
  const started = Date.now()
  let absentTotal = 0
  for (const [index, name] of names.entries()) {
    await tablet.getByRole('button', { name: new RegExp(`^${name}`) }).click()
    await tablet.getByRole('heading', { name: `${name} сыныбы` }).waitFor()
    const marks = tablet.locator('.mark')
    await marks.first().waitFor()
    // Әр сыныпта 0–2 оқушы жоқ.
    for (let k = 0; k < index % 3; k++) {
      await marks.nth(3 + k * 7).click()
      absentTotal++
    }
    if (index === 1) {
      // Планшетті көлденең ұстағанда үш баған, тігінен ұстағанда екі баған; екеуінде де бүкіл тізім
      // «Сақтау» жолағының үстінде айналдырусыз көрінуі тиіс.
      const layout = () => marks.evaluateAll((els) => ({
        columns: new Set(els.map((el) => Math.round(el.getBoundingClientRect().left))).size,
        bottom: Math.max(...els.map((el) => el.getBoundingClientRect().bottom)),
        bar: document.querySelector('.savebar').getBoundingClientRect().top,
      }))
      for (const [width, height, columns, name] of [[1280, 800, 3, 'duty-marking'], [800, 1280, 2, 'duty-marking-portrait']]) {
        await tablet.setViewportSize({ width, height })
        const seen = await layout()
        if (seen.columns !== columns) throw new Error(`${width}×${height}: тізім ${seen.columns} бағанда, ${columns} болуы керек`)
        if (seen.bottom > seen.bar) throw new Error(`${width}×${height}: тізім экранға сыймайды (${Math.round(seen.bottom)} > ${Math.round(seen.bar)})`)
        if (SHOTS) await tablet.screenshot({ path: `${SHOTS}/${name}.png` })
      }
      await tablet.setViewportSize({ width: 1280, height: 800 })
    }
    await tablet.getByRole('button', { name: 'Сақтау' }).click()
    await tablet.getByText(`${name} сақталды.`).waitFor()
  }
  const perClass = Math.round((Date.now() - started) / names.length)
  await tablet.getByText('Аралынды: 19 / 19 сынып').waitFor()
  await tablet.getByText('Барлық сынып аралынды.').waitFor()
  step(`барлық 19 сынып белгіленді (${absentTotal} оқушы жоқ); автомат бір сыныпқа орта есеппен ${perClass} мс жұмсады`)
  await shot(tablet, 'duty-classes-done')

  // Түзету: бірінші сыныпта бір оқушы қате белгіленген.
  await tablet.getByRole('button', { name: new RegExp(`^${names[1]}`) }).click()
  const wrong = tablet.locator('.mark--absent').first()
  const wrongName = await wrong.locator('.mark__name').textContent()
  await wrong.click()
  await tablet.getByRole('button', { name: 'Сақтау' }).click()
  await tablet.getByText(`${names[1]} сақталды.`).waitFor()
  await tablet.getByRole('button', { name: new RegExp(`^${names[1]}.*бәрі бар`) }).waitFor()
  step(`түзету сақталды: ${names[1]} сыныбында «${wrongName}» қайта «бар» болды`)

  await adminPage.reload()
  await card.getByText('Аралынды: 19 / 19 сынып').waitFor()
  await card.getByText('1 планшет кірген').waitFor()
  step('завуч бетінде аралау барысы мен кірген планшет көрінеді')
  await shot(adminPage, 'duty-admin-progress')

  await card.getByRole('button', { name: 'Жаңа код беру' }).click()
  await card.getByText('Әлі ешкім кірген жоқ').waitFor()
  await tablet.getByRole('button', { name: new RegExp(`^${names[0]}`) }).click()
  await tablet.getByRole('heading', { name: 'Кезекші коды' }).waitFor()
  await tablet.getByText('Смена аяқталды не код ауыстырылды.').waitFor()
  await tablet.getByLabel('Код').fill(code)
  await tablet.getByRole('button', { name: 'Кіру' }).click()
  await tablet.getByRole('alert').getByText(/Код қате не мерзімі өткен/).waitFor()
  step('жаңа код берілгенде планшет шығарылды, ескі код қабылданбайды')
} catch (error) {
  if (SHOTS) {
    await tablet.screenshot({ path: `${SHOTS}/FAILED-tablet.png`, fullPage: true })
    await adminPage.screenshot({ path: `${SHOTS}/FAILED-admin.png`, fullPage: true })
  }
  throw error
} finally {
  await browser.close()
}
