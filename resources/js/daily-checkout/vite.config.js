import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react-swc'
import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'

const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)
const defaultOutDir = path.join(__dirname, '..', '..', '..', 'public', 'daily')
const dailyOutDir = process.env.DAILY_CHECKOUT_OUT_DIR
  ? path.resolve(__dirname, process.env.DAILY_CHECKOUT_OUT_DIR)
  : defaultOutDir

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
        
        const source = JSON.stringify(manifest, null, 2)
        for (const fileName of ['manifest.json', 'manifest.webmanifest']) {
          this.emitFile({
            type: 'asset',
            fileName,
            source
          })
          console.log(`[manifest-copy] \u2713 ${fileName} added to bundle output`)
        }
      } catch (error) {
        console.error('[manifest-copy] Error processing manifest:', error.message)
      }
    } else {
      console.warn(`[manifest-copy] Source manifest not found at ${sourceManifestPath}`)
    }
  }
}

// Ship the audited, application-owned worker directly. This avoids a second
// generated worker and keeps the runtime cache/push contract deterministic.
const serviceWorkerCopyPlugin = {
  name: 'service-worker-copy',
  apply: 'build',
  closeBundle() {
    const customSwPath = path.join(__dirname, 'public', 'service-worker.js')
    const outputSwPath = path.join(dailyOutDir, 'sw.js')
    if (!fs.existsSync(customSwPath)) {
      throw new Error(`Service worker source is missing: ${customSwPath}`)
    }
    fs.mkdirSync(dailyOutDir, { recursive: true })
    const assets = fs.readdirSync(path.join(dailyOutDir, 'assets'))
      .filter(file => /\.(js|css|png)$/.test(file)).sort().map(file => `/daily/assets/${file}`)
    const source = fs.readFileSync(customSwPath, 'utf-8')
    if (!source.includes('/* DAILY_BUILD_ASSETS */ []')) throw new Error('Service worker asset marker is missing')
    fs.writeFileSync(outputSwPath, source.replace('/* DAILY_BUILD_ASSETS */ []', JSON.stringify(assets)))
    console.log(`[service-worker-copy] \u2713 ${outputSwPath}`)
  }
}

// Laravel serves the canonical Hub manifest at the origin root. The isolated
// Daily preview must expose that same asset for the real worker's install step.
const hubManifestPreviewPlugin = {
  name: 'hub-manifest-preview',
  configurePreviewServer(server) {
    server.middlewares.use((request, response, next) => {
      if (request.url !== '/manifest.json') return next()
      response.setHeader('Content-Type', 'application/manifest+json')
      response.end(fs.readFileSync(path.join(__dirname, '../../../public/manifest.json')))
    })
  }
}

// https://vitejs.dev/config/
export default defineConfig({
  base: '/daily/',
  plugins: [
    react(),
    manifestCopyPlugin,
    serviceWorkerCopyPlugin,
    hubManifestPreviewPlugin,
  ],
  build: {
    outDir: dailyOutDir,
    emptyOutDir: true,
    sourcemap: false,
  },
  server: {
    fs: {
      strict: false,
    },
  },
})
