import { setTimeout as sleep } from 'node:timers/promises';

export interface RetryOptions {
  maxRetries: number;
  delayMs: number;
  backoffMultiplier?: number;
  onRetry?: (attempt: number, error: Error) => void;
  shouldRetry?: (error: Error) => boolean;
}

export async function retry<T>(fn: () => Promise<T>, options: RetryOptions): Promise<T> {
  const { maxRetries, delayMs, backoffMultiplier = 2, onRetry, shouldRetry } = options;

  let lastError: Error | null = null;

  for (let attempt = 0; attempt <= maxRetries; attempt++) {
    try {
      return await fn();
    } catch (error) {
      lastError = error instanceof Error ? error : new Error(String(error));

      if (attempt < maxRetries && (!shouldRetry || shouldRetry(lastError))) {
        onRetry?.(attempt + 1, lastError);
        const delay = delayMs * Math.pow(backoffMultiplier, attempt);
        await sleep(delay);
      }
    }
  }

  throw lastError;
}
