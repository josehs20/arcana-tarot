# Prompt 16 — Percentual de comissão dos atendentes

Implemente a configuração por atendente e o cálculo auditável de comissão por consulta. Este módulo depende da cobrança por tempo (Prompt 15) e deve reutilizar o valor final oficial calculado pelo servidor. Inspecione primeiro a política de preços, a carteira, as sessões e os lançamentos existentes.

## Política de cálculo obrigatória
Antes de codificar, criar `docs/COMMISSION_POLICY.md` que descreva, em linguagem clara:
- Cada atendente tem uma taxa percentual definida pelo administrador. Se existir taxa padrão global, ela serve apenas como valor inicial; a taxa efetiva de cada atendente é explícita e editável por admin.
- No início oficial da consulta, grave no registro da sessão o snapshot do percentual então vigente. O cálculo ao finalizar deve ler esse snapshot, nunca o percentual atual do cadastro. Alterações futuras valem apenas para consultas iniciadas depois da alteração.
- A base é o valor monetário efetivamente cobrado do cliente pela consulta, em centavos, conforme calculado pelo módulo de cobrança; usar o valor líquido de estornos/reembolsos registrados, se esse recurso existir. Não usar o valor de recargas, o saldo remanescente ou a quantidade bruta de créditos como se fossem reais.
- Como a cobrança usa créditos pré-pagos, inspecione como créditos se relacionam com dinheiro. Se não houver conversão monetária documentada e consistente, não invente que 1 crédito equivale a R$ 1; documente a decisão necessária e impeça cálculo financeiro de produção até existir base confiável. Testes podem usar uma configuração controlada.
- Consulta recusada/cancelada antes de começar não gera comissão. Uma consulta que efetivamente começou e teve cobrança positiva gera comissão sobre o valor efetivamente cobrado, mesmo que termine como interrompida, conforme motivo e valores oficiais.
- A comissão é uma participação interna do atendente na receita do serviço. Não é pagamento automático, transferência bancária nem garantia de saque; pagamentos aos atendentes ficam fora do MVP.
- Definir arredondamento comercial para centavos; usar aritmética decimal/inteira, não `float`. Recomenda-se arredondamento half-up para o centavo mais próximo, documentando a fórmula usada.

## Configuração administrativa
- No cadastro/edição do perfil de atendente, permitir que somente admin configure o percentual entre `0.00` e `100.00`, com até duas casas decimais e validação server-side.
- Exibir a taxa atual no painel do atendente e a taxa histórica por consulta. Não revelar essa taxa aos clientes nem no perfil público.
- Toda alteração deve registrar administrador, horário, valor anterior e valor novo em trilha de auditoria. Não permitir edição direta dos ganhos acumulados.

## Lançamentos de comissão
- Criar ou adaptar um livro-razão imutável de comissões, vinculado à consulta e ao atendente. Registrar, no mínimo: tipo de lançamento, base em centavos, percentual snapshot, valor em centavos, referência à consulta, chave de idempotência, data e motivo/usuário responsável por ajuste quando aplicável.
- Uma consulta só pode gerar uma comissão original uma única vez, mesmo com retries, dois workers ou encerramento repetido. Use transações, chaves únicas e bloqueio apropriado.
- Reversões/correções devem ser novos lançamentos positivos de reversão/ajuste com tipo e motivo — nunca editar ou apagar silenciosamente o lançamento original.
- Se a estrutura atual já tiver conceito de comissão, reutilize-o em vez de duplicar tabelas. Não misture comissão com o saldo de créditos do cliente.
- Calcular a comissão sobre a base monetária final, usando aritmética precisa. Exemplo de referência de teste: base R$ 100,00 e taxa 25,00% resulta em R$ 25,00; a suíte também deve cobrir frações de centavo e arredondamento.

## Exibição e relatórios
- Atendente vê apenas as próprias consultas, taxa aplicada, base de cálculo, comissão ganha, reversões e total acumulado.
- Admin consegue filtrar comissões por atendente, período e consulta, visualizar a taxa e base históricas e comparar receita bruta de serviço, comissões e receita após comissões.
- Rotular indicadores corretamente: receita após comissão não equivale a lucro líquido, pois impostos, custos e taxas de pagamento podem não estar modelados.
- Não adicionar processamento de pagamento aos atendentes ou saque neste MVP.

## Testes obrigatórios
- Apenas admin altera percentuais; validar valores negativos, acima de 100, precisão indevida e manipulação via request.
- Alterar taxa não modifica consultas antigas nem consulta já iniciada.
- Cálculo correto e arredondamento para diversos valores/taxas.
- Nenhuma comissão em consulta que não começou ou não gerou cobrança.
- Consulta iniciada e encerrada/interrompida gera uma comissão compatível com o total realmente cobrado.
- Idempotência sob chamada repetida, retry e encerramento concorrente.
- Estorno/ajuste gera lançamento separado e auditado, se suportado pela estrutura financeira.
- Atendente A nunca acessa comissão do atendente B; cliente não vê taxa nem relatório de comissão.
- Dashboard e histórico usam a mesma fonte de dados sem duplicar totais.

Atualize migrations/models/policies/services/interfaces/documentação necessários sem apagar dados. Rode testes relevantes e informe qualquer bloqueio de conversão entre créditos e dinheiro. Não implementar pagamentos de comissão nem avançar para o prompt seguinte.