# Prompt 04 — Autenticação, papéis e autorização

Implemente autenticação adequada ao MVP e autorização no servidor.

## Papéis
- `admin`
- `attendant`
- `client`

## Requisitos
- Cadastro/login/logout e recuperação de senha conforme o scaffolding escolhido.
- Validar dados no servidor.
- Aplicar middleware, Policies/Gates ou mecanismo equivalente para cada área.
- Administrador gerencia usuários e configurações permitidas.
- Atendente acessa apenas seu perfil, suas consultas e seus ganhos.
- Cliente acessa apenas seu perfil, saldo, recargas, consultas e mensagens.
- Não confiar em IDs enviados pelo navegador sem verificar autorização.
- Bloquear cadastro público de administrador; criar admin inicial somente por seeder seguro ou comando documentado.
- Criar testes negativos de acesso cruzado entre clientes/atendentes.
- Não implementar login social nem autenticação de dois fatores nesta etapa, salvo se já houver suporte existente e simples.

## Interface
Criar redirecionamento após login conforme o papel, com páginas provisórias se os painéis ainda não existirem.

## Critérios
Testes comprovam autenticação, acesso autorizado e negação de acesso indevido. Documente como criar o primeiro administrador sem senha hardcoded no repositório.
