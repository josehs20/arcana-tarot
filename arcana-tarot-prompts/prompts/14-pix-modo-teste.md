# Prompt 14 — Integração PIX em modo de teste

Prepare a integração de pagamentos de créditos por PIX. Antes de codificar, inspecione provedores compatíveis e a configuração existente. Não escolha nem invente credenciais ou endpoints.

## Requisitos
- Isolar o provedor por interface/serviço para permitir troca futura.
- Se o provedor ainda não foi escolhido, implementar contrato e fluxo de sandbox/mock, documentando claramente que não recebe dinheiro real.
- Criar pedido de recarga com valor em centavos, status, usuário, referência externa e chave idempotente.
- Mostrar ao cliente status pendente e instruções retornadas pelo provedor/mock.
- Confirmar recarga somente após webhook autenticado/validado ou consulta server-to-server conforme o provedor.
- Validar assinatura/autenticidade do webhook e proteção contra replay conforme documentação do provedor.
- Processar evento repetido sem duplicar créditos.
- Nunca confiar no redirect do navegador para marcar pagamento como pago.
- Registrar transições de estado e falhas sem armazenar dados sensíveis desnecessários.
- Não ativar cobrança real nem usar credenciais reais no código.

## Entrega
Documente como configurar sandbox, estados do pagamento, fluxo de webhook e como trocar para produção com segurança. Crie testes de pagamento pendente, aprovado, recusado e webhook repetido.
