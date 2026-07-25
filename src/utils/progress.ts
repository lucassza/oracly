import { writeFileSync } from 'node:fs';

export class ProgressBar {
  private total: number;
  private current = 0;
  private startTime: number;
  private lastRender = 0;

  constructor(total: number) {
    this.total = total;
    this.startTime = Date.now();
  }

  update(increment: number): void {
    this.current += increment;
    this.render();
  }

  private render(): void {
    const now = Date.now();
    // Throttle rendering to max once every 100ms
    if (now - this.lastRender < 100 && this.current < this.total) return;
    this.lastRender = now;

    const pct = Math.floor((this.current / this.total) * 100);
    const barWidth = 30;
    const filled = Math.floor((this.current / this.total) * barWidth);
    const empty = barWidth - filled;
    const bar = '\u2588'.repeat(filled) + '\u2591'.repeat(empty);

    const elapsed = (now - this.startTime) / 1000;
    const rate = this.current / elapsed;
    const remaining = rate > 0 ? (this.total - this.current) / rate : 0;

    const elapsedStr = this.formatTime(elapsed);
    const remainingStr = this.current >= this.total ? '' : ` | ETA: ${this.formatTime(remaining)}`;

    const line = `\r  ${bar} ${pct}% (${this.current}/${this.total}) | ${elapsedStr}${remainingStr}   `;
    process.stderr.write(line);

    if (this.current >= this.total) {
      process.stderr.write('\n');
    }
  }

  private formatTime(seconds: number): string {
    if (seconds < 60) return `${Math.round(seconds)}s`;
    const m = Math.floor(seconds / 60);
    const s = Math.round(seconds % 60);
    return `${m}m${s}s`;
  }
}
