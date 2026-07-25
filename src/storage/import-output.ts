import { readFile, readdir } from 'node:fs/promises';
import { join } from 'node:path';
import type { NormalizedMatch } from '../types/schemas.js';
import { SqliteMatchStore } from './sqlite-store.js';

interface StoredOutput {
  matches?: NormalizedMatch[];
}

export async function importOutputDirectory(store: SqliteMatchStore, outputPath: string): Promise<number> {
  const files = await readdir(outputPath);
  const outputFiles = files.filter((file) => file.endsWith('.json'));
  const outputs = await Promise.all(outputFiles.map(async (file) => {
    const content = await readFile(join(outputPath, file), 'utf-8');
    return JSON.parse(content) as StoredOutput;
  }));

  const matches = outputs.flatMap((output) => output.matches ?? []);
  store.saveMatches(matches);
  return matches.length;
}
