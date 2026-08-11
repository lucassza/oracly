-- Oracly / schema sokkerpro — índices somente (não apaga nem altera dados).
-- Idempotente: seguro rodar mais de uma vez.

CREATE INDEX IF NOT EXISTS match_snapshots_kickoff_at
  ON sokkerpro.match_snapshots (kickoff_at);

CREATE INDEX IF NOT EXISTS match_snapshots_status_kickoff_desc
  ON sokkerpro.match_snapshots (status, kickoff_at DESC NULLS LAST);

CREATE INDEX IF NOT EXISTS favorite_leagues_country
  ON sokkerpro.favorite_leagues (country);

CREATE INDEX IF NOT EXISTS leagues_country
  ON sokkerpro.leagues (country);
