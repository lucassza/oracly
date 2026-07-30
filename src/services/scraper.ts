import { v4 as uuid } from 'uuid';
import { mkdir, writeFile, readFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { join } from 'node:path';
import { SokkerProApi } from '../api/client.js';
import {
  normalizeFixtureFromList,
  enrichMatchWithDetail,
  deduplicateMatches,
  validateMatch,
  parseOddsValue,
  parseNumber,
} from '../api/normalizer.js';
import type { NormalizedMatch } from '../types/schemas.js';
import { getEnv } from '../config/env.js';
import { getLogger } from '../utils/logger.js';
import { ProgressBar } from '../utils/progress.js';
import { PostgresMatchStore } from '../storage/postgres-store.js';

// ============================================================
// Scraper Execution Result
// ============================================================

export interface ScrapeResult {
  runId: string;
  requestedDate: string;
  startedAt: string;
  finishedAt: string;
  status: 'completed' | 'partially_completed' | 'failed' | 'authentication_required' | 'blocked';
  summary: {
    matchesFound: number;
    matchesProcessed: number;
    matchesFailed: number;
  };
  matches: NormalizedMatch[];
}

export interface ScrapeOptions {
  from?: string;  // HH:MM format
  to?: string;    // HH:MM format
}

// ============================================================
// Scraper Service
// ============================================================

export class ScraperService {
  private readonly api: SokkerProApi;
  private readonly logger;

  constructor() {
    this.api = new SokkerProApi();
    this.logger = getLogger();
  }

  /**
   * Run the full scraping process for a given date.
   */
  async scrape(date: string, options: ScrapeOptions = {}): Promise<ScrapeResult> {
    const runId = uuid();
    const startedAt = new Date().toISOString();
    const env = getEnv();

    this.logger.info({ runId, date, from: options.from, to: options.to, startedAt }, 'Scraper started');

    const result: ScrapeResult = {
      runId,
      requestedDate: date,
      startedAt,
      finishedAt: '',
      status: 'completed',
      summary: {
        matchesFound: 0,
        matchesProcessed: 0,
        matchesFailed: 0,
      },
      matches: [],
    };

    try {
      // Step 1: Fetch all fixtures for the date
      this.logger.info({ runId }, 'Fetching fixtures list');
      const fixturesResponse = await this.api.getFixtures(date);

      if (!fixturesResponse.success || !fixturesResponse.data) {
        this.logger.error({ runId }, 'Failed to fetch fixtures');
        result.status = 'failed';
        result.finishedAt = new Date().toISOString();
        return result;
      }

      const allFixtures = fixturesResponse.data.sortedCategorizedFixtures || [];
      const totalFound = fixturesResponse.data.fixtures_total || 0;
      result.summary.matchesFound = totalFound;

      this.logger.info(
        { runId, totalFound, leagues: allFixtures.length },
        'Fixtures list fetched',
      );

      // Step 2: Normalize each fixture from the list
      const collectedAt = new Date().toISOString();
      let allMatches: NormalizedMatch[] = [];

      for (const category of allFixtures) {
        for (const fixture of category.fixtures) {
          const match = normalizeFixtureFromList(fixture, category, collectedAt, 'utc-3');
          allMatches.push(match);
        }
      }

      this.logger.info({ runId, normalized: allMatches.length }, 'Fixtures normalized');

      // Step 3: Deduplicate
      allMatches = deduplicateMatches(allMatches);
      this.logger.info({ runId, afterDedup: allMatches.length }, 'Matches deduplicated');

      // Step 3.5: Filter by time period (Brasilia timezone)
      if (options.from || options.to) {
        const beforeFilter = allMatches.length;
        allMatches = allMatches.filter((match) => {
          if (!match.kickoffBrasilia) return true;
          
          // Extract HH:MM from kickoffBrasilia
          const timeMatch = match.kickoffBrasilia.match(/T(\d{2}:\d{2})/);
          if (!timeMatch) return true;
          
          const gameTime = timeMatch[1];
          
          if (options.from && gameTime < options.from) return false;
          if (options.to && gameTime > options.to) return false;
          
          return true;
        });
        
        this.logger.info(
          { runId, from: options.from, to: options.to, before: beforeFilter, after: allMatches.length },
          'Filtered by time period',
        );
      }

      // Step 4: Fetch details for each match (with concurrency limit)
      this.logger.info(
        { runId, toProcess: allMatches.length, concurrency: env.SCRAPER_CONCURRENCY },
        'Starting detail collection',
      );

      const enrichedMatches: NormalizedMatch[] = [];
      const failedMatches: string[] = [];
      const progress = new ProgressBar(allMatches.length);

      // Process in batches
      for (let i = 0; i < allMatches.length; i += env.SCRAPER_CONCURRENCY) {
        const batch = allMatches.slice(i, i + env.SCRAPER_CONCURRENCY);

        const batchPromises = batch.map(async (match) => {
          try {
            return await this.enrichMatch(match);
          } catch (error) {
            const msg = error instanceof Error ? error.message : String(error);
            this.logger.error(
              { runId, fixtureId: match.providerMatchId, error: msg },
              'Failed to enrich match',
            );
            failedMatches.push(match.providerMatchId || 'unknown');
            result.summary.matchesFailed++;
            return match; // Return partial match
          }
        });

        const batchResults = await Promise.all(batchPromises);
        enrichedMatches.push(...batchResults);
        progress.update(batchResults.length);

        // Delay between batches
        if (i + env.SCRAPER_CONCURRENCY < allMatches.length) {
          const delay = Math.floor(
            Math.random() * (env.SCRAPER_DELAY_MAX_MS - env.SCRAPER_DELAY_MIN_MS) +
              env.SCRAPER_DELAY_MIN_MS,
          );
          this.logger.debug({ runId, delay }, 'Delaying between batches');
          await new Promise((resolve) => setTimeout(resolve, delay));
        }
      }

      result.matches = enrichedMatches;
      result.summary.matchesProcessed = enrichedMatches.length;

      // Determine final status
      if (result.summary.matchesFailed > 0 && result.summary.matchesProcessed > 0) {
        result.status = 'partially_completed';
      } else if (result.summary.matchesFailed > 0 && result.summary.matchesProcessed === 0) {
        result.status = 'failed';
      }

      // Step 5: Validate all matches
      const validationErrors: string[] = [];
      for (const match of result.matches) {
        const validation = validateMatch(match);
        if (!validation.valid) {
          validationErrors.push(
            `${match.providerMatchId}: ${validation.errors.join(', ')}`,
          );
        }
      }

      if (validationErrors.length > 0) {
        this.logger.warn(
          { runId, errors: validationErrors.slice(0, 10) },
          'Validation warnings',
        );
      }

      // Step 6: Save results
      result.finishedAt = new Date().toISOString();
      await this.saveResults(result);

      this.logger.info(
        {
          runId,
          status: result.status,
          found: result.summary.matchesFound,
          processed: result.summary.matchesProcessed,
          failed: result.summary.matchesFailed,
          duration: new Date(result.finishedAt).getTime() - new Date(result.startedAt).getTime(),
        },
        'Scraper finished',
      );

      return result;
    } catch (error) {
      const msg = error instanceof Error ? error.message : String(error);
      this.logger.error({ runId, error: msg }, 'Scraper failed');
      result.status = 'failed';
      result.finishedAt = new Date().toISOString();

      // Save what we have
      try {
        await this.saveResults(result);
      } catch {
        // Ignore save errors
      }

      return result;
    }
  }

  /**
   * Enrich a single match with detail data.
   */
  private async enrichMatch(match: NormalizedMatch): Promise<NormalizedMatch> {
    if (!match.providerMatchId) return match;

    const collectedAt = new Date().toISOString();

    // Fetch detail
    const detail = await this.api.getFixtureDetail(match.providerMatchId);

    // Fetch X7 predictions (optional, don't fail if unavailable)
    let x7;
    try {
      x7 = await this.api.getFixtureX7(match.providerMatchId);
    } catch {
      this.logger.debug(
        { fixtureId: match.providerMatchId },
        'X7 predictions not available',
      );
    }

    // Enrich
    const enriched = enrichMatchWithDetail(match, detail, x7, collectedAt);

    this.logger.debug(
      {
        fixtureId: enriched.providerMatchId,
        home: enriched.homeTeam.name,
        away: enriched.awayTeam.name,
        status: enriched.status,
      },
      'Match enriched',
    );

    return enriched;
  }

  /**
   * Save results to JSON file.
   */
  private async saveResults(result: ScrapeResult): Promise<string> {
    const env = getEnv();
    const outputDir = env.OUTPUT_PATH;

    // Ensure directory exists
    await mkdir(outputDir, { recursive: true });

    const filename = `sokkerpro-${result.requestedDate}-${result.runId}.json`;
    const filepath = join(outputDir, filename);

    // Atomic write: write to temp file then rename
    const tempPath = `${filepath}.tmp`;
    await writeFile(tempPath, JSON.stringify(result, null, 2), 'utf-8');
    await writeFile(filepath, JSON.stringify(result, null, 2), 'utf-8');

    // Remove temp file
    try {
      const { unlink } = await import('node:fs/promises');
      await unlink(tempPath);
    } catch {
      // Ignore
    }

    this.logger.info({ filepath, matches: result.matches.length }, 'Results saved');

    const store = new PostgresMatchStore({
      host: env.POSTGRES_HOST,
      port: env.POSTGRES_PORT,
      database: env.POSTGRES_DB,
      user: env.POSTGRES_USER,
      password: env.POSTGRES_PASSWORD,
    });
    try {
      await store.saveMatches(result.matches);
      this.logger.info({ host: env.POSTGRES_HOST, database: env.POSTGRES_DB, matches: result.matches.length }, 'Results persisted to Postgres');
    } finally {
      await store.close();
    }

    return filepath;
  }
}
