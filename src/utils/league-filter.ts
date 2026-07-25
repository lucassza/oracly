// Keeps only "principais ligas": excludes youth/age-grade, women's, friendlies,
// amateur/reserve squads, and semi-amateur regional/lower-tier competitions.
//
// Heuristic, keyword-based against `leagueName`/`competition` strings from the
// SokkerPRO API — there is no explicit tier/gender field to rely on. Known
// women's leagues that don't carry a "women"-style keyword in their name are
// listed explicitly in EXPLICIT_EXCLUDED_LEAGUES; extend that set as new ones
// are found in scraped data.

const EXCLUDE_PATTERNS: RegExp[] = [
  // Youth / age-grade
  /\bu-?1[6-9]\b/i,
  /\bu-?2[0-3]\b/i,
  /\byouth\b/i,
  /\bjunior\b/i,
  /\bcotif\b/i,

  // Women's football
  /\bwomen'?s?\b/i,
  /\b(feminin|femenin)[oa]\b/i,
  /\bladies\b/i,

  // Friendlies
  /\bfriendl(y|ies)\b/i,

  // Amateur / reserve squads
  /\bamateur\b/i,
  /\breserves?\b/i,

  // Regional / state / semi-amateur lower-tier competitions
  /\bregionalliga\b/i,
  /\boberliga\b/i,
  /\bnpl\b/i,
  /\bregional leagues?\b/i,
  /\bcounties leagues?\b/i,
  /\bstate league\b/i,
  /\b(third|fourth|fifth)\s+division\b/i,
  /\b[3-9]\.\s?(division|liga|liiga|lyga|deild)\b/i,
  /\bkolmonen\b/i,
];

const EXPLICIT_EXCLUDED_LEAGUES = new Set(['damallsvenskan', 'nwsl']);

export function isMainLeague(competition: string | undefined): boolean {
  if (!competition) return true;
  const normalized = competition.trim().toLowerCase();
  if (EXPLICIT_EXCLUDED_LEAGUES.has(normalized)) return false;
  return !EXCLUDE_PATTERNS.some((pattern) => pattern.test(normalized));
}
