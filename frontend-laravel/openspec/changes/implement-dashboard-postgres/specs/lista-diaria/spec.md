## MODIFIED Requirements

### Requirement: Visualização dos picks do dia

O sistema SHALL exibir a lista diária de picks lendo agregações do Postgres Oracly (equivalente a `getDailyPicks`), com placar, mercados e ordenação por horário no fuso America/Sao_Paulo.

#### Scenario: Carregar dia atual

- GIVEN usuário autenticado e Postgres configurado
- WHEN abre “Lista diária”
- THEN vê os picks do dia civil em Brasília obtidos do banco

### Requirement: Atualizar visualização

O sistema SHALL permitir recarregar a lista a partir do banco. NÃO SHALL disparar scrape via API Node.

#### Scenario: Recarregar

- GIVEN a lista aberta
- WHEN o usuário dispara atualizar
- THEN os dados são relidos do Postgres
