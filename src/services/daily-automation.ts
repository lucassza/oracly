import { getEnv } from '../config/env.js';
import { getLogger } from '../utils/logger.js';
import { addDays, getBrasiliaDate, getMsUntilNextBrasiliaTime, wait } from '../utils/time.js';
import { ScraperService } from './scraper.js';

export class DailyAutomationService {
  private readonly scraper;
  private readonly logger;

  constructor() {
    this.scraper = new ScraperService();
    this.logger = getLogger();
  }

  async runOnce(baseDate = getBrasiliaDate()): Promise<void> {
    const env = getEnv();
    for (let offset = 0; offset < env.DAILY_UPDATE_DAYS; offset++) {
      const date = addDays(baseDate, offset);
      this.logger.info({ date, offset }, 'Running daily automated scrape');
      await this.scraper.scrape(date);
    }
  }

  async start(): Promise<never> {
    const env = getEnv();
    if (!env.DAILY_UPDATE_ENABLED) {
      throw new Error('Daily automation is disabled. Set DAILY_UPDATE_ENABLED=true to run it continuously.');
    }

    this.logger.info(
      {
        hour: env.DAILY_UPDATE_HOUR_BRASILIA,
        minute: env.DAILY_UPDATE_MINUTE_BRASILIA,
        days: env.DAILY_UPDATE_DAYS,
      },
      'Daily automation started',
    );

    while (true) {
      const waitMs = getMsUntilNextBrasiliaTime(
        env.DAILY_UPDATE_HOUR_BRASILIA,
        env.DAILY_UPDATE_MINUTE_BRASILIA,
      );
      this.logger.info({ waitMs }, 'Waiting for next scheduled daily update');
      await wait(waitMs);

      try {
        await this.runOnce();
      } catch (error) {
        const message = error instanceof Error ? error.message : String(error);
        this.logger.error({ error: message }, 'Daily automated scrape failed');
      }
    }
  }
}
