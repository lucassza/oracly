import { describe, expect, it, vi, beforeEach } from 'vitest';
import { resetEnv } from '../src/config/env.js';
import type { ScrapeResult, ScraperService } from '../src/services/scraper.js';
import { resetLogger } from '../src/utils/logger.js';
import { scrapeDateWithRetry } from '../src/utils/scrape-retry.js';

function failedResult(date: string): ScrapeResult {
  return {
    runId: 'test',
    requestedDate: date,
    startedAt: new Date().toISOString(),
    finishedAt: new Date().toISOString(),
    status: 'failed',
    summary: { matchesFound: 0, matchesProcessed: 0, matchesFailed: 0 },
    error: { phase: 'fetch_fixtures', message: 'HTTP 503' },
    matches: [],
  };
}

function okResult(date: string, found = 10): ScrapeResult {
  return {
    runId: 'test',
    requestedDate: date,
    startedAt: new Date().toISOString(),
    finishedAt: new Date().toISOString(),
    status: 'completed',
    summary: { matchesFound: found, matchesProcessed: found, matchesFailed: 0 },
    matches: [],
  };
}

describe('scrapeDateWithRetry', () => {
  beforeEach(() => {
    resetEnv();
    resetLogger();
  });

  it('retries when the fixtures list returns zero matches', async () => {
    vi.stubEnv('DAILY_SCRAPE_RETRY_COUNT', '2');
    vi.stubEnv('DAILY_SCRAPE_RETRY_DELAY_MS', '1');

    const scrape = vi
      .fn<ScraperService['scrape']>()
      .mockResolvedValueOnce(failedResult('2026-08-31'))
      .mockResolvedValueOnce(okResult('2026-08-31', 537));

    const scraper = { scrape } as unknown as ScraperService;
    const result = await scrapeDateWithRetry(scraper, '2026-08-31');

    expect(scrape).toHaveBeenCalledTimes(2);
    expect(result.summary.matchesFound).toBe(537);
  });

  it('stops after exhausting retries', async () => {
    vi.stubEnv('DAILY_SCRAPE_RETRY_COUNT', '1');
    vi.stubEnv('DAILY_SCRAPE_RETRY_DELAY_MS', '1');

    const scrape = vi.fn<ScraperService['scrape']>().mockResolvedValue(failedResult('2026-08-31'));
    const scraper = { scrape } as unknown as ScraperService;
    const result = await scrapeDateWithRetry(scraper, '2026-08-31');

    expect(scrape).toHaveBeenCalledTimes(2);
    expect(result.status).toBe('failed');
    expect(result.error?.phase).toBe('fetch_fixtures');
  });
});
