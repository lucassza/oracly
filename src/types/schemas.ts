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
  statistics: normalizedStatisticsSchema.optional(),

  raw: z.record(z.unknown()).optional(),
});

export type NormalizedTeam = z.infer<typeof normalizedTeamSchema>;
export type NormalizedScore = z.infer<typeof normalizedScoreSchema>;
export type NormalizedOdds = z.infer<typeof normalizedOddsSchema>;
export type HeadToHeadEntry = z.infer<typeof headToHeadEntrySchema>;
export type NormalizedStatistics = z.infer<typeof normalizedStatisticsSchema>;
export type NormalizedMatch = z.infer<typeof normalizedMatchSchema>;
