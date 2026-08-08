## MODIFIED Requirements

### Requirement: Mercados via Postgres

O sistema SHALL calcular upcoming e histórico dos mercados Over a partir de `match_snapshots.match_json` no Postgres, sem API Node.

#### Scenario: Listar upcoming

- GIVEN snapshots no banco
- WHEN o usuário abre Over 0.5 / 1.5 em modo upcoming
- THEN as previsões vêm do Postgres
