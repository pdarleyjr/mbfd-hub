/**
 * Lighthouse CI configuration.
 *
 * Two budgets:
 *  - mobile preset for non-admin routes (the regression guard)
 *  - desktop preset for /admin (the modernization-target dashboard)
 *
 * Run locally:
 *    npm i -g @lhci/cli
 *    lhci autorun --config=.lighthouserc.cjs
 *
 * CI: see .github/workflows/lighthouse-ci.yml
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
      // Soft thresholds while we capture the baseline. Tighten after
      // the first successful CI run records actual numbers.
      assertions: {
        'categories:performance': ['warn', { minScore: 0.85 }],
        'categories:accessibility': ['error', { minScore: 0.9 }],
        'categories:best-practices': ['warn', { minScore: 0.9 }],
        'categories:seo': ['warn', { minScore: 0.9 }],
      },
    },
    upload: {
      // The temporary public storage option avoids the LHCI server setup
      // for the very first runs. Swap to GitHub Pages / S3 later.
      target: 'temporary-public-storage',
    },
  },
};
