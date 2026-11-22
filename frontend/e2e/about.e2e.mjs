// «Платформа туралы» бетін нақты браузерде тексереді: кірмеген адам ашады → завуч суретті жүктейді →
// сурет кірмеген адамға көрінеді (кішірейтілген, JPEG) → завуч суретті алып тастайды.
// Алдын ала: migrate:fresh --seed, serve + vite.
import { mkdtempSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { chromium } from 'playwright-core'

const BASE = process.env.E2E_BASE ?? 'http://localhost:5173'
const SHOTS = process.env.E2E_SHOTS
const step = (text) => console.log(`✓ ${text}`)
const browser = await chromium.launch({ channel: 'chrome', args: ['--no-sandbox'] })
const guest = await (await browser.newContext({ viewport: { width: 390, height: 844 } })).newPage()
const admin = await (await browser.newContext({ viewport: { width: 1180, height: 820 } })).newPage()

try {
  await guest.goto(BASE)
  await guest.getByRole('link', { name: 'Платформа туралы' }).click()
  await guest.getByRole('heading', { name: 'Платформа туралы' }).waitFor()
  await guest.getByText(/Нұсқасы: \d+\.\d+\.\d+\.\d+/).waitFor()
  await guest.getByRole('region', { name: 'Автор' }).getByText('Әбеу Нұргелді').waitFor()
  if (await guest.getByRole('img').count() !== 0) throw new Error('сурет жүктелмей тұрып көрінді')
  const mail = await guest.getByRole('link', { name: 'nur520328@gmail.com' }).getAttribute('href')
  if (mail !== 'mailto:nur520328@gmail.com') throw new Error('пошта сілтемесі қате')
  step('кірмеген адам кіру бетінен «Платформа туралы» бетін ашты: мақсаты, авторы, байланысы, нұсқасы бар')

  // Сынақ суретін браузердің өзінде жасаймыз (1600×1200 PNG), нақты адамның суреті қолданылмайды.
  const png = await admin.evaluate(async () => {
    const canvas = document.createElement('canvas')
    canvas.width = 1600
    canvas.height = 1200
    const ctx = canvas.getContext('2d')
    ctx.fillStyle = '#2f6fd0'
    ctx.fillRect(0, 0, 1600, 1200)
    ctx.fillStyle = '#ffffff'
    ctx.font = '160px sans-serif'
    ctx.fillText('СЫНАҚ', 480, 660)
    return canvas.toDataURL('image/png').split(',')[1]
  })
  const file = join(mkdtempSync(join(tmpdir(), 'qatysu-e2e-')), 'synaq.png')
  writeFileSync(file, Buffer.from(png, 'base64'))

  await admin.goto(BASE)
  await admin.getByLabel('Логин').fill('akimshi')
  await admin.getByLabel('Пароль').fill('synaq-parol-1')
  await admin.getByRole('button', { name: 'Кіру' }).click()
  await admin.getByRole('link', { name: 'Баптау', exact: true }).click()
  const panel = admin.getByRole('region', { name: '«Платформа туралы» бетіндегі сурет' })
  await panel.getByText('Сурет әлі жүктелмеген.').waitFor()
  await panel.getByLabel(/Сурет таңдау/).setInputFiles(file)
  await panel.getByRole('button', { name: 'Жүктеу' }).click()
  await panel.getByText('Сурет сақталды.').waitFor()
  step('завуч «Баптаудан» суретті жүктеді')

  await guest.reload()
  const photo = guest.getByRole('img', { name: 'Автордың суреті: Әбеу Нұргелді' })
  await photo.waitFor()
  const size = await photo.evaluate((img) => new Promise((resolve) => {
    const done = () => resolve([img.naturalWidth, img.naturalHeight])
    if (img.complete && img.naturalWidth > 0) done()
    else img.addEventListener('load', done)
  }))
  if (size[0] !== 720 || size[1] !== 540) throw new Error(`сурет кішірейтілмеген: ${size}`)
  const type = await guest.evaluate(async (src) => (await fetch(src)).headers.get('content-type'), await photo.getAttribute('src'))
  if (type !== 'image/jpeg') throw new Error(`сурет түрі күтпеген: ${type}`)
  if (SHOTS) await guest.screenshot({ path: `${SHOTS}/about.png`, fullPage: true })
  step('сурет кірмеген адамға көрінеді: 720×540 JPEG болып кішірейтілген')

  await panel.getByRole('button', { name: 'Суретті алып тастау' }).click()
  await panel.getByText('Сурет алып тасталды.').waitFor()
  await guest.reload()
  await guest.getByRole('region', { name: 'Автор' }).getByText('Әбеу Нұргелді').waitFor()
  if (await guest.getByRole('img').count() !== 0) throw new Error('алып тасталған сурет әлі көрініп тұр')
  step('завуч суретті алып тастады: бет суретсіз көрінеді')
  console.log('«Платформа туралы» сценарийі өтті.')
} finally {
  await browser.close()
}
