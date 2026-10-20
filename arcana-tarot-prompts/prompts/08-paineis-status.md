# Prompt 08 — Painéis e status dos usuários

Implemente os painéis mínimos do cliente, atendente e administrador.

## Cliente
- Resumo de saldo.
- Botão para comprar créditos (pode apontar para página ainda em construção até o prompt de pagamentos).
- Atendentes disponíveis.
- Consulta atual, se existir.
- Histórico simples de consultas e recargas.

## Atendente
- Perfil profissional básico.
- Alternar entre online e offline.
- Solicitações de atendimento pendentes.
- Consulta ativa, se existir.
- Histórico das próprias consultas.
- Resumo de ganhos/comissões calculados pelo servidor, incluindo a porcentagem aplicada às consultas históricas; não permitir edição manual dos ganhos.

## Estados do atendente
- `offline`: não aceita novas consultas.
- `online`: elegível para receber solicitação.
- `occupied`: possui consulta ativa; derivado/garantido pelo servidor.
- O atendente não pode marcar-se manualmente como ocupado sem sessão ativa.
- O servidor não deve permitir duas consultas ativas para o mesmo atendente.

## Administrador
- Indicadores básicos.
- Listagem/gestão de clientes e atendentes.
- Links para consultas, financeiro, candidaturas de **Trabalhe conosco** e o módulo **Baralhos e cartas de Tarot**, mesmo que os módulos ainda estejam em evolução. O CRUD do catálogo será implementado no Prompt 09. Mostrar contador de candidaturas pendentes quando houver.

Não permitir edição arbitrária de saldo nem de comissão acumulada nesta etapa. A taxa percentual individual será configurada pelo administrador no módulo financeiro definido no Prompt 16. Use Policies e testes para todos os acessos. Documente transições de status.
