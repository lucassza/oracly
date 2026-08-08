## ADDED Requirements

### Requirement: Acesso direto ao Postgres Oracly

A aplicação Laravel SHALL obter dados de jogos, previsões e favoritos exclusivamente via conexão Postgres `oracly`, sem chamar a API HTTP do dashboard Node.

#### Scenario: Leitura sem Node

- GIVEN o dashboard Node fora do ar
- WHEN o usuário autenticado abre Lista diária / Hoje / Predictions / 1º Tempo / Favoritos
- THEN os dados são carregados do Postgres Oracly

### Requirement: Sem alteração de schema

A aplicação SHALL NOT criar migrations que alterem `match_snapshots` ou o schema de jogos. Escrita permitida apenas nas tabelas de favoritos já existentes (`favorite_countries`, `favorite_leagues`).

#### Scenario: Favoritar liga

- GIVEN uma liga listada
- WHEN o usuário marca favorito
- THEN o registro é persistido em `favorite_leagues` sem alterar outras tabelas
