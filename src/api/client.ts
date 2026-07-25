import { getEnv } from '../config/env.js';
import { getLogger } from '../utils/logger.js';
import { retry } from '../utils/retry.js';
import type {
  FixturesResponse,
  FixtureDetailResponse,
  X7Response,
} from './schemas.js';

// ============================================================
// SokkerPRO API Client
// ============================================================

export class SokkerProApi {
  private readonly baseUrl: string;
  private readonly timeout: number;

  constructor() {
    const env = getEnv();
    this.baseUrl = env.SOKKERPRO_API_BASE_URL;
    this.timeout = env.PLAYWRIGHT_TIMEOUT;
  }

  /**
   * Fetch all matches for a given date.
   * @param date - Format YYYY-MM-DD
   * @param timezone - e.g. "utc-3", "America/Sao_Paulo"
   */
  async getFixtures(date: string, timezone = 'utc-3'): Promise<FixturesResponse> {
    const logger = getLogger();
    const url = `${this.baseUrl}/home/fixtures/${date}/${timezone}`;

    logger.info({ url, date, timezone }, 'Fetching fixtures');

    const data = await retry(
      async () => {
        const response = await fetch(url, {
          method: 'GET',
          headers: {
            Accept: 'application/json',
            'User-Agent': 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            Referer: 'https://sokkerpro.com/',
            Origin: 'https://sokkerpro.com',
          },
          signal: AbortSignal.timeout(this.timeout),
        });

        if (!response.ok) {
          const text = await response.text().catch(() => '');
          throw new Error(`HTTP ${response.status}: ${response.statusText} - ${text.slice(0, 200)}`);
        }

        return response.json() as Promise<FixturesResponse>;
      },
      {
        maxRetries: getEnv().SCRAPER_MAX_RETRIES,
        delayMs: getEnv().SCRAPER_DELAY_MIN_MS,
        onRetry: (attempt, error) => {
          logger.warn({ attempt, error: error.message }, 'Retrying fixtures fetch');
        },
      },
    );

    logger.info(
      { totalFixtures: data.data?.fixtures_total, leagues: data.data?.sortedCategorizedFixtures?.length },
      'Fixtures fetched',
    );

    return data;
  }

  /**
   * Fetch detailed fixture data.
   * @param fixtureId - The fixture ID
   */
  async getFixtureDetail(fixtureId: string): Promise<FixtureDetailResponse> {
    const logger = getLogger();
    const url = `${this.baseUrl}/fixture/${fixtureId}`;

    logger.debug({ fixtureId, url }, 'Fetching fixture detail');

    const data = await retry(
      async () => {
        const response = await fetch(url, {
          method: 'GET',
          headers: {
            Accept: 'application/json',
            'User-Agent': 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            Referer: 'https://sokkerpro.com/',
            Origin: 'https://sokkerpro.com',
          },
          signal: AbortSignal.timeout(this.timeout),
        });

        if (!response.ok) {
          const text = await response.text().catch(() => '');
          const err = new Error(`HTTP ${response.status}: ${response.statusText} - ${text.slice(0, 200)}`);
          (err as any).status = response.status;
          throw err;
        }

        return response.json() as Promise<FixtureDetailResponse>;
      },
      {
        maxRetries: getEnv().SCRAPER_MAX_RETRIES,
        delayMs: getEnv().SCRAPER_DELAY_MIN_MS,
        onRetry: (attempt, error) => {
          logger.warn({ fixtureId, attempt, error: error.message }, 'Retrying fixture detail fetch');
        },
        shouldRetry: (error) => {
          const status = (error as any).status;
          return status !== 404;
        },
      },
    );

    return data;
  }

  /**
   * Fetch X7 predictions for a fixture.
   * @param fixtureId - The fixture ID
   */
  async getFixtureX7(fixtureId: string): Promise<X7Response> {
    const logger = getLogger();
    const url = `${this.baseUrl}/fixture/${fixtureId}/x7`;

    logger.debug({ fixtureId, url }, 'Fetching fixture X7 predictions');

    const response = await fetch(url, {
      method: 'GET',
      headers: {
        Accept: 'application/json',
        'User-Agent': 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
        Referer: 'https://sokkerpro.com/',
        Origin: 'https://sokkerpro.com',
      },
      signal: AbortSignal.timeout(this.timeout),
    });

    if (!response.ok) {
      // 404 means no X7 data for this fixture - don't retry
      throw new Error(`X7 not available (HTTP ${response.status})`);
    }

    return response.json() as Promise<X7Response>;
  }
}
