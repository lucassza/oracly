// ============================================================
// Half-time outcome exclusion model
//
// Given first-half goal averages (and, optionally, market odds), picks the ONE outcome
// (home / draw / away) least likely to be the half-time result — i.e. the safest bet is
// "this outcome will NOT happen at half-time".
//
// Backtested (n=678, real HT scores from GET /fixture/{id}): the Poisson pick alone hits
// 84.5% of the time with full coverage, beating both the market-implied 1T odds (78.3%,
// ~80% coverage) and the naive "always exclude away" baseline (73.5%). See the plan doc for
// the full comparison table.
// ============================================================

export type Outcome = 'home' | 'draw' | 'away';

export interface OutcomeProbs {
  home: number;
  draw: number;
  away: number;
}

const FACTORIALS = [1, 1, 2, 6, 24, 120];
// 0..5 goals covers the entire realistic range of a half-time scoreline; the tail beyond
// that is negligible and gets folded in via normalization.
const GOAL_GRID = FACTORIALS.length;

// Floor for lambda: a team with a 0.0 first-half scoring average would otherwise collapse
// its win probability to ~0, which is a data artifact (small sample), not a real signal.
const MIN_LAMBDA = 0.08;

function poissonPmf(k: number, lambda: number): number {
  return (Math.exp(-lambda) * Math.pow(lambda, k)) / FACTORIALS[k];
}

function normalize(home: number, draw: number, away: number): OutcomeProbs {
  const total = home + draw + away;
  return { home: home / total, draw: draw / total, away: away / total };
}

export function poissonOutcomeProbs(lambdaHome: number, lambdaAway: number): OutcomeProbs {
  let home = 0;
  let draw = 0;
  let away = 0;
  for (let i = 0; i < GOAL_GRID; i++) {
    for (let j = 0; j < GOAL_GRID; j++) {
      const p = poissonPmf(i, lambdaHome) * poissonPmf(j, lambdaAway);
      if (i > j) home += p;
      else if (j > i) away += p;
      else draw += p;
    }
  }
  return normalize(home, draw, away);
}

// Removes the bookmaker overround from decimal odds, returning the market's implied
// probabilities for each outcome (they sum to 1).
export function impliedProbs(oddHome: number, oddDraw: number, oddAway: number): OutcomeProbs {
  return normalize(1 / oddHome, 1 / oddDraw, 1 / oddAway);
}

function argmin(probs: OutcomeProbs): Outcome {
  let best: Outcome = 'home';
  let bestValue = probs.home;
  if (probs.draw < bestValue) {
    best = 'draw';
    bestValue = probs.draw;
  }
  if (probs.away < bestValue) {
    best = 'away';
    bestValue = probs.away;
  }
  return best;
}

export interface HalfTimeExclusionFeatures {
  homeFirstHalfGoalsAverage: number | undefined;
  awayFirstHalfGoalsAverage: number | undefined;
  oddsHalfTime?: { home: number | undefined; draw: number | undefined; away: number | undefined };
  oddsFullTime?: { home: number | undefined; draw: number | undefined; away: number | undefined };
}

export interface HalfTimeExclusionResult {
  // The outcome picked as LEAST likely to happen at half-time — the recommended exclusion.
  excluded: Outcome;
  probs: OutcomeProbs;
  sources: {
    poisson: Outcome;
    oddsHt?: Outcome;
    oddsFt?: Outcome;
  };
  // How many of the available sources (including Poisson itself) agree with `excluded`.
  agreement: number;
  // Total sources that had enough data to produce a pick (1 to 3).
  sourcesAvailable: number;
}

// Poisson (from first-half goal averages) is the core signal — it's the only one with ~100%
// coverage and it beats the market in backtesting. Odds are corroborating signals only: when
// present they contribute to `agreement`, but never override the Poisson pick.
export function pickExclusion(features: HalfTimeExclusionFeatures): HalfTimeExclusionResult | undefined {
  const { homeFirstHalfGoalsAverage, awayFirstHalfGoalsAverage } = features;
  if (homeFirstHalfGoalsAverage === undefined || awayFirstHalfGoalsAverage === undefined) return undefined;

  const probs = poissonOutcomeProbs(Math.max(MIN_LAMBDA, homeFirstHalfGoalsAverage), Math.max(MIN_LAMBDA, awayFirstHalfGoalsAverage));
  const poissonPick = argmin(probs);

  const sources: HalfTimeExclusionResult['sources'] = { poisson: poissonPick };
  let agreement = 1;
  let sourcesAvailable = 1;

  const ht = features.oddsHalfTime;
  if (ht?.home !== undefined && ht?.draw !== undefined && ht?.away !== undefined) {
    const oddsHtPick = argmin(impliedProbs(ht.home, ht.draw, ht.away));
    sources.oddsHt = oddsHtPick;
    sourcesAvailable++;
    if (oddsHtPick === poissonPick) agreement++;
  }

  const ft = features.oddsFullTime;
  if (ft?.home !== undefined && ft?.draw !== undefined && ft?.away !== undefined) {
    const oddsFtPick = argmin(impliedProbs(ft.home, ft.draw, ft.away));
    sources.oddsFt = oddsFtPick;
    sourcesAvailable++;
    if (oddsFtPick === poissonPick) agreement++;
  }

  return { excluded: poissonPick, probs, sources, agreement, sourcesAvailable };
}
