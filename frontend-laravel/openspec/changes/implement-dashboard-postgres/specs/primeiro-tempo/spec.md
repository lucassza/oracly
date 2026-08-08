## MODIFIED Requirements

### Requirement: Exclusão HT via Postgres

O sistema SHALL calcular exclusão de 1º tempo a partir dos snapshots no Postgres (Poisson + odds), sem API Node.

#### Scenario: Carregar modelo

- GIVEN jogos com médias de 1º tempo no banco
- WHEN abre “1º Tempo”
- THEN vê exclusões derivadas dos dados Postgres
