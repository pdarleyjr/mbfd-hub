import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
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

// https://vitejs.dev/config/
export default defineConfig({
  base: '/daily/',
  plugins: [
    react(),
    manifestCopyPlugin,
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
