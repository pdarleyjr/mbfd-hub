import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import { VitePWA } from 'vite-plugin-pwa'
import { sentryVitePlugin } from '@sentry/vite-plugin'
import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'

const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)

const manifestCopyPlugin = {
  name: 'manifest-copy',
  apply: 'build',
  generateBundle(options, bundle) {
    // Read the source manifest from the public folder
    const sourceManifestPath = path.join(__dirname, 'public', 'manifest.json')
    
    console.log(`[manifest-copy] Reading source manifest from: ${sourceManifestPath}`)
    
    if (fs.existsSync(sourceManifestPath)) {
      try {
        let manifest = JSON.parse(fs.readFileSync(sourceManifestPath, 'utf-8'))
        console.log('[manifest-copy] Source manifest loaded successfully')
        console.log('[manifest-copy] start_url:', manifest.start_url)
        console.log('[manifest-copy] scope:', manifest.scope)
        
        // Ensure paths are correct for /daily/ scope
        if (manifest.start_url !== '/daily/') {
          manifest.start_url = '/daily/'
          console.log('[manifest-copy] Updated start_url to /daily/')
        }
        if (manifest.scope !== '/daily/') {
          manifest.scope = '/daily/'
          console.log('[manifest-copy] Updated scope to /daily/')
        }
        
        // Emit the manifest.json as part of the bundle
        this.emitFile({
          type: 'asset',
          fileName: 'manifest.json',
          source: JSON.stringify(manifest, null, 2)
        })
        console.log('[manifest-copy] ✓ manifest.json added to bundle output')
      } catch (error) {
        console.error('[manifest-copy] Error processing manifest:', error.message)
      }
    } else {
      console.warn(`[manifest-copy] Source manifest not found at ${sourceManifestPath}`)
    }
  }
}

const serviceWorkerCopyPlugin = {
  name: 'service-worker-copy',
  apply: 'build',
  closeBundle() {
    const sourcePath = path.join(__dirname, 'public', 'service-worker.js')
    const destPath = path.join(__dirname, '..', '..', '..', 'public', 'daily', 'sw.js')
    
    console.log(`[service-worker-copy] Copying service worker from: ${sourcePath}`)
    console.log(`[service-worker-copy] To: ${destPath}`)
    
    if (fs.existsSync(sourcePath)) {
      try {
        fs.copyFileSync(sourcePath, destPath)
        console.log('[service-worker-copy] ✓ Service worker copied successfully as sw.js')
      } catch (error) {
        console.error('[service-worker-copy] Error copying service worker:', error.message)
      }
    } else {
      console.warn(`[service-worker-copy] Source service worker not found at ${sourcePath}`)
    }
  }
}

// https://vitejs.dev/config/
export default defineConfig({
  base: '/daily/',
  plugins: [
    react(),
    VitePWA({
      registerType: 'autoUpdate',
      includeAssets: ['favicon.ico', 'apple-touch-icon.png'],
      manifest: {
        name: 'MBFD Daily Checkout',
        short_name: 'MBFD Daily',
        description: 'Miami Beach Fire Department Daily Checkout System',
        start_url: '/daily/',
        scope: '/daily/',
        display: 'standalone',
        orientation: 'portrait',
        theme_color: '#1e3a5f',
        background_color: '#f8f6f2',
        icons: [
          {
            src: '/daily/icons/icon-192x192.png',
            sizes: '192x192',
            type: 'image/png',
          },
          {
            src: '/daily/icons/icon-512x512.png',
            sizes: '512x512',
            type: 'image/png',
          },
          {
            src: '/daily/icons/icon-512x512.png',
            sizes: '512x512',
            type: 'image/png',
            purpose: 'any maskable',
          },
        ],
      },
      workbox: {
        globPatterns: ['**/*.{js,css,html,ico,png,svg,woff2}'],
        runtimeCaching: [
          {
            urlPattern: /^https:\/\/.*\/api\//i,
            handler: 'NetworkFirst',
            options: {
              cacheName: 'api-cache',
              expiration: {
                maxEntries: 100,
                maxAgeSeconds: 60 * 60 * 24, // 24 hours
              },
              networkTimeoutSeconds: 5,
              cacheableResponse: {
                statuses: [0, 200],
              },
            },
          },
          {
            urlPattern: /\.(?:png|jpg|jpeg|svg|gif|webp)$/i,
            handler: 'CacheFirst',
            options: {
              cacheName: 'image-cache',
              expiration: {
                maxEntries: 60,
                maxAgeSeconds: 60 * 60 * 24 * 30, // 30 days
              },
            },
          },
          {
            urlPattern: /\.(?:woff2?|ttf|eot)$/i,
            handler: 'CacheFirst',
            options: {
              cacheName: 'font-cache',
              expiration: {
                maxEntries: 20,
                maxAgeSeconds: 60 * 60 * 24 * 365, // 1 year
              },
            },
          },
        ],
      },
    }),
    manifestCopyPlugin,
    serviceWorkerCopyPlugin,
    // Sentry plugin disabled temporarily - needs project setup in Sentry dashboard
    // sentryVitePlugin({
    //   org: process.env.SENTRY_ORG,
    //   project: process.env.SENTRY_PROJECT_FRONTEND,
    //   authToken: process.env.SENTRY_AUTH_TOKEN,
    //   telemetry: false,
    // }),
  ],
  build: {
    outDir: '../../../public/daily',
    emptyOutDir: true,
    sourcemap: true,
  },
  server: {
    fs: {
      strict: false,
    },
  },
})
