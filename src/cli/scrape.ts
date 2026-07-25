#!/usr/bin/env node

import { ScraperService } from '../services/scraper.js';
import { getLogger } from '../utils/logger.js';

// ============================================================
// CLI: Scrape SokkerPRO
// ============================================================

function parseArgs(): { date: string; headless: boolean } {
  const args = process.argv.slice(2);
  let date = '';
  let headless = true;

  for (const arg of args) {
    if (arg.startsWith('--date=')) {
      date = arg.split('=')[1] || '';
    }
    if (arg === '--headless=false' || arg === '--no-headless') {
      headless = false;
    }
  }

  // Default to today if no date provided
  if (!date) {
    const now = new Date();
    date = now.toISOString().split('T')[0];
  }

  return { date, headless };
}

async function main(): Promise<void> {
  const { date } = parseArgs();
  const logger = getLogger();

  logger.info({ date }, 'Starting SokkerPRO scraper');

  const scraper = new ScraperService();
  const result = await scraper.scrape(date);

  logger.info(
    {
      runId: result.runId,
      status: result.status,
      found: result.summary.matchesFound,
      processed: result.summary.matchesProcessed,
      failed: result.summary.matchesFailed,
    },
    'Scraping complete',
  );

  process.exit(result.status === 'failed' ? 1 : 0);
}

main().catch((error) => {
  console.error('Fatal error:', error);
  process.exit(1);
});
