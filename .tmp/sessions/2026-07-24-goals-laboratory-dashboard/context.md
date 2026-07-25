# Task Context: Goals Laboratory Dashboard

Session ID: 2026-07-24-goals-laboratory-dashboard
Created: 2026-07-24T00:00:00Z
Status: completed

## Current Request
Create a screen for clearer reading of goal strategy assertiveness using already collected matches.

## Context Files (Standards to Follow)
- /home/lucas/.config/opencode/context/core/standards/code-quality.md
- /home/lucas/.config/opencode/context/core/workflows/component-planning.md

## Reference Files (Source Material to Look At)
- src/dashboard/index.html
- src/cli/run.ts
- src/types/schemas.ts
- src/api/normalizer.ts
- storage/output/sokkerpro-2026-07-24-b5686b97-1e74-41b4-b3fc-922c759f368f.json
- storage/output/sokkerpro-2026-07-24-21c8a791-d31b-4857-a6a0-e9224f8c1b32.json

## Components
- Snapshot-pair analytics
- Goals Laboratory dashboard view
- Threshold and market comparison
- Match-level strategy results

## Constraints
- Use pre-kickoff prediction snapshots joined to later finished-match snapshots by providerMatchId.
- Show accuracy, coverage, sample size, and missing-timeline caveats.
- Do not present ROI because actual goal-market odds are unavailable.
- Retain the existing match dashboard.
- Visual direction: dark football analysis console; grass-charcoal surfaces, referee-yellow signal, green success and red failures; threshold confidence timeline as signature element.

## Exit Criteria
- [x] Dashboard exposes goals strategy backtest using stored JSON files.
- [x] Results cover five markets and threshold selection.
- [x] Build, typecheck, lint, and tests pass.

## Results
- Added the Goals Laboratory dashboard view to `src/dashboard/index.html`.
- Added flat-config TypeScript plugin/parser and Node globals to ESLint configuration.
- Added `globals` as an explicit development dependency.
- Build and tests pass; lint passes with 17 existing warnings and no errors.
