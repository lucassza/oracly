import { z } from 'zod';

// ============================================================
// Normalized Match Schema (output model)
// ============================================================

export const normalizedTeamSchema = z.object({
  name: z.string(),
  providerId: z.string().optional(),
});

export const normalizedScoreSchema = z.object({
  home: z.number().optional(),
  away: z.number().optional(),
  halftimeHome: z.number().optional(),
  halftimeAway: z.number().optional(),
});

export const normalizedOddsSchema = z.object({
  over05: z.number().optional(),
  over15: z.number().optional(),
  over25: z.number().optional(),
  home: z.number().optional(),
  draw: z.number().optional(),
  away: z.number().optional(),
  bookmaker: z.string().optional(),
  collectedAt: z.string().optional(),
});

export const headToHeadEntrySchema = z.object({
  date: z.string().optional(),
  homeTeam: z.string().optional(),
  awayTeam: z.string().optional(),
  homeScore: z.number().optional(),
  awayScore: z.number().optional(),
});

export const goalEventSchema = z.object({
  minute: z.number(),
  extraMinute: z.number().optional(),
  player: z.string().optional(),
  team: z.enum(['home', 'away']),
  type: z.string().optional(),
});

export const timelineEventSchema = z.object({
  minute: z.number(),
  extraMinute: z.number().optional(),
  type: z.string(),
  player: z.string().optional(),
  team: z.enum(['home', 'away']),
  description: z.string().optional(),
});

// Pre-match first-half averages (last N matches), used by the half-time outcome-exclusion
// model. *_goal_sofrido (conceded) fields exist in the API but cover only ~16% of fixtures,
// so they're deliberately left out here.
export const firstHalfStatisticsSchema = z.object({
  homeGoalsAverage: z.number().optional(),
  awayGoalsAverage: z.number().optional(),
  homeShotsOnTargetAverage: z.number().optional(),
  awayShotsOnTargetAverage: z.number().optional(),
  homePossessionAverage: z.number().optional(),
  awayPossessionAverage: z.number().optional(),
  homeDangerousAttacksAverage: z.number().optional(),
  awayDangerousAttacksAverage: z.number().optional(),
});

export const normalizedStatisticsSchema = z.object({
  homeGoalsAverage: z.number().optional(),
  awayGoalsAverage: z.number().optional(),
  combinedGoalsAverage: z.number().optional(),

  homeOver15Percentage: z.number().optional(),
  awayOver15Percentage: z.number().optional(),

  homeScoredAverage: z.number().optional(),
  awayScoredAverage: z.number().optional(),

  homeConcededAverage: z.number().optional(),
  awayConcededAverage: z.number().optional(),

  bttsPercentage: z.number().optional(),
  over05Percentage: z.number().optional(),
  over15Percentage: z.number().optional(),
  over25Percentage: z.number().optional(),

  homeForm: z.array(z.string()).optional(),
  awayForm: z.array(z.string()).optional(),

  headToHead: z.array(headToHeadEntrySchema).optional(),

  goals: z.array(goalEventSchema).optional(),
  timeline: z.array(timelineEventSchema).optional(),

  firstHalf: firstHalfStatisticsSchema.optional(),

  additional: z.record(z.unknown()).optional(),
});

export const normalizedMatchSchema = z.object({
  provider: z.literal('sokkerpro'),
  providerMatchId: z.string().optional(),
  sourceUrl: z.string().url(),

  collectedAt: z.string(),
  matchDate: z.string().optional(),
  kickoffAt: z.string().optional(),
  kickoffBrasilia: z.string().optional(),
  timezone: z.string().optional(),

  country: z.string().optional(),
  competition: z.string().optional(),
  season: z.string().optional(),
  round: z.string().optional(),

  homeTeam: normalizedTeamSchema,
  awayTeam: normalizedTeamSchema,

  status: z.string().optional(),
  liveMinute: z.string().optional(),
  score: normalizedScoreSchema.optional(),
  odds: normalizedOddsSchema.optional(),
  // 1X2 odds for the first half only (BET365 primary, XBET fallback).
  oddsHalfTime: normalizedOddsSchema.optional(),
  statistics: normalizedStatisticsSchema.optional(),
  // Set only by the backfill-ht CLI when it retrofits `statistics.firstHalf`/`oddsHalfTime`
  // onto a pre-existing snapshot. Marks that those specific values were fetched long after
  // collectedAt (not alongside it), so if the provider's "last N games" average is a rolling
  // current-time figure rather than a value frozen at prediction time, it may not reflect
  // team form as of the original collectedAt. Absent on everything collected by the normal
  // scrape pipeline. See the half-time exclusion plan, Etapa 5.
  backfilledHalfTimeStatsAt: z.string().optional(),

  raw: z.record(z.unknown()).optional(),
});

export type NormalizedTeam = z.infer<typeof normalizedTeamSchema>;
export type NormalizedScore = z.infer<typeof normalizedScoreSchema>;
export type NormalizedOdds = z.infer<typeof normalizedOddsSchema>;
export type FirstHalfStatistics = z.infer<typeof firstHalfStatisticsSchema>;
export type HeadToHeadEntry = z.infer<typeof headToHeadEntrySchema>;
export type NormalizedStatistics = z.infer<typeof normalizedStatisticsSchema>;
export type NormalizedMatch = z.infer<typeof normalizedMatchSchema>;
