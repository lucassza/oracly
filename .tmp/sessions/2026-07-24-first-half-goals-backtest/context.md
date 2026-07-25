# Task Context: First-Half Goals Backtest

Session ID: 2026-07-24-first-half-goals-backtest
Created: 2026-07-24T00:00:00Z
Status: completed

## Current Request
Use the already collected match data to assess the strategy and assertiveness for goals in the first half.

## Context Files (Standards to Follow)
- /home/lucas/.config/opencode/context/core/standards/code-quality.md
- /home/lucas/.config/opencode/context/core/workflows/component-planning.md

## Reference Files (Source Material to Look At)
- storage/output/sokkerpro-2026-07-24-b5686b97-1e74-41b4-b3fc-922c759f368f.json
- storage/output/sokkerpro-2026-07-24-21c8a791-d31b-4857-a6a0-e9224f8c1b32.json
- src/api/normalizer.ts

## Components
- Match snapshots
- First-half prediction assessment
- Threshold analysis

## Constraints
- Join predictions and results by providerMatchId.
- Use the pre-match snapshot for X7 predictions and the later snapshot for final scores.
- A finished match with missing halftime scores is treated as 0-0 at halftime.

## Exit Criteria
- [x] Produce accuracy and coverage by prediction threshold.
- [x] Recommend an operational prediction threshold.

## Results
- 135 settled matches joined to pre-kickoff predictions.
- The recommended initial selection is `gols_1t_05_over.pred >= 80`: 18 wins in 20 matches (90% accuracy), with 15% coverage.
- Of 20 selected matches, 3 are confirmed without a goal through minute 30; 3 matches lack goal timelines and cannot be classified for this timing metric.
- 18 selected matches are confirmed to have two or more goals through minute 70; one additional match has no goal timeline, so its timing is undetermined.
- The sample covers a single match day and must be expanded before real-money use.
