## MODIFIED Requirements

### Requirement: Persistência compartilhada

O sistema SHALL ler e gravar favoritos diretamente no Postgres Oracly (`favorite_countries` / `favorite_leagues`), sem API Node e sem localStorage como fonte da verdade.

#### Scenario: Sincronizar entre navegadores

- GIVEN favoritos salvos no Postgres
- WHEN outro navegador abre Favoritos na app Laravel
- THEN vê o mesmo conjunto
