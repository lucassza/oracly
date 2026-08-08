# Auth

## Purpose

Garantir acesso autenticado ao painel Filament Oracly V2 com e-mail e senha.

## Requirements

### Requirement: Login com e-mail e senha

O sistema SHALL exigir autenticação Filament em `/admin/login` antes de exibir qualquer tela do dashboard.

#### Scenario: Login bem-sucedido

- GIVEN um usuário cadastrado com e-mail e senha válidos
- WHEN ele autentica em `/admin/login`
- THEN ele acessa o painel em `/admin` e as páginas do dashboard

#### Scenario: Credenciais inválidas

- GIVEN credenciais incorretas
- WHEN o usuário tenta autenticar
- THEN o acesso é negado e nenhuma tela do dashboard é exibida

### Requirement: Sessão protegida

O sistema SHALL proteger todas as páginas Filament do grupo Dashboard com middleware de autenticação.

#### Scenario: Acesso anônimo

- GIVEN um visitante sem sessão
- WHEN ele acessa `/admin` ou qualquer página do dashboard
- THEN é redirecionado para `/admin/login`
