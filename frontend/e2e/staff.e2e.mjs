// Тізбекті нақты браузерде тексереді: кезекші белгіледі → пән мұғалімі кешігу мен кетуді тіркеді (телефон) →
// сынып жетекшісі себеп пен рұқсат қойды, тарихын көрді → завуч сол күнді ашты және күнделікті жиынтықты көрді.
// Алдын ала: migrate:fresh --seed, db:seed --class=DemoSeeder, serve + vite.
// E2E_TEACHER — жетекші емес мұғалімнің логині, E2E_HOMEROOM — 7Ә жетекшісінің логині.
import { chromium } from 'playwright-core'

const BASE = process.env.E2E_BASE ?? 'http://localhost:5173'
const SHOTS = process.env.E2E_SHOTS
const step = (text) => console.log(`✓ ${text}`)
const PHONE = { viewport: { width: 390, height: 844 }, hasTouch: true }

const browser = await chromium.launch({ channel: 'chrome', args: ['--no-sandbox'] })
const open = async (options) => {
  const page = await (await browser.newContext(options)).newPage()
  page.on('dialog', (dialog) => dialog.accept())
  return page
}
const adminPage = await open({ viewport: { width: 1180, height: 820 } })
const tablet = await open({ viewport: { width: 1280, height: 800 }, hasTouch: true })
const teacher = await open(PHONE)
const homeroom = await open(PHONE)
const shot = async (page, name) => {
  if (SHOTS) await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: true })
}
const login = async (page, name) => {
  await page.goto(BASE)
  await page.getByLabel('Логин').fill(name)
  await page.getByLabel('Пароль').fill('synaq-parol-1')
  await page.getByRole('button', { name: 'Кіру' }).click()
}

try {
  await login(adminPage, 'akimshi')
  await adminPage.getByRole('navigation', { name: 'Негізгі мәзір' }).waitFor()
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

  // Кезекші 7Ә-ні аралады: екі оқушы жоқ.
  await tablet.goto(`${BASE}/kezekshi`)
  await tablet.getByLabel('Код').fill(code)
  await tablet.getByRole('button', { name: 'Кіру' }).click()
  await tablet.getByRole('button', { name: /^7Ә/ }).click()
  const marks = tablet.locator('.mark')
  const late = await marks.nth(3).locator('.mark__name').textContent()
  const sick = await marks.nth(10).locator('.mark__name').textContent()
  const leaver = await marks.nth(6).locator('.mark__name').textContent()
  await marks.nth(3).click()
  await marks.nth(10).click()
  await tablet.getByRole('button', { name: 'Сақтау' }).click()
  await tablet.getByText('Барлық белгі серверге жетті.').waitFor()
  step(`кезекші 7Ә-ні аралады: ${late} мен ${sick} жоқ`)

  // Пән мұғалімі (телефон): кешіккен оқушыны сынып арқылы, кеткен оқушыны тегі бойынша табады.
  await login(teacher, process.env.E2E_TEACHER)
  await teacher.getByRole('link', { name: 'Кешігу мен кетіп қалуды тіркеу' }).click()
  const started = Date.now()
  await teacher.getByRole('button', { name: '7Ә', exact: true }).click()
  await teacher.getByRole('button', { name: new RegExp(`${late}.*жоқ`) }).click()
  await teacher.getByRole('button', { name: 'Келді', exact: true }).click()
  await teacher.getByRole('group', { name: 'Қай сабақтан келді?' }).getByRole('button', { name: '3-сабақ' }).click()
  await teacher.getByText(`${late}: 3-сабақтан келді деп тіркелді.`).waitFor()
  const taps = Date.now() - started
  await shot(teacher, 'staff-register')
  step(`мұғалім төрт басумен тіркеді: ${late} 3-сабақтан келді (автомат ${taps} мс жұмсады)`)

  await teacher.getByLabel('Тегі бойынша іздеу').fill(leaver.split(' ')[0].slice(0, 4))
  await teacher.getByRole('button', { name: new RegExp(`${leaver}.*7Ә`) }).click()
  await teacher.getByRole('button', { name: 'Кетіп қалды', exact: true }).click()
  const leaveLessons = await teacher.getByRole('group', { name: 'Қай сабақтан кетіп қалды?' }).getByRole('button').allTextContents()
  if (leaveLessons[0] !== '2-сабақ') throw new Error(`кету 2-сабақтан басталуы тиіс: ${leaveLessons.join(',')}`)
  await teacher.getByRole('group', { name: 'Қай сабақтан кетіп қалды?' }).getByRole('button', { name: '5-сабақ' }).click()
  await teacher.getByText(`${leaver}: 5-сабақтан кетіп қалды деп тіркелді.`).waitFor()
  step(`тегі бойынша іздеп тіркеді: ${leaver} 5-сабақтан кетіп қалды`)

  // Сынып жетекшісі: шешім күтіп тұрғандар → себеп пен рұқсат.
  await login(homeroom, process.env.E2E_HOMEROOM)
  const waiting = homeroom.getByRole('region', { name: 'Шешім күтіп тұрғандар' })
  await waiting.getByRole('link', { name: new RegExp(`${sick}.*себебін көрсету керек`) }).waitFor()
  await waiting.getByRole('link', { name: new RegExp(`${leaver}.*рұқсатын анықтау керек`) }).waitFor()
  if (await waiting.getByRole('link', { name: new RegExp(late) }).count() !== 0) throw new Error('кешіккен оқушы шешім күтпеуі тиіс')
  await shot(homeroom, 'staff-home')
  step('жетекшінің бастапқы бетінде екі оқушы шешім күтіп тұр (кешіккені жоқ)')

  await waiting.getByRole('link', { name: new RegExp(sick) }).click()
  await homeroom.getByText('2 оқушы бойынша шешім керек').waitFor()
  await homeroom.getByRole('button', { name: `${sick}: өзгерту` }).click()
  await homeroom.getByRole('group', { name: 'Болмау себебі' }).getByRole('radio', { name: 'ауырды' }).check()
  await homeroom.getByLabel('Түсініктеме').fill('анықтама әкеледі')
  await homeroom.getByRole('button', { name: 'Сақтау' }).click()
  await homeroom.getByText('1 оқушы бойынша шешім керек').waitFor()
  await homeroom.getByRole('button', { name: `${leaver}: өзгерту` }).click()
  await homeroom.getByRole('group', { name: 'Кетуге рұқсат' }).getByRole('radio', { name: 'рұқсатсыз' }).check()
  await homeroom.getByRole('button', { name: 'Сақтау' }).click()
  await homeroom.getByText('Бәрі анықталған.').waitFor()
  await homeroom.getByText('жоқ · ауырды · анықтама әкеледі').waitFor()
  await homeroom.getByText('бар · 5-сабақтан кетіп қалды · рұқсатсыз').waitFor()
  await homeroom.getByText('3-сабақтан келді', { exact: true }).waitFor()
  step('жетекші себеп («ауырды» + түсініктеме) пен рұқсат («рұқсатсыз») қойды: бәрі анықталды')

  await homeroom.getByRole('button', { name: `${late}: өзгерту` }).click()
  await homeroom.getByRole('button', { name: 'Тарихы' }).click()
  const history = homeroom.getByRole('list', { name: 'Тарихы' })
  await history.getByText(/Кезекші белгілеген/).waitFor()
  await history.getByText(/Келген сабағы: бос → 3-сабақ · мұғалім/).waitFor()
  await shot(homeroom, 'staff-day')
  step('тарихта кім не істегені көрінеді (кезекші, мұғалім)')

  // Жетекші шешім қабылдаған соң мұғалім кетуді өшіре алмайды, ал кешігуді (өз тіркеуін) өшіре алады.
  await teacher.reload()
  await teacher.getByRole('button', { name: '7Ә', exact: true }).click()
  await teacher.getByRole('button', { name: new RegExp(leaver) }).click()
  if (await teacher.getByRole('button', { name: '«Кетіп қалды» тіркеуін өшіру' }).count() !== 0) throw new Error('рұқсат қойылған кетуді өшіру ұсынылмауы тиіс')
  if (await teacher.getByText(/ауырды|рұқсатсыз|анықтама/).count() !== 0) throw new Error('мұғалімге себеп не рұқсат көрініп тұр')
  await teacher.getByRole('button', { name: new RegExp(late) }).click()
  await teacher.getByRole('button', { name: '«Келді» тіркеуін өшіру' }).waitFor()
  step('мұғалімге себеп пен рұқсат көрінбейді; жетекші шешкен кетуді өшіре алмайды')

  // Завуч кез келген сыныптың күнін аша алады.
  await adminPage.getByRole('link', { name: 'Сыныптар мен оқушылар' }).click()
  await adminPage.getByRole('link', { name: /^7Ә/ }).click()
  await adminPage.getByRole('link', { name: 'Қатысу', exact: true }).click()
  await adminPage.getByText('Бәрі анықталған.').waitFor()
  await adminPage.getByText('жоқ · ауырды · анықтама әкеледі').waitFor()
  step('завуч сынып бетінен сол күннің қатысуын ашты')

  // Завучтың күнделікті жиынтығы: сандар мен оқушылардың күйі сол тізбекке сәйкес.
  await adminPage.getByRole('link', { name: 'Жиынтық', exact: true }).click()
  const summary = adminPage.getByRole('region', { name: 'Түстен кейінгі смена' })
  const stats = summary.getByRole('status')
  await stats.getByText(/^1 \/ \d+$/).waitFor()
  for (const [value, label] of [['1', 'күні бойы жоқ'], ['1', 'кешікті'], ['1', 'кетіп қалды'], ['0', 'шешім күтеді']]) {
    const found = await stats.locator('.stat', { hasText: label }).locator('b').textContent()
    if (found !== value) throw new Error(`жиынтық «${label}»: ${found}, күтілгені ${value}`)
  }
  await summary.getByText('Жоқ оқушылар: ауырды 1 · себепті 0 · себепсіз 0 · себебі көрсетілмеген 0').waitFor()
  await summary.getByText('Кетіп қалғандар: рұқсатпен 0 · рұқсатсыз 1 · анықталмаған 0').waitFor()
  await summary.getByRole('row', { name: new RegExp(`${sick}.*жоқ · ауырды · анықтама әкеледі`) }).waitFor()
  await summary.getByRole('row', { name: new RegExp(`${late}.*3-сабақтан келді`) }).waitFor()
  await summary.getByRole('row', { name: new RegExp(`${leaver}.*бар · 5-сабақтан кетіп қалды · рұқсатсыз`) }).waitFor()
  await summary.getByRole('region', { name: /^Әлі аралынбаған сыныптар: \d+$/ }).getByRole('link', { name: '7Б сыныбының күнін ашу' }).waitFor()
  await shot(adminPage, 'summary')
  await summary.getByRole('link', { name: '7Ә сыныбының күнін ашу' }).first().click()
  await adminPage.getByText('Бәрі анықталған.').waitFor()
  await adminPage.getByRole('link', { name: '← Жиынтық' }).click()
  await adminPage.getByRole('heading', { name: 'Күнделікті жиынтық' }).waitFor()
  step('завуч жиынтығы: 1 сынып аралынды, 1 жоқ (ауырды), 1 кешікті, 1 кетті (рұқсатсыз); сыныптан жиынтыққа қайтты')

  // Кезекші енді бұл үш оқушының белгісін өзгерте алмайды.
  // Планшет тізімдерді серверден 20 секунд сайын және бет ашылғанда жаңартады; жаңарғанын күтеміз.
  await Promise.all([tablet.waitForResponse((r) => r.url().includes('/api/duty/rosters') && r.ok()), tablet.reload()])
  await tablet.waitForTimeout(300)
  await tablet.getByRole('button', { name: /^7Ә/ }).click()
  for (const name of [late, sick, leaver]) {
    if (!(await tablet.locator('.mark', { hasText: name }).isDisabled())) throw new Error(`${name} кезекшіге құлыптаулы болуы тиіс`)
  }
  step('мұғалім мен жетекші тиген үш оқушы кезекшіге құлыптаулы')
} catch (error) {
  if (SHOTS) {
    for (const [name, page] of [['teacher', teacher], ['homeroom', homeroom], ['admin', adminPage], ['tablet', tablet]]) {
      await page.screenshot({ path: `${SHOTS}/FAILED-${name}.png`, fullPage: true }).catch(() => undefined)
    }
  }
  throw error
} finally {
  await browser.close()
}
