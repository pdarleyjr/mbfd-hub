#!/bin/bash
set -e
cd /root/mbfd-hub

echo "=== SAFETY CHECK: Production UP? ==="
curl -sI https://support.darleyplex.com | head -1

echo ""
echo "=== Phase 1a: Remove tracked artifact files ==="
git rm -f 'count()' 'bootstrap()' 'cnt' '$null' "'In" 'compose.yaml.backup2' 'compose-vps.yaml' "assignRole('training_admin')" 2>&1 || true

# Check for bcrypt file (special chars)
BCRYPT_FILE=$(git ls-files | grep -i "bcrypt" || true)
if [ -n "$BCRYPT_FILE" ]; then
  git rm -f "$BCRYPT_FILE" && echo "Removed: $BCRYPT_FILE"
fi

echo ""
echo "=== Phase 1b: Relocate root markdown reports to docs/ ==="
for f in BASELINE_EVIDENCE_REPORT.md CACHE_IMPLEMENTATION.md CHATIFY_FIX_REPORT.md CLOUDFLARE_ADMIN_ACCESS_FIX.md CSV_VS_DATABASE_ANALYSIS.md DIAGNOSTIC_ANALYSIS_REPORT.md deploy_manual_steps.md; do
  if git ls-files --error-unmatch "$f" 2>/dev/null; then
    git mv "$f" "docs/$f" && echo "Moved: $f -> docs/$f"
  else
    echo "Skipped (not tracked): $f"
  fi
done

echo ""
echo "=== Staging summary ==="
git status --short

echo ""
echo "=== Committing ==="
git config user.email "pdarleyjr@gmail.com"
git config user.name "pdarleyjr"
git commit -m "feat(hygiene): Phase 1 & 2 — delete terminal artifacts and relocate root markdown to docs/

Deleted artifacts:
- count(), bootstrap(), cnt, \$null, 'In
- compose.yaml.backup2, compose-vps.yaml
- assignRole('training_admin'), bcrypt('Penco1']) if present

Relocated to docs/:
- BASELINE_EVIDENCE_REPORT.md, CACHE_IMPLEMENTATION.md, CHATIFY_FIX_REPORT.md
- CLOUDFLARE_ADMIN_ACCESS_FIX.md, CSV_VS_DATABASE_ANALYSIS.md
- DIAGNOSTIC_ANALYSIS_REPORT.md, deploy_manual_steps.md

No existing app code modified. Zero downtime. No schema changes."

echo ""
echo "=== Pushing to GitHub ==="
git push origin main

echo ""
echo "=== SAFETY CHECK: Production still UP? ==="
curl -sI https://support.darleyplex.com | head -1

echo ""
echo "=== Phase 1 & 2 COMPLETE ==="
