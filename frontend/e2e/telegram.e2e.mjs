// Telegram ботты нақты браузерде, жалған Telegram серверімен тексереді: завуч ботты қосады → аккаунтын байланыстырады
// (сілтемедегі кодты «Telegram» атынан серверге жібереміз) → бет байланысты өзі байқайды → `qatysu:notify` смена жиынтығын жібереді.
// Нақты Telegram-ға ештеңе кетпейді. Алдын ала: migrate:fresh --seed, db:seed --class=DemoSeeder, және сервер мына айнымалылармен:
//   TELEGRAM_BOT_TOKEN=e2e-token TELEGRAM_API_URL=http://127.0.0.1:8099 php artisan serve --port=8000   (+ vite)
import { execFile } from 'node:child_process'
import { promisify } from 'node:util'
import { createServer } from 'node:http'
import { chromium } from 'playwright-core'

const BASE = process.env.E2E_BASE ?? 'http://localhost:5173'
const API = process.env.E2E_API ?? 'http://localhost:8000'
const BACKEND = new URL('../../backend/', import.meta.url).pathname
const ENV = { ...process.env, TELEGRAM_BOT_TOKEN: 'e2e-token', TELEGRAM_API_URL: 'http://127.0.0.1:8099' }
const step = (text) => console.log(`✓ ${text}`)
// Бір процесте жалған сервер де тұр: команда оны бұғаттамауы үшін асинхронды түрде іске қосылады.
const artisan = (...args) => promisify(execFile)('php', ['artisan', ...args], { cwd: BACKEND, env: ENV, encoding: 'utf8' })

// Жалған Telegram: келген әр сұранысты жазып алады, бәріне «ok» дейді.
const received = []
const fake = createServer((req, res) => {
  const chunks = []
  req.on('data', (chunk) => chunks.push(chunk))
  req.on('end', () => {
    const method = req.url.split('/').pop()
    const raw = Buffer.concat(chunks).toString('utf8')
    let body = raw
    try { body = JSON.parse(raw) } catch { /* multipart: мәтін күйінде қалады */ }
    if (!req.url.startsWith('/bote2e-token/')) {
      res.writeHead(401, { 'Content-Type': 'application/json' }).end(JSON.stringify({ ok: false, error_code: 401, description: 'Unauthorized' }))
      return
    }
    received.push({ method, body })
    res.writeHead(200, { 'Content-Type': 'application/json' })
    res.end(JSON.stringify({ ok: true, result: method === 'getMe' ? { id: 42, username: 'qatysu_e2e_bot' } : {} }))
  })
})
await new Promise((resolve) => fake.listen(8099, '127.0.0.1', resolve))

const browser = await chromium.launch({ channel: 'chrome', args: ['--no-sandbox'] })
const page = await (await browser.newContext({ viewport: { width: 1180, height: 820 } })).newPage()
const adminCall = (method, path, body) => page.evaluate(async ([m, p, b]) => {
  const xsrf = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN=')).slice(11))
  const r = await fetch(`/api/admin/${p}`, {
    method: m, body: b === null ? undefined : JSON.stringify(b), credentials: 'same-origin',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf },
  })
  if (!r.ok) throw new Error(`${p}: ${r.status}`)
  return r.json()
}, [method, path, body])

try {
  await page.goto(BASE)
  await page.getByLabel('Логин').fill('akimshi')
  await page.getByLabel('Пароль').fill('synaq-parol-1')
  await page.getByRole('button', { name: 'Кіру' }).click()
  await page.getByRole('navigation', { name: 'Негізгі мәзір' }).waitFor()

  // Завуч ботты сайттан қосады.
  await page.getByRole('link', { name: 'Баптау', exact: true }).click()
  const bot = page.getByRole('region', { name: 'Telegram бот' })
  await bot.getByText('Токен жазылған, бірақ бот әлі қосылмаған.').waitFor()
  await bot.getByRole('button', { name: 'Ботты қосу' }).click()
  await bot.getByText('Бот: @qatysu_e2e_bot').waitFor()
  const webhook = received.find((r) => r.method === 'setWebhook')?.body
  if (!webhook?.url?.endsWith('/api/telegram/webhook') || !/^[0-9a-f]{64}$/.test(webhook.secret_token)) throw new Error('setWebhook күтпеген түрде шақырылды')
  step('завуч «Ботты қосу» басты: бот тексерілді, сайттың мекенжайы Telegram-ға тіркелді')

  // Байланыстыру: сілтемедегі кодты «Telegram» атынан серверге жібереміз.
  const mine = page.getByRole('region', { name: 'Telegram хабарлары' })
  await mine.getByRole('button', { name: 'Байланыстыру' }).click()
  const href = await mine.getByRole('link', { name: 'Telegram-ды ашу (@qatysu_e2e_bot)' }).getAttribute('href')
  const token = new URL(href).searchParams.get('start')
  const update = (text, secret) => fetch(`${API}/api/telegram/webhook`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Telegram-Bot-Api-Secret-Token': secret },
    body: JSON.stringify({ update_id: 1, message: { chat: { id: 4242, type: 'private' }, text } }),
  })
  if ((await update(`/start ${token}`, 'bogde')).status !== 403) throw new Error('құпиясы қате сұраныс қабылданды')
  if ((await update(`/start ${token}`, webhook.secret_token)).status !== 200) throw new Error('webhook сұранысы қабылданбады')
  const welcome = received.find((r) => r.method === 'sendMessage' && String(r.body.chat_id) === '4242')
  if (!welcome?.body.text.includes('аккаунтыңыз байланыстырылды')) throw new Error('байланыстыру туралы жауап келмеді')
  await mine.getByText('Telegram байланыстырылған').waitFor({ timeout: 15000 })
  step('аккаунт байланыстырылды: құпиясы қате сұраныс қабылданбады, бет байланысты өзі байқады')

  // Смена жиынтығы: бүгін оқу күні, таңғы смена 10:00-де бітеді деп қойылады (10:00-ден кейін ғана тексеріледі).
  const now = new Date(Date.now() + 5 * 3600 * 1000)
  if (now.getUTCHours() >= 10 && now.getUTCMinutes() + now.getUTCHours() * 60 > 605) {
    const today = now.toISOString().slice(0, 10)
    const years = await adminCall('GET', 'academic-years', null)
    await adminCall('PUT', `academic-years/${years.years[0].id}/calendar`, { from: today, to: today, is_school_day: true, note: null })
    await adminCall('PATCH', 'settings', { morning_shift_end: 600 })
    try {
      const before = received.length
      await artisan('qatysu:notify')
      await artisan('qatysu:notify')
      const sent = received.slice(before).filter((r) => r.method === 'sendMessage').map((r) => r.body.text)
      const summary = sent.filter((text) => text.startsWith('Таңғы смена'))
      if (summary.length !== 1 || !/Аралынды: 0 \/ \d+ сынып\./.test(summary[0]) || !summary[0].includes('/admin/summary?date=')) {
        throw new Error(`смена жиынтығы күтпеген: ${JSON.stringify(sent)}`)
      }
      step('смена біткенде завучқа жиынтық бір-ақ рет келді (аралынбаған сыныптармен), қайта іске қосқанда қайталанбады')
    } finally {
      await adminCall('PATCH', 'settings', { morning_shift_end: 790 })
    }
  } else {
    console.log('  (10:05-ке дейін: смена жиынтығы тексерілмеді)')
  }

  // Ажырату.
  await page.reload()
  await page.getByRole('region', { name: 'Telegram хабарлары' }).getByRole('button', { name: 'Ажырату' }).click()
  await page.getByRole('region', { name: 'Telegram хабарлары' }).getByRole('button', { name: 'Байланыстыру' }).waitFor()
  await page.getByRole('region', { name: 'Telegram бот' }).getByText(/Байланыстырған қызметкер: [01] \//).waitFor()
  step('завуч Telegram-ды ажыратты')
  console.log('Telegram сценарийі өтті.')
} finally {
  await browser.close()
  fake.close()
}
