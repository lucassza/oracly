# SokkerPRO Scraper

Scraper desacoplado para coletar jogos e estatísticas do site [SokkerPRO](https://sokkerpro.com).

## Funcionalidades

- Coleta automática de jogos do dia
- Extração de detalhes de cada partida (odds, estatísticas, H2H)
- Normalização de dados com validação Zod
- Exportação em JSON com escrita atômica
- Logs estruturados com Pino
- Retentativas com backoff exponencial
- Suporte a Docker

## Descobertas Técnicas

O SokkerPRO é um SPA (Vue.js) que utiliza uma API interna em `m2.sokkerpro.com`.

**Endpoints públicos (sem autenticação):**
| Endpoint | Descrição |
|---|---|
| `GET /home/fixtures/{date}/{timezone}` | Lista de jogos do dia |
| `GET /fixture/{id}` | Detalhes da partida |
| `GET /fixture/{id}/x7` | Previsões/picks |
| `GET /fixture/{id}/comments` | Comentários |
| `GET /fixture/{id}/lineups` | Escalações |

A API retorna dados completos incluindo odds (BET365/XBET), estatísticas, H2H e probabilidades.

## Requisitos

- Node.js 22+
- npm

## Instalação

```bash
npm install
```

## Configuração

Copie o arquivo de exemplo e configure as variáveis:

```bash
cp env.example .env
```

Variáveis principais:

| Variável | Descrição | Padrão |
|---|---|---|
| `SCRAPER_DATE` | Data para coleta (YYYY-MM-DD) | Data atual |
| `SCRAPER_CONCURRENCY` | Requisições simultâneas | 2 |
| `SCRAPER_DELAY_MIN_MS` | Delay mínimo entre requisições | 1000ms |
| `SCRAPER_DELAY_MAX_MS` | Delay máximo entre requisições | 3000ms |
| `SCRAPER_MAX_RETRIES` | Número máximo de retentativas | 3 |
| `LOG_LEVEL` | Nível de log | info |
| `OUTPUT_PATH` | Diretório de saída | storage/output |

## Uso

### Coletar jogos de hoje

```bash
npm run scrape
```

### Coletar jogos de uma data específica

```bash
npm run scrape -- --date=2026-07-24
```

### Inspecionar a API

```bash
npm run inspect
npm run inspect -- --date=2026-07-24
```

### Validar conectividade

```bash
npm run session:validate
```

### Criar sessão (não necessário)

```bash
npm run session:create
```

> A API do SokkerPRO é pública e não requer autenticação para dados de jogos.

## Docker

### Construir

```bash
docker compose build
```

### Executar

```bash
docker compose run --rm scraper
docker compose run --rm scraper scrape --date=2026-07-24
```

### Validar

```bash
docker compose run --rm scraper session:validate
```

## Estrutura de Saída

Os resultados são salvos em `storage/output/`:

```json
{
  "runId": "uuid",
  "requestedDate": "2026-07-24",
  "startedAt": "2026-07-24T09:00:00.000Z",
  "finishedAt": "2026-07-24T09:03:12.000Z",
  "status": "completed",
  "summary": {
    "matchesFound": 454,
    "matchesProcessed": 454,
    "matchesFailed": 0
  },
  "matches": [...]
}
```

## Dados Normalizados

Cada partida contém:

```typescript
{
  provider: "sokkerpro",
  providerMatchId: string,
  sourceUrl: string,
  collectedAt: string,
  matchDate: string,
  kickoffAt: string,
  timezone: string,
  country: string,
  competition: string,
  round: string,
  homeTeam: { name: string, providerId: string },
  awayTeam: { name: string, providerId: string },
  status: string,
  score: { home: number, away: number, halftimeHome: number, halftimeAway: number },
  odds: { home: number, draw: number, away: number, bookmaker: string },
  statistics: { ... }
}
```

## Testes

```bash
npm test
npm run test:watch
```

## Validação

```bash
npm run typecheck
npm run lint
npm run format
```

## Tratamento de Erros

- Falhas individuais não interrompem a execução
- Retentativas automáticas para erros temporários
- Screenshots e HTML salvos para depuração
- Status `partially_completed` quando há falhas parciais

## Limitações

- A API pode ter rate limiting (delays configuráveis)
- Dados em tempo real dependem do status do jogo
- Alguns campos podem não estar disponíveis para todos os jogos

## Termos de Uso

Este scraper respeita os termos de uso do SokkerPRO:
- Utiliza endpoints públicos
- Não contorna autenticação
- Não realiza carga excessiva
- Utiliza delays entre requisições
- Não armazena credenciais

## Licença

Uso interno.
