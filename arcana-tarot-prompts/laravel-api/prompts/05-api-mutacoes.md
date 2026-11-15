# 05 — Endpoints de escrita

Leia ANALISE/STATUS e reutilize Services/Actions da etapa 01. Implemente as mutações API necessárias: iniciar/aceitar/encerrar consulta conforme papel e fluxo atual; enviar mensagem textual/carta; atualizar status permitido do atendente; iniciar solicitação de compra/checkout apenas pelo mecanismo atual.

Regras obrigatórias:
- Nunca confiar em preço, saldo, comissão, duração, status ou IDs de terceiros enviados pelo app.
- Validar papel, disponibilidade, ownership e transições de estado no servidor.
- Operações financeiras/consulta concorrentes devem usar transações, locks e idempotência conforme implementação atual.
- Não duplicar ledger, cobrança, webhook ou integração InfinitePay. Não marcar compra como paga pelo retorno do app.
- Persistir mensagens/cartas pelo formato atual e preservar histórico.
- Respostas JSON e erros HTTP consistentes.

Não criar checkout incompatível com políticas das lojas; apenas expor o fluxo web/provedor existente de forma segura quando aplicável. Testar duplicidade, concorrência, autorização, saldo insuficiente e estados inválidos. Atualize ENDPOINTS.md.
