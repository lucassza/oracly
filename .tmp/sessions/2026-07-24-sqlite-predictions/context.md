# Task Context: SQLite Predictions

Session ID: 2026-07-24-sqlite-predictions
Created: 2026-07-24T00:00:00Z
Status: completed

## Current Request
Use a local SQLite database for historical match data and create an upcoming-match Over 0.5 FT prediction screen.

## Context Files (Standards to Follow)
- /home/lucas/.config/opencode/context/core/standards/code-quality.md
- /home/lucas/.config/opencode/context/core/workflows/component-planning.md

## Reference Files (Source Material to Look At)
- src/services/scraper.ts
- src/cli/run.ts
- src/dashboard/index.html
- src/types/schemas.ts
- src/config/env.ts
- storage/output/*.json

## External Docs Fetched
- Node.js 22 `node:sqlite` documentation for `DatabaseSync`, file-backed databases, prepared statements, and bound parameters.

## Components
- SQLite schema and repository
- Historical JSON import
- Scraper snapshot persistence
- Dashboard prediction API
- Over 0.5 FT predictions view

## Constraints
- Use Node 22 built-in `node:sqlite`; no external SQLite dependency.
- Preserve JSON output as an export/backup.
- Use the latest pre-kickoff snapshot for predictions and avoid duplicate final results.
- Mark `oj` as a model/theoretical odd, not a market price.

## Exit Criteria
- [x] Existing JSON output imports into local SQLite.
- [x] New scrape snapshots persist to SQLite.
- [x] Dashboard serves historical analytics and future O0.5 FT predictions from SQLite.
- [x] Build, lint, and tests pass.

## Results
- Imported 3,524 existing match snapshots into `storage/sokkerpro.db`.
- Scraper persists new normalized snapshots to SQLite after exporting JSON.
- Dashboard exposes SQLite-backed historical snapshots and O0.5 FT predictions.
- Added the upcoming O0.5 FT prediction view and configurable `DASHBOARD_PORT`.
- Build, endpoint validation, and 31 tests pass; lint has 16 existing warnings and no errors.
- Added O0.5 HT upcoming and historical APIs, including a compatible SQLite migration for HT predictions.
- Added the O0.5 HT dashboard view with upcoming/history tabs and accuracy cards; 32 tests now pass.
