# Over 0.5 / 1.5

## Purpose

Reproduzir a aba “Over 0.5 / 1.5” com mercados HT/FT, histórico, combos e recomendação de melhor filtro.

## Requirements

### Requirement: Mercados disponíveis

O sistema SHALL oferecer os mercados presentes no dashboard atual: Over 0.5 HT, Over 1.5 HT, Over 0.5 FT, Over 1.5 FT e Under 3.5 FT.

#### Scenario: Trocar mercado

- GIVEN a tela de previsões
- WHEN o usuário seleciona outro mercado
- THEN upcoming e histórico passam a usar o mercado escolhido

### Requirement: Modos upcoming e histórico

O sistema SHALL permitir alternar entre previsões futuras e histórico de acerto, com limiar de probabilidade configurável.

#### Scenario: Filtrar por probabilidade

- GIVEN modo upcoming e limiar 80
- WHEN a lista é carregada
- THEN apenas previsões com probabilidade ≥ 80 são exibidas

### Requirement: Combos e melhor filtro

O sistema SHALL preservar a lógica de combos e destaque de melhor filtro já validada no dashboard Node (ex.: combo FT90+BTTS60 no Over 0.5 HT).

#### Scenario: Aplicar combo

- GIVEN mercado Over 0.5 HT
- WHEN o usuário ativa um combo definido
- THEN a lista e as taxas refletem o filtro do combo
