# Relatórios administrativos e definição dos indicadores

> Implementado nos Prompts 16 e 17. Este documento define exatamente como o
> dashboard, a lista de pagamentos, o relatório de comissões e a auditoria são
> calculados. Nenhum indicador é digitado manualmente.

## 1. Fontes oficiais

| Indicador | Fonte | Regra |
|---|---|---|
| Clientes | `users` | Usuários com papel `client`; o cartão mostra o total e quantos estão ativos. |
| Atendentes online | `attendant_profiles` | Perfis publicados, de usuários ativos, com `availability = online`. |
| Atendentes ocupados | `consultations` | Atendentes distintos com consulta em `active`. |
| Consultas em andamento | `consultations` | Status `active`. |
| Solicitações aguardando aceite | `consultations` | Status `requested`. |
| Consultas concluídas | `consultations` | Status `ended`. Canceladas, recusadas, expiradas e interrompidas não entram neste cartão. |
| Recargas confirmadas | `payments` | Quantidade e valor de pagamentos com status `confirmed`. |
| Candidaturas pendentes | `attendant_applications` | Status `pending`. Candidaturas em análise não entram neste cartão. |
| Receita bruta de consultas | `credit_ledger_entries` | Soma dos débitos cujo `reference_type` é `App\Models\Consultation`. |
| Comissões acumuladas | `commission_ledger_entries` | `accrual + adjustment − reversal`, sempre em centavos inteiros. |
| Receita operacional após comissões | Cálculo derivado | Receita bruta de consultas − comissões líquidas. |

## 2. O que cada valor significa

### Receita bruta de consultas

É o valor efetivamente debitado do cliente por consumo de consultas, em
centavos, conforme o livro-razão de créditos. Não é o valor de recargas, não é
saldo em carteira e não é a quantidade bruta de créditos.

Uma consulta ainda ativa pode já ter débitos e, portanto, aparecer na receita
bruta antes de gerar comissão. A comissão só é criada quando a consulta chega a
um estado final elegível (`ended` ou `interrupted`) e possui base positiva.

### Comissões acumuladas

As comissões são lançamentos internos do MVP:

```text
comissões líquidas = accruals + adjustments − reversals
```

- `accrual`: comissão original da consulta;
- `adjustment`: correção interna positiva;
- `reversal`: estorno integral de um accrual, persistido como valor positivo e
  subtraído pelo tipo.

Não existe carteira de comissão, transferência bancária, saque ou pagamento
automático ao atendente no MVP.

### Receita operacional após comissões

```text
receita operacional = receita bruta de consultas − comissões líquidas
```

**Este valor não é lucro líquido.** Impostos, custos operacionais, infraestrutura,
chargebacks e taxas de provedores de pagamento não estão modelados. O rótulo
"receita após comissões" deve ser mantido em qualquer relatório futuro.

## 3. Relatório de comissões

`/admin/comissoes` permite filtrar por atendente, consulta e período. Para o
conjunto filtrado, exibe:

- receita de serviço: soma das bases dos accruals;
- comissões: accruals + adjustments − reversals;
- estornos: soma dos lançamentos de reversão;
- receita após comissões: receita de serviço − comissões.

Cada linha mostra atendente, consulta, tipo, taxa congelada, base, valor com
sinal, motivo, autor e status de estorno. A taxa vem de
`consultations.commission_rate_bps` e de `commission_ledger_entries`; alterar a
taxa atual do perfil nunca recalcula o passado.

A visão do atendente (`/attendant/historico`) usa exatamente o mesmo razão, mas
é filtrada por `attendant_id` autenticado. O atendente vê comissões geradas,
estornos, líquido acumulado, base e taxa congelada, sem acesso ao relatório
global nem à edição de valores.

## 4. Pagamentos e auditoria

- `/admin/pagamentos` lista recargas com filtros de cliente/referência, status e
  período. Totais confirmados são recalculados sobre o filtro ativo. Conteúdo
  PIX, payload de webhook e credenciais não são exibidos.
- `/admin/auditoria` é somente leitura, exclusiva de administradores ativos. Os
  metadados são escapados na renderização e não incluem senhas, tokens ou
  conteúdo de conversa.
- Não existe interface administrativa para ler mensagens de consulta. Qualquer
  acesso excepcional futuro exigirá decisão própria, justificativa e trilha de
  auditoria (ver `docs/SECURITY_PRIVACY.md`).

## 5. Isolamento e paginação

- Históricos de cliente e atendente são paginados e sempre filtrados pelo ID da
  sessão; nunca aceitam um `user_id` do navegador.
- O valor consumido por consulta no histórico do cliente é lido dos débitos
  vinculados àquela consulta, não de cálculo no navegador.
- Filtros são validados no servidor (`Rule::enum`, datas e tamanho de busca).
- Todas as rotas novas estão sob `auth` + `role` e repetem a autorização no
  controller/policy.

## 6. Consultas úteis de conferência

As consultas abaixo são somente leitura e servem para conciliação manual. Não
alteram dados.

```sql
-- Receita bruta de consultas
SELECT COALESCE(SUM(amount_cents), 0)
FROM credit_ledger_entries
WHERE type = 'debit' AND reference_type = 'App\\Models\\Consultation';

-- Comissões líquidas
SELECT COALESCE(SUM(
  CASE WHEN type = 'reversal' THEN -commission_amount_cents ELSE commission_amount_cents END
), 0)
FROM commission_ledger_entries;
```

## 7. Limitações

- Não há consolidação contábil, fechamento mensal, impostos ou conciliação com
  extrato bancário.
- Não há pagamento real nem transferência ao atendente.
- Os indicadores são globais (sem filtro de período no dashboard). O relatório
  de comissões e as listas operacionais têm filtros próprios.
- A receita bruta inclui débitos de consultas ainda ativas; a comissão
  correspondente só aparece após o encerramento elegível.
