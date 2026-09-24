import { getEnv } from '../config/env.js';
import type { ScrapeOptions, ScrapeResult, ScraperService } from '../services/scraper.js';
import { getLogger } from './logger.js';
import { wait } from './time.js';

function shouldRetry(result: ScrapeResult): boolean {
  return result.status === 'failed' && result.summary.matchesFound === 0;
}

export async function scrapeDateWithRetry(
  scraper: ScraperService,
  date: string,
  options: ScrapeOptions = {},
): Promise<ScrapeResult> {
  const env = getEnv();
  const logger = getLogger();
  const maxAttempts = env.DAILY_SCRAPE_RETRY_COUNT + 1;

  let lastResult: ScrapeResult | null = null;

  for (let attempt = 1; attempt <= maxAttempts; attempt++) {
    const result = await scraper.scrape(date, options);
    lastResult = result;

    if (!shouldRetry(result)) {
      return result;
    }

    if (attempt < maxAttempts) {
      const delayMs = env.DAILY_SCRAPE_RETRY_DELAY_MS * attempt;
      logger.warn(
        { date, attempt, maxAttempts, delayMs, error: result.error },
        'Scrape returned no fixtures; retrying',
      );
      await wait(delayMs);
    }
  }

  return lastResult!;
}
