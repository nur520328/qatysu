import react from '@vitejs/plugin-react'
import { VitePWA } from 'vite-plugin-pwa'
import { defineConfig } from 'vitest/config'

export default defineConfig({
  plugins: [
    react(),
    // Сайт планшетке «қосымша» ретінде орнатылады және беттің өзі интернетсіз ашылады
    // (docs/кезең-5-жоспар.md, 10-шешім). Тек интерфейс файлдары сақталады; API жауаптары ешқашан
    // кэштелмейді: оқушы дерегі тек кезекшінің өз қоймасында (src/duty/db.ts) тұрады.
    VitePWA({
      registerType: 'autoUpdate',
      injectRegister: 'auto',
      includeAssets: ['icons/apple-touch-icon.png'],
      manifest: {
        name: 'Сабаққа қатысу',
        short_name: 'Қатысу',
        lang: 'kk',
        start_url: '/',
        scope: '/',
        display: 'standalone',
        background_color: '#f4f6f9',
        theme_color: '#14181f',
        icons: [
          { src: '/icons/icon-192.png', sizes: '192x192', type: 'image/png' },
          { src: '/icons/icon-512.png', sizes: '512x512', type: 'image/png' },
          { src: '/icons/icon-maskable-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
        ],
      },
      workbox: {
        globPatterns: ['**/*.{js,css,html,woff2,png}'],
        navigateFallback: '/index.html',
        // Бұл мекенжайлар әрқашан серверге барады (API, CSRF, жүктелетін файлдар).
        navigateFallbackDenylist: [/^\/api\//, /^\/sanctum\//, /^\/templates\//],
        cleanupOutdatedCaches: true,
        clientsClaim: true,
        skipWaiting: true,
      },
    }),
  ],
  server: {
    // Әзірлеу кезінде API сұраныстары жергілікті Laravel серверіне бағытталады.
    proxy: {
      '/api': 'http://127.0.0.1:8000',
      '/sanctum': 'http://127.0.0.1:8000',
    },
  },
  test: {
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],
    restoreMocks: true,
  },
})
