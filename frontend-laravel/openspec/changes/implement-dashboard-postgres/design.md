## Context

V2 Filament deve espelhar o dashboard em `src/dashboard/index.html` usando apenas Postgres. A lógica de agregação vive hoje em `src/storage/postgres-store.ts` e `src/analysis/half-time-exclusion.ts`.

## Goals / Non-Goals

**Goals**
- Portar consultas/agregações necessárias para PHP
- Telas Filament funcionais com login
- Favoritos compartilhados nas tabelas já existentes

**Non-Goals**
- API Node / proxy HTTP
- Alterar scrapers ou schema de `match_snapshots`
- Redesign visual pixel-perfect do HTML legado

## Decisions

1. **Fonte única = Postgres `oracly`** — Laravel `DB::connection('oracly')`.
2. **Sem Eloquent nas tabelas Oracly** — repositories + DTOs/arrays para não induzir migrations.
3. **Port da lógica TypeScript** — services PHP espelham `getDailyPicks`, `getTodayMatches`, predictions, HT exclusion, favorites.
4. **Refresh** = `redirect`/`$this->reset` Livewire (releitura do banco). Scrapers seguem no processo Node separado.
5. **Schema** = `ORACLY_DB_SCHEMA` (default `public`) com tabelas qualificáveis.

## Risks / Trade-offs

- Carregar muitos `match_json` em memória (igual ao Node) — aceitável na V1; otimizar depois com SQL se necessário.
- Divergência sutil vs Node se helpers não forem portados 1:1 — mitigar com testes unitários dos services críticos.

## Migration Plan

Nenhuma migration Oracly. Apenas código Laravel + env.
