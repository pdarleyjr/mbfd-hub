---
name: quarto_render
description: Orchestrates .qmd to Typst rendering.
tools: ["quarto"]
validation: "Check for Typst binary in Windows PATH before execution."
---

# Quarto Render Skill

## Purpose
Render `.qmd` Quarto documents to professional PDF output via the Typst backend.

## Usage
1. Ensure Quarto CLI is installed: `quarto --version`
2. Place `.qmd` files in `reports/`
3. Run `quarto render reports/<file>.qmd --to typst`
4. All asset paths must be relative to the `.qmd` file location

## Validation
- Verify Typst binary is available in PATH
- Confirm source `.qmd` exists before rendering
- Check all image/asset references resolve correctly
