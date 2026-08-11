/**
 * Lighthouse CI configuration.
 *
 * Local collection defaults to representative public routes. The production
 * workflow overrides the URL list while retaining these settings and the
 * assertions below.
 *
 * Run locally:
 *    npm i -g @lhci/cli
 *    lhci autorun --config=.lighthouserc.cjs
 *
 * CI: see .github/workflows/lighthouse.yml
 */
module.exports = {
  ci: {
    collect: {
      // The harness assumes the app is reachable at the URL below.
      // Locally: php artisan serve --port=8000 (and seed at least one
      // route per `url` entry).
      url: [
        // Non-admin routes — must NOT regress under mobile preset.
        'http://localhost:8000/',
        'http://localhost:8000/pump-simulator',
        'http://localhost:8000/apparatus-layout',
      ],
      numberOfRuns: 3,
      settings: {
        preset: 'desktop',
        // Skip categories irrelevant to a static SSR site
        onlyCategories: ['performance', 'accessibility', 'best-practices', 'seo'],
        chromeFlags: '--no-sandbox --disable-dev-shm-usage',
      },
    },
    assert: {
      // Error-level thresholds are release gates. Category scores other than
      // accessibility remain warnings because they can vary across runners;
      // deterministic timing and transfer-size regressions fail the build.
      assertions: {
        'categories:performance': ['warn', { minScore: 0.85 }],
        'categories:accessibility': ['error', { minScore: 0.9 }],
        'categories:best-practices': ['warn', { minScore: 0.9 }],
        'categories:seo': ['warn', { minScore: 0.9 }],
        interactive: ['error', { maxNumericValue: 3500 }],
        'first-contentful-paint': ['error', { maxNumericValue: 1800 }],
        'largest-contentful-paint': ['error', { maxNumericValue: 3000 }],
        'cumulative-layout-shift': ['error', { maxNumericValue: 0.15 }],
        'total-blocking-time': ['error', { maxNumericValue: 400 }],
        'resource-summary:script:size': ['error', { maxNumericValue: 512000 }],
        'resource-summary:stylesheet:size': ['error', { maxNumericValue: 102400 }],
        'resource-summary:image:size': ['error', { maxNumericValue: 512000 }],
        'resource-summary:font:size': ['error', { maxNumericValue: 153600 }],
        'resource-summary:document:size': ['error', { maxNumericValue: 51200 }],
        'resource-summary:total:size': ['error', { maxNumericValue: 1536000 }],
        'resource-summary:script:count': ['error', { maxNumericValue: 15 }],
        'resource-summary:stylesheet:count': ['error', { maxNumericValue: 5 }],
        'resource-summary:font:count': ['error', { maxNumericValue: 5 }],
        'resource-summary:third-party:count': ['error', { maxNumericValue: 10 }],
        'resource-summary:total:count': ['error', { maxNumericValue: 75 }],
      },
    },
    upload: {
      // The temporary public storage option avoids the LHCI server setup
      // for the very first runs. Swap to GitHub Pages / S3 later.
      target: 'temporary-public-storage',
    },
  },
};
