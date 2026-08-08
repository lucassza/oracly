## MODIFIED Requirements

### Requirement: Lista de jogos de hoje

O sistema SHALL listar jogos do dia (Brasília) a partir do Postgres (`getTodayMatches` equivalente).

#### Scenario: Carregar jogos

- GIVEN usuário autenticado
- WHEN abre “Hoje”
- THEN vê jogos do dia civil atual lidos do banco

### Requirement: Recarregar do banco

O sistema SHALL recarregar do Postgres; NÃO SHALL chamar API Node de refresh.

#### Scenario: Atualizar

- GIVEN a tela “Hoje”
- WHEN o usuário dispara atualizar
- THEN a lista é relida do Postgres
