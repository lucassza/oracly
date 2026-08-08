# Hoje

## Purpose

Reproduzir a aba “Hoje” do dashboard atual: jogos do dia com mercados e refresh em tempo quase real.

## Requirements

### Requirement: Lista de jogos de hoje

O sistema SHALL listar jogos do dia (Brasília) com horário, placar, status e chips de mercados, equivalente a `/api/today`.

#### Scenario: Carregar jogos

- GIVEN usuário autenticado
- WHEN abre “Hoje”
- THEN vê os jogos do dia civil atual em Brasília

### Requirement: Refresh

O sistema SHALL permitir atualizar a lista do dia sem modificar workers/scrapers, consumindo o contrato existente (`POST /api/today/refresh` ou leitura Postgres equivalente).

#### Scenario: Atualizar

- GIVEN a tela “Hoje” aberta
- WHEN o usuário dispara refresh
- THEN os dados exibidos refletem a última coleta disponível
