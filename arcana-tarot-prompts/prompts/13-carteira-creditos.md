# Prompt 13 — Carteira e livro-razão de créditos

Implemente a carteira de créditos e os lançamentos financeiros internos, sem integração com provedor real nesta etapa.

## Requisitos
- Saldo derivado de lançamentos ou mantido como projeção consistente com livro-razão.
- Lançamentos imutáveis de crédito e débito.
- Cada lançamento possui tipo, valor em centavos, usuário, referência e identificador idempotente.
- Separar créditos comprados de bônus somente se o MVP realmente precisar; por padrão, não implementar bônus.
- Histórico de movimentações para o próprio cliente.
- Visão administrativa protegida.
- Ajustes administrativos somente por operação específica, com motivo obrigatório, autor e auditoria.
- Não permitir saldo negativo.
- Usar transações e bloqueio de linha/estratégia equivalente para impedir gasto concorrente.
- Não permitir que o cliente envie o saldo ou valor do débito.
- Nunca usar float para dinheiro.

## Testes
Cobrir crédito, débito, saldo insuficiente, duas operações simultâneas, idempotência, histórico e autorização. Documentar invariantes financeiras.
