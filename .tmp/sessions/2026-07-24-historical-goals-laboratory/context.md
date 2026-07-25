# Task Context: Historical Goals Laboratory

Session ID: 2026-07-24-historical-goals-laboratory
Created: 2026-07-24T00:00:00Z
Status: completed

## Current Request
In the laboratory, show every past scored match without requiring the user to choose days.

## Context Files (Standards to Follow)
- /home/lucas/.config/opencode/context/core/standards/code-quality.md
- /home/lucas/.config/opencode/context/core/workflows/component-planning.md

## Reference Files (Source Material to Look At)
- src/dashboard/index.html
- storage/output/*.json

## Components
- Historical snapshot loading
- Pre-match prediction selection
- Finished match deduplication

## Constraints
- Load all stored output snapshots automatically in the Goals Laboratory view.
- Select the latest prediction captured before kickoff for each match.
- Display each final result only once.
- Keep the match dashboard file selector unchanged.

## Exit Criteria
- [x] Goals Laboratory aggregates all stored historical results automatically.
- [x] Build, lint, and tests pass.

## Results
- The laboratory now loads all output snapshots automatically.
- It deduplicates finished matches and pairs each with the latest pre-kickoff prediction.
- The file selector is hidden while the laboratory view is active.
- Build and tests pass; lint passes with 17 existing warnings and no errors.
