# PTY-23 Frontend design: brand, logo, loader and design system

| Field | Value |
|---|---|
| Type | Story |
| Phase | 2b Frontend design |
| Status | Done |
| Weight | M |
| Builder | Opus 5.5 (orchestrator, main session; no agent slot used) |
| Reviewer | Mohamad (design approval given 2026-10-01) |
| Branch | `PTY-23-frontend-design` |
| Release | v0.2.0 |

## Goal

A design Mohamad approves before any UI code: the brand (logo, favicon, loader) and the design system, in light and dark.

## Acceptance criteria

- [x] Brand board: logo lockup (light/dark), mark variants, app icon, favicon at real 16/32/48 px in light and dark tabs, live loader loop with storyboard, busy-button state
- [x] Design-system boards, light and dark: colour, type, buttons, inputs (g/kg quantity input, error), status pills, progress, KPI tiles, stock table with a Negative row, skeletons, empty/error, toast, freshness stamp, identity chip, motion tokens
- [x] Approved by Mohamad (2026-10-01): "i approve the design"
- [x] Logo SVGs in `public/brand/`
- [x] design.md updated to the approved tokens (D-041, D-042)
- [x] D-043: screens built in code from the approved system
- [x] Q-012 closed: UI split into PTY-12, PTY-18 and PTY-19; PTY-17 deferred

## Builder notes

- Design canvas (private to Mohamad): https://claude.ai/artifact/3okL33eFNhVBXT9WtJRFWG
- The dark design-system board mounts the light one with `theme="dark"`, so the two cannot drift.
- One invented statistic ("3 below half a week") was caught and replaced with a real one before publishing.
- The design tool's guidance discourages Inter as generic. It was kept because Mohamad chose it and its tabular numbers suit the stock tables.

## Mohamad review

Approved: 2026-10-01 (design). PR review pending.
