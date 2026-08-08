# Favoritos

## Purpose

Reproduzir a aba “Favoritos”: seleção de países e ligas compartilhada entre dispositivos via Postgres.

## Requirements

### Requirement: Listar países e ligas conhecidas

O sistema SHALL listar países/ligas conhecidas (equivalente a `/api/leagues`) com busca e indicação de divisão/top flight quando disponível.

#### Scenario: Buscar liga

- GIVEN a tela Favoritos
- WHEN o usuário digita um termo
- THEN a lista filtra países/ligas correspondentes

### Requirement: Persistência compartilhada

O sistema SHALL ler e gravar favoritos no Postgres Oracly (contrato `/api/favorites`), não em localStorage como fonte primária.

#### Scenario: Sincronizar entre navegadores

- GIVEN favoritos salvos no Postgres
- WHEN outro navegador abre Favoritos
- THEN vê o mesmo conjunto de países e ligas

### Requirement: Chave país+liga

O sistema SHALL identificar ligas por `país::competição`, não apenas pelo nome da competição.

#### Scenario: Homônimos

- GIVEN duas ligas com o mesmo nome em países diferentes
- WHEN apenas uma é favoritada
- THEN a outra permanece não favoritada
