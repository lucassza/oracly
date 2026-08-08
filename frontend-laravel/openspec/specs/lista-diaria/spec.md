# Lista diária

## Purpose

Reproduzir a aba “Lista diária” do dashboard atual (`src/dashboard/index.html`), com picks do dia e navegação temporal.

## Requirements

### Requirement: Visualização dos picks do dia

O sistema SHALL exibir a lista diária de picks com placar, mercados relevantes e ordenação por horário, no fuso America/Sao_Paulo.

#### Scenario: Carregar dia atual

- GIVEN usuário autenticado
- WHEN abre “Lista diária”
- THEN vê os picks do dia civil em Brasília

### Requirement: Navegação por dia

O sistema SHALL permitir navegar para o dia anterior e o dia seguinte.

#### Scenario: Ir para ontem

- GIVEN a lista do dia D
- WHEN o usuário escolhe o dia anterior
- THEN a lista carrega os dados de D-1

### Requirement: Atualizar unsettled

O sistema SHALL oferecer ação de atualizar partidas ainda não resolvidas do dia selecionado, sem alterar scrapers — apenas consumindo o mesmo contrato de dados já usado pelo dashboard Node (`/api/daily-picks`, `POST /api/daily-picks/refresh` ou leitura equivalente no Postgres).

#### Scenario: Refresh

- GIVEN partidas unsettled no dia
- WHEN o usuário dispara atualizar
- THEN a lista é recarregada com o estado mais recente
