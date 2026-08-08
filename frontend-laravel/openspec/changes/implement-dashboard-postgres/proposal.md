## Why

O dashboard V2 no Filament precisa das mesmas telas do Node, mas a aplicação Laravel deve falar **direto com o Postgres Oracly** — sem depender da API HTTP do scraper/dashboard Node.

## What Changes

- Camada PHP de leitura (e escrita só em favoritos) sobre o Postgres já existente
- Implementação real das páginas Filament: Lista diária, Hoje, Over 0.5/1.5, 1º Tempo, Favoritos
- Remoção da ideia de proxy/refresh via API Node
- Botão “atualizar” nas telas apenas **recarrega do banco** (scrapers continuam no Node, fora desta app)

## Capabilities

### New Capabilities

- `oracly-postgres`: acesso à conexão `oracly` e serviços de domínio sobre `match_snapshots` / ligas / favoritos

### Modified Capabilities

- `lista-diaria`: dados via Postgres direto
- `hoje`: dados via Postgres direto
- `over-05-15`: dados via Postgres direto
- `primeiro-tempo`: dados via Postgres direto
- `favoritos`: leitura/escrita nas tabelas existentes via Postgres

## Impact

- Código novo em `app/Oracly/` e páginas Filament
- Sem migrations no schema Oracly
- Sem alterações em scrapers Node
- Dependência de `ORACLY_DB_*` no `.env` (já previsto)
