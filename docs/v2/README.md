# Oracly V2 — Frontend Laravel + Filament

Pasta de desenvolvimento da V2 do frontend. **Não altera scrapers Node nem o schema do banco de jogos.**

## Stack

| Camada | Tecnologia |
|--------|------------|
| Runtime | **Docker** (PHP só no container — não instalar PHP no host) |
| App | Laravel 13 + PHP 8.4 |
| UI/admin | Filament 4 (login e-mail/senha) |
| Specs | OpenSpec (`openspec/`) |
| Auth/sessão Laravel | SQLite no volume do app |
| Dados de jogos | Postgres Oracly — **somente leitura** (conexão `oracly`) |

## Telas (paridade com o dashboard atual)

| Aba atual | Página Filament | Rota |
|-----------|-----------------|------|
| Lista diária | `DailyList` | `/admin/daily-list` |
| Hoje | `TodayMatches` | `/admin/today-matches` |
| Over 0.5 / 1.5 | `Predictions` | `/admin/predictions` |
| 1º Tempo | `HalfTimeExclusion` | `/admin/half-time-exclusion` |
| Favoritos | `Favorites` | `/admin/favorites` |

Fonte de verdade das telas legadas: `src/dashboard/index.html` + APIs em `src/cli/run.ts`.

## OpenSpec

Specs em `frontend-laravel/openspec/specs/`. No Cursor (após reload):

- `/opsx-propose` — propor uma change
- `/opsx-apply` — implementar
- `/opsx-archive` — arquivar e sincronizar specs

## Pré-requisito

Docker Engine + Compose plugin no host. **Não use PHP/Composer instalados no sistema** — o `Dockerfile` traz tudo.

```bash
sudo apt-get update
sudo apt-get install -y docker.io docker-compose-plugin
sudo usermod -aG docker "$USER"
# faça logout/login para o grupo docker valer
```

## Setup com Docker

```bash
cd frontend-laravel
cp .env.example .env   # se ainda não existir

# Configure ORACLY_DB_* no .env (Postgres de jogos).
# A app lê o banco DIRETO — não usa a API Node.

docker compose build
docker compose up -d
```

Painel: http://192.168.15.18:8000/admin/login

Usuário criado automaticamente no primeiro boot:

- E-mail: `admin@oracly.local`
- Senha: `oraclyadmin` (**trocar em produção**)

## Performance (índices)

Índices no schema `sokkerpro` **sem apagar dados**:

```bash
docker compose exec app php artisan oracly:optimize-indexes
```

SQL fonte: `database/oracly/optimize_indexes.sql`.

## Fora de escopo da V2 frontend

- Alterar workers/scrapers (`src/services/*`, automations)
- Migrations no schema de `match_snapshots` / ligas do Oracly
- Instalar PHP/Composer no host
- Proxy HTTP para o dashboard Node

## Documentação relacionada

- [README raiz](../README.md) — scraper e Docker atuais
- Specs OpenSpec em `../frontend-laravel/openspec/specs/`
