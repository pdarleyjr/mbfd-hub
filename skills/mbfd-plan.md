# MBFD Plan Skill

## Purpose
Break down an MBFD Hub task into an actionable implementation plan before writing any code.

## Workflow
1. Read CLAUDE.md and AI_AGENT_ERRORS.md from the repo root
2. Identify affected files, models, routes, and Filament resources
3. Check for related error entries that could impact the task
4. Enumerate steps with estimated risk level (LOW/MED/HIGH)
5. Identify environment boundaries (local vs VPS)
6. Output a numbered plan with file paths and commands

## Constraints
- Never skip reading CLAUDE.md — it contains critical deployment rules
- Never skip reading AI_AGENT_ERRORS.md — it prevents repeat mistakes
- Plans must specify which environment each step executes in
- No `@apply` in CSS, no pure grays, no nested cards
- Typography: Plus Jakarta Sans + Source Sans 3
