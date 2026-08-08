# 1º Tempo

## Purpose

Reproduzir a aba “1º Tempo” (exclusão de resultado no intervalo), com filtros por fontes e marcação de ligas favoritas.

## Requirements

### Requirement: Previsões e histórico HT exclusion

O sistema SHALL exibir upcoming e histórico da estratégia de exclusão de 1º tempo, equivalentes a `/api/predictions/ht-exclusion` e `/api/history/ht-exclusion`.

#### Scenario: Carregar modelo

- GIVEN usuário autenticado
- WHEN abre “1º Tempo”
- THEN vê previsões e/ou histórico com taxas por combinação de fontes

### Requirement: Filtro exato de fontes

O sistema SHALL permitir filtrar por combinações exatas de fontes (ex.: 3/3, 2/2, 2/3, 1/2, 1/3, 1/1), como no dashboard atual.

#### Scenario: Filtrar 2/2

- GIVEN histórico carregado
- WHEN o usuário seleciona o filtro 2/2
- THEN apenas entradas com acordo 2/2 são listadas

### Requirement: Favoritar liga na linha

O sistema SHALL permitir marcar/desmarcar liga como favorita a partir da linha, persistindo no Postgres compartilhado (sem localStorage como fonte da verdade).

#### Scenario: Marcar favorito

- GIVEN uma liga na lista
- WHEN o usuário marca favorito
- THEN o estado fica disponível em outros navegadores via `/api/favorites`
