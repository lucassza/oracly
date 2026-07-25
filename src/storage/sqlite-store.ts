import { mkdirSync } from 'node:fs';
import { dirname } from 'node:path';
import { DatabaseSync } from 'node:sqlite';
import type { NormalizedMatch } from '../types/schemas.js';
import { isMainLeague } from '../utils/league-filter.js';

export interface UpcomingOver05FtPrediction {
  providerMatchId: string;
  kickoffAt: string | undefined;
  country: string | undefined;
  competition: string | undefined;
  homeTeam: string;
  awayTeam: string;
  probability: number;
  modelOdd: number | undefined;
  combinedGoalsAverage: number | undefined;
  over05Percentage: number | undefined;
  bttsPercentage: number | undefined;
}

export interface UpcomingOver05HtPrediction extends UpcomingOver05FtPrediction {}

export const GOAL_MARKETS = [
  { key: 'gols_1t_05_over', label: 'Over 0.5 1T' },
  { key: 'gols_1t_15_over', label: 'Over 1.5 1T' },
  { key: 'over_15_ft_over', label: 'Over 1.5 FT' },
  { key: 'over_25_ft_over', label: 'Over 2.5 FT' },
  { key: 'btts_sim', label: 'BTTS' },
] as const;

export interface TodayMatch {
  providerMatchId: string;
  kickoffAt: string | undefined;
  kickoffBrasilia: string | undefined;
  country: string | undefined;
  competition: string | undefined;
  homeTeam: string;
  awayTeam: string;
  status: string | undefined;
  liveMinute: string | undefined;
  homeScore: number | undefined;
  awayScore: number | undefined;
  combinedGoalsAverage: number | undefined;
  predictions: Record<string, { probability: number | undefined; modelOdd: number | undefined }>;
}

export interface HistoricalMarketResult {
  providerMatchId: string;
  kickoffAt: string | undefined;
  country: string | undefined;
  competition: string | undefined;
  homeTeam: string;
  awayTeam: string;
  probability: number;
  halftimeGoals: number;
  finalGoals: number;
  firstGoalMinute: number | undefined;
  hit: boolean;
}

export interface HistoricalOver05HtResult extends HistoricalMarketResult {
  ftHit: boolean;
}

const getOver05FtPrediction = (match: NormalizedMatch): number | undefined =>
  (match.statistics?.additional?.x7Predictions as Record<string, { pred?: number }> | undefined)?.over_05_ft_over?.pred;

const getOver05FtModelOdd = (match: NormalizedMatch): number | undefined =>
  (match.statistics?.additional?.x7Predictions as Record<string, { oj?: number }> | undefined)?.over_05_ft_over?.oj;

const getOver05HtPrediction = (match: NormalizedMatch): number | undefined =>
  (match.statistics?.additional?.x7Predictions as Record<string, { pred?: number }> | undefined)?.gols_1t_05_over?.pred;

const getOver05HtModelOdd = (match: NormalizedMatch): number | undefined =>
  (match.statistics?.additional?.x7Predictions as Record<string, { oj?: number }> | undefined)?.gols_1t_05_over?.oj;

const getOver15FtPrediction = (match: NormalizedMatch): number | undefined =>
  (match.statistics?.additional?.x7Predictions as Record<string, { pred?: number }> | undefined)?.over_15_ft_over?.pred;

const getOver15FtModelOdd = (match: NormalizedMatch): number | undefined =>
  (match.statistics?.additional?.x7Predictions as Record<string, { oj?: number }> | undefined)?.over_15_ft_over?.oj;

export class SqliteMatchStore {
  private readonly database: DatabaseSync;

  constructor(databasePath: string) {
    mkdirSync(dirname(databasePath), { recursive: true });
    this.database = new DatabaseSync(databasePath);
    this.initializeSchema();
  }

  saveMatches(matches: NormalizedMatch[]): void {
    const statement = this.database.prepare(`
      INSERT INTO match_snapshots (
        provider_match_id, collected_at, kickoff_at, match_date, status, competition,
        home_team, away_team, home_score, away_score, halftime_home, halftime_away,
        over_05_ft_prediction, over_05_ft_model_odd, combined_goals_average,
        over_05_percentage, btts_percentage, over_05_ht_prediction, over_05_ht_model_odd, match_json
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
      ON CONFLICT(provider_match_id, collected_at) DO UPDATE SET
        status = excluded.status, home_score = excluded.home_score, away_score = excluded.away_score,
        halftime_home = excluded.halftime_home, halftime_away = excluded.halftime_away,
        over_05_ft_prediction = excluded.over_05_ft_prediction, over_05_ft_model_odd = excluded.over_05_ft_model_odd,
        over_05_ht_prediction = excluded.over_05_ht_prediction, over_05_ht_model_odd = excluded.over_05_ht_model_odd,
        match_json = excluded.match_json
    `);

    this.database.exec('BEGIN');
    try {
      matches.forEach((match) => {
        if (!match.providerMatchId) return;
        statement.run(
          match.providerMatchId,
          match.collectedAt,
          match.kickoffAt ?? null,
          match.matchDate ?? null,
          match.status ?? null,
          match.competition ?? null,
          match.homeTeam.name,
          match.awayTeam.name,
          match.score?.home ?? null,
          match.score?.away ?? null,
          match.score?.halftimeHome ?? null,
          match.score?.halftimeAway ?? null,
          getOver05FtPrediction(match) ?? null,
          getOver05FtModelOdd(match) ?? null,
          match.statistics?.combinedGoalsAverage ?? null,
          match.statistics?.over05Percentage ?? null,
          match.statistics?.bttsPercentage ?? null,
          getOver05HtPrediction(match) ?? null,
          getOver05HtModelOdd(match) ?? null,
          JSON.stringify(match),
        );
      });
      this.database.exec('COMMIT');
    } catch (error) {
      this.database.exec('ROLLBACK');
      throw error;
    }
  }

  getUpcomingOver05FtPredictions(now: string): UpcomingOver05FtPrediction[] {
    return this.buildUpcomingPredictions(now, getOver05FtPrediction, getOver05FtModelOdd);
  }

  getUpcomingOver05HtPredictions(now: string): UpcomingOver05HtPrediction[] {
    return this.buildUpcomingPredictions(now, getOver05HtPrediction, getOver05HtModelOdd);
  }

  getHistoricalOver05HtResults(): HistoricalOver05HtResult[] {
    return this.buildHistoricalResults(getOver05HtPrediction, (settled) => (settled.score?.halftimeHome ?? 0) + (settled.score?.halftimeAway ?? 0) >= 1)
      .map((result) => ({ ...result, ftHit: result.finalGoals >= 1 }));
  }

  getUpcomingOver15FtPredictions(now: string): UpcomingOver05FtPrediction[] {
    return this.buildUpcomingPredictions(now, getOver15FtPrediction, getOver15FtModelOdd);
  }

  getHistoricalOver15FtResults(): HistoricalMarketResult[] {
    return this.buildHistoricalResults(getOver15FtPrediction, (settled) => (settled.score?.home ?? 0) + (settled.score?.away ?? 0) >= 2);
  }

  private buildHistoricalResults(
    getPrediction: (match: NormalizedMatch) => number | undefined,
    hitCheck: (settled: NormalizedMatch) => boolean,
  ): HistoricalMarketResult[] {
    const byFixture = this.getAllSnapshots().reduce((groups, match) => {
      if (!match.providerMatchId) return groups;
      const snapshots = groups.get(match.providerMatchId) ?? [];
      snapshots.push(match);
      groups.set(match.providerMatchId, snapshots);
      return groups;
    }, new Map<string, NormalizedMatch[]>());

    return [...byFixture.entries()].flatMap(([providerMatchId, snapshots]) => {
      const settled = snapshots.filter((match) => match.status === 'finished').sort(byLatestCollection).at(-1);
      const kickoffAt = settled?.kickoffAt;
      if (!settled || !kickoffAt) return [];
      const predicted = snapshots
        .filter((match) => match.collectedAt < kickoffAt && getPrediction(match) !== undefined)
        .sort(byLatestCollection)
        .at(-1);
      const probability = predicted && getPrediction(predicted);
      if (!predicted || probability === undefined) return [];
      const halftimeGoals = (settled.score?.halftimeHome ?? 0) + (settled.score?.halftimeAway ?? 0);
      const finalGoals = (settled.score?.home ?? 0) + (settled.score?.away ?? 0);
      const goalMinutes = settled.statistics?.goals?.map((goal) => goal.minute) ?? [];
      return [{
        providerMatchId,
        kickoffAt,
        country: settled.country,
        competition: settled.competition,
        homeTeam: settled.homeTeam.name,
        awayTeam: settled.awayTeam.name,
        probability,
        halftimeGoals,
        finalGoals,
        firstGoalMinute: goalMinutes.length ? Math.min(...goalMinutes) : undefined,
        hit: hitCheck(settled),
      }];
    });
  }

  private buildUpcomingPredictions(
    now: string,
    getPrediction: (match: NormalizedMatch) => number | undefined,
    getModelOdd: (match: NormalizedMatch) => number | undefined,
  ): UpcomingOver05FtPrediction[] {
    return this.getLatestSnapshots()
      .filter((match) => isMainLeague(match.competition))
      .filter((match) => match.status === 'not_started' && (match.kickoffAt ?? '') > now)
      .flatMap((match) => {
        const probability = getPrediction(match);
        if (probability === undefined) return [];
        return [{
          providerMatchId: match.providerMatchId ?? '',
          kickoffAt: match.kickoffAt,
          country: match.country,
          competition: match.competition,
          homeTeam: match.homeTeam.name,
          awayTeam: match.awayTeam.name,
          probability,
          modelOdd: getModelOdd(match),
          combinedGoalsAverage: match.statistics?.combinedGoalsAverage,
          over05Percentage: match.statistics?.over05Percentage,
          bttsPercentage: match.statistics?.bttsPercentage,
        }];
      })
      .sort((a, b) => (a.kickoffAt ?? '').localeCompare(b.kickoffAt ?? '') || b.probability - a.probability);
  }

  getTodayMatches(dateBrasilia: string): TodayMatch[] {
    return this.getLatestSnapshots()
      .filter((match) => isMainLeague(match.competition))
      .filter((match) => toBrasiliaDate(match.kickoffAt) === dateBrasilia)
      .sort((a, b) => (a.kickoffAt ?? '').localeCompare(b.kickoffAt ?? ''))
      .map(toTodayMatch);
  }

  private getLatestSnapshots(): NormalizedMatch[] {
    const rows = this.database.prepare(`
      WITH latest AS (
        SELECT provider_match_id, MAX(collected_at) AS collected_at
        FROM match_snapshots GROUP BY provider_match_id
      )
      SELECT match_json FROM match_snapshots
      INNER JOIN latest USING (provider_match_id, collected_at)
    `).all();
    return rows.map((row) => JSON.parse(String(row.match_json)) as NormalizedMatch);
  }

  getAllSnapshots(): NormalizedMatch[] {
    const rows = this.database.prepare('SELECT match_json FROM match_snapshots ORDER BY collected_at ASC').all();
    return rows
      .map((row) => JSON.parse(String(row.match_json)) as NormalizedMatch)
      .filter((match) => isMainLeague(match.competition));
  }

  close(): void {
    this.database.close();
  }

  private initializeSchema(): void {
    this.database.exec(`
      CREATE TABLE IF NOT EXISTS match_snapshots (
        provider_match_id TEXT NOT NULL,
        collected_at TEXT NOT NULL,
        kickoff_at TEXT,
        match_date TEXT,
        status TEXT,
        competition TEXT,
        home_team TEXT NOT NULL,
        away_team TEXT NOT NULL,
        home_score INTEGER,
        away_score INTEGER,
        halftime_home INTEGER,
        halftime_away INTEGER,
        over_05_ft_prediction REAL,
        over_05_ft_model_odd REAL,
        combined_goals_average REAL,
        over_05_percentage REAL,
        btts_percentage REAL,
        match_json TEXT NOT NULL,
        PRIMARY KEY (provider_match_id, collected_at)
      ) STRICT;
      CREATE INDEX IF NOT EXISTS match_snapshots_upcoming
        ON match_snapshots (status, kickoff_at, collected_at);
    `);
    this.addColumn('over_05_ht_prediction', 'REAL');
    this.addColumn('over_05_ht_model_odd', 'REAL');
  }

  private addColumn(columnName: string, columnType: string): void {
    const columns = this.database.prepare('PRAGMA table_info(match_snapshots)').all();
    if (!columns.some((column) => column.name === columnName)) {
      this.database.exec(`ALTER TABLE match_snapshots ADD COLUMN ${columnName} ${columnType}`);
    }
  }
}

const byLatestCollection = (left: NormalizedMatch, right: NormalizedMatch): number => left.collectedAt.localeCompare(right.collectedAt);

// Brazil has used a fixed UTC-3 offset (no DST) since 2019 — safe to derive
// the Brasília calendar date straight from kickoffAt instead of trusting the
// precomputed `kickoffBrasilia` field, which is missing on some older snapshots.
const toBrasiliaDate = (kickoffAt: string | undefined): string | undefined => {
  if (!kickoffAt) return undefined;
  const timestamp = new Date(kickoffAt).getTime();
  if (Number.isNaN(timestamp)) return undefined;
  return new Date(timestamp - 3 * 60 * 60 * 1000).toISOString().slice(0, 10);
};

const toTodayMatch = (match: NormalizedMatch): TodayMatch => {
  const x7 = match.statistics?.additional?.x7Predictions as Record<string, { pred?: number; oj?: number }> | undefined;
  const predictions: Record<string, { probability: number | undefined; modelOdd: number | undefined }> = {};
  for (const { key } of GOAL_MARKETS) {
    predictions[key] = { probability: x7?.[key]?.pred, modelOdd: x7?.[key]?.oj };
  }
  return {
    providerMatchId: match.providerMatchId ?? '',
    kickoffAt: match.kickoffAt,
    kickoffBrasilia: match.kickoffBrasilia,
    country: match.country,
    competition: match.competition,
    homeTeam: match.homeTeam.name,
    awayTeam: match.awayTeam.name,
    status: match.status,
    liveMinute: match.liveMinute,
    homeScore: match.score?.home,
    awayScore: match.score?.away,
    combinedGoalsAverage: match.statistics?.combinedGoalsAverage,
    predictions,
  };
};
