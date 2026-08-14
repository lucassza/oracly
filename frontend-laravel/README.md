# Oracly V2 — Frontend Laravel

Frontend Filament com **Postgres direto** (sem API Node). Guia: [`docs/v2/README.md`](../docs/v2/README.md).

```bash
cd frontend-laravel
docker compose up -d --build
```

http://192.168.15.18:8000/admin/login — `admin@oracly.local` / `oraclyadmin`

## Experimento IA — contra 0x1 / 1x0

Configure `OPENROUTER_TOKEN` no `.env` e aplique a migration local do Laravel:

```bash
php artisan migrate
php artisan oracly:analyze-against-one-goal --dry-run
php artisan oracly:analyze-against-one-goal --limit=25 --threshold=75
```

O comando usa apenas dados pré-jogo, persiste cada resposta para evitar cobranças repetidas e compara as entradas da IA com o baseline de Poisson. Ele é experimental e não altera a estratégia exibida no painel.
