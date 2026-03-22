# MBFD Scribe Skill

## Purpose
Document completed work by updating CLAUDE.md, AI_AGENT_ERRORS.md, and .project_summary.md.

## Workflow
1. After successful implementation and review, update project documentation
2. Add new status entries to CLAUDE.md header section with date and summary
3. If new errors were discovered and fixed, add entries to AI_AGENT_ERRORS.md
4. Update .project_summary.md if new systems or major features were added
5. Commit documentation updates: `docs: update project documentation for <feature>`

## CLAUDE.md Update Format
```
> ✅ **Feature Name** (YYYY-MM-DD) — Brief description of what was implemented.
```

## AI_AGENT_ERRORS.md Entry Format
```
### ERROR-NNN: Short Title
**Date**: YYYY-MM-DD
**Severity**: 🔴 CRITICAL / 🟡 MEDIUM / 🟢 INFO
**Status**: ✅ RESOLVED
**File(s) Affected**: path/to/file.php

**Symptom**: What happened.
**Root Cause**: Why it happened.
**Fix Applied**: What was done.
**Prevention**: How to avoid it.
```
