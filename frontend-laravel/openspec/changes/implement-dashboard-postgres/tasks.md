## 1. Infra Oracly

- [x] 1.1 Confirmar conexão `oracly` e schema via `ORACLY_DB_*`
- [x] 1.2 Criar `App\Oracly\Support\BrasiliaDate` e `LeagueFilter`
- [x] 1.3 Criar `MatchSnapshotRepository` (latest + all snapshots)
- [x] 1.4 Criar services: DailyPicks, TodayMatches, Favorites, Predictions, HalfTimeExclusion (+ port pickExclusion)

## 2. Filament

- [x] 2.1 Implementar `DailyList` (dia, paginação, tabela, reload)
- [x] 2.2 Implementar `TodayMatches`
- [x] 2.3 Implementar `Favorites` (toggle país/liga)
- [x] 2.4 Implementar `Predictions` (mercados + limiar + upcoming/histórico)
- [x] 2.5 Implementar `HalfTimeExclusion` (filtros de acordo)

## 3. Docs e validação

- [x] 3.1 Atualizar `docs/v2/README.md` (Postgres direto, sem Node API)
- [x] 3.2 Smoke test nas rotas `/admin/*` via Docker
