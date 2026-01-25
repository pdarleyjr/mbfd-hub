import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import { VitePWA } from 'vite-plugin-pwa'
import { sentryVitePlugin } from '@sentry/vite-plugin'

// https://vitejs.dev/config/
export default defineConfig({
  base: '/daily/',
  plugins: [
    react(),
    VitePWA({
      base: '/daily/',
      scope: '/daily/',
      registerType: 'autoUpdate',
      includeAssets: ['vite.svg', 'icons/*.png'],
      manifest: {
        name: 'MBFD Daily Checkout',
        short_name: 'MBFD Checkout',
        description: 'Daily apparatus inspection app for Miami Beach Fire Department',
        start_url: '/daily/',
        scope: '/daily/',
        display: 'standalone',
        background_color: '#ffffff',
        theme_color: '#1e40af',
        orientation: 'portrait-primary',
        categories: ['productivity', 'utilities'],
        prefer_related_applications: false,
        icons: [
          {
            src: '/daily/icons/icon-192.png',
            sizes: '192x192',
            type: 'image/png',
            purpose: 'any maskable'
          },
          {
            src: '/daily/icons/icon-512.png',
            sizes: '512x512',
            type: 'image/png',
            purpose: 'any maskable'
          }
        ],
        screenshots: [],
        shortcuts: [
          {
            name: 'Start Inspection',
            short_name: 'Inspect',
            description: 'Begin daily apparatus inspection',
            url: '/daily/',
            icons: [
              {
                src: '/daily/icons/icon-192.png',
                sizes: '192x192'
              }
            ]
          }
        ]
      },
      workbox: {
        navigateFallback: '/daily/index.html',
        globPatterns: ['**/*.{js,css,html,ico,png,svg}'],
        runtimeCaching: [
          {
            urlPattern: /^https:\/\/support\.darleyplex\.com\/api\/.*/i,
            handler: 'NetworkFirst',
            options: {
              cacheName: 'api-cache',
              expiration: {
                maxEntries: 10,
                maxAgeSeconds: 60 * 60
              }
            }
          }
        ]
      }
    }),
    sentryVitePlugin({
      org: process.env.SENTRY_ORG,
      project: process.env.SENTRY_PROJECT_FRONTEND,
      authToken: process.env.SENTRY_AUTH_TOKEN,
    }),
  ],
  build: {
    outDir: '../../../public/daily',
    emptyOutDir: true,
    sourcemap: true,
  },
})
