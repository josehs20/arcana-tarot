# Carteira e Livro-Razão de Créditos

> Implementado no **Prompt 13**. Este documento é a referência das invariantes
> financeiras do MVP. A recarga PIX em sandbox foi adicionada no Prompt 14 (ver
> `docs/PIX_SANDBOX.md`) e a cobrança por tempo no Prompt 15 (ver
> `docs/TIME_BILLING.md`). Comissões são etapa posterior.

## 1. Visão geral

A carteira é composta por dois níveis:

| Nível | Tabela | Papel |
|---|---|---|
| Livro-razão | `credit_ledger_entries` | Fonte de verdade imutável de cada crédito/débito. |
| Projeção | `wallets.balance_cents` | Cache do saldo, atualizado na mesma transação do lançamento. |

O cliente nunca envia saldo, valor de débito ou tipo de operação. A interface
do cliente permite ler o saldo/histórico e criar um pedido de recarga PIX em
sandbox; o crédito só é lançado após webhook autenticado do provedor. Débitos
vêm da cobrança por minuto da consulta, executada pelo servidor. Ajustes são
exclusivos do painel administrativo.

## 2. Estrutura dos lançamentos

Cada linha de `credit_ledger_entries` contém:

- `wallet_id`: carteira/usuário proprietário;
- `type`: `credit` ou `debit` (`App\Enums\LedgerEntryType`);
- `amount_cents`: valor positivo em centavos;
- `balance_after_cents`: fotografia do saldo após o lançamento;
- `reference_type`/`reference_id`: origem polimórfica (pagamento, consulta,
  auditoria de ajuste etc.);
- `reason`: motivo, obrigatório em ajustes administrativos;
- `created_by`: autor humano, quando houver;
- `idempotency_key`: identificador único da operação;
- `created_at`: único timestamp; não existe `updated_at`.

O saldo exibido é sempre derivado de operações server-side. Nenhum endpoint
aceita `balance_cents` do navegador.

## 3. Invariantes financeiras

1. **Dinheiro é inteiro.** Todos os valores usam centavos (`int`/BIGINT). Não há
   `float`, `double` ou `decimal` em cálculos de crédito.
2. **Todo saldo tem lastro.** `wallets.balance_cents` só muda dentro da mesma
   transação que cria um `credit_ledger_entry`.
3. **Projeção consistente.** Para qualquer carteira:
   `wallets.balance_cents = Σ créditos − Σ débitos`.
4. **Nenhum saldo negativo.** Débitos maiores que o saldo são recusados e não
   geram lançamento.
5. **Lançamentos imutáveis.** O model bloqueia `update` e `delete`; correções
   exigem um novo lançamento (crédito ou débito).
6. **Idempotência global.** `idempotency_key` é única. Repetir a mesma operação
   retorna o lançamento existente; reutilizar a chave com carteira, tipo ou
   valor diferente é recusado.
7. **Referência confiável.** `reference_type`/`reference_id` são definidos pelo
   servidor, nunca pelo cliente.
8. **Ajuste é operação separada.** Só administradores ativos ajustam carteiras,
   sempre com motivo, autor e auditoria.
9. **Ajustes não apagam histórico.** Um débito administrativo não remove o
   crédito original; ambos permanecem no extrato.
10. **Sem bônus no MVP.** Não há categoria separada de bônus nem crédito
    promocional automático. Ajustes de cortesia usam `credit` com motivo e
    auditoria, mantendo o ledger simples.

## 4. Operações do domínio

`App\Support\CreditWalletManager` centraliza as operações:

| Método | Uso | Regras |
|---|---|---|
| `walletFor(User)` | Obtém/cria carteira vazia | Uma carteira por usuário. |
| `credit(User, cents, key, ref)` | Entrada de crédito | Valor > 0; referência persistida obrigatória; idempotente. |
| `debit(User, cents, key, ref, reason?)` | Saída de crédito | Valor > 0; referência persistida obrigatória; idempotente; recusa saldo insuficiente; `reason` opcional para descrever a origem. |
| `adjust(target, actor, type, cents, reason, key)` | Ajuste administrativo | Ator admin ativo; alvo cliente; motivo obrigatório; auditoria. |
| `ledgerBalance(Wallet)` | Conferência | Soma o ledger, sem usar o cache. |

### 4.1 Idempotência

A chave é verificada **depois** do bloqueio da carteira. Se já existir:

- mesma carteira + tipo + valor → retorna o lançamento existente;
- qualquer divergência → `DomainException`, sem alterar saldo.

Todas as operações usam chaves determinísticas: `payment:{payment_id}`,
`consultation:{id}:minute:{n}` (cobrança por tempo) ou
`admin_adjustment:{user_id}:{operation_uuid}`.

### 4.2 Concorrência

O fluxo de débito executa:

1. `DB::transaction`;
2. `SELECT ... FOR UPDATE` na linha da carteira;
3. verificação de idempotência;
4. `UPDATE wallets SET balance_cents = balance_cents - ? WHERE id = ? AND balance_cents >= ?`;
5. criação do lançamento com `balance_after_cents`.

O `UPDATE` condicional é a barreira final contra saldo negativo e funciona mesmo
em bancos de teste onde `lockForUpdate` é no-op (SQLite). Em MySQL, o lock
serializa operações concorrentes na mesma carteira.

### 4.3 Cobrança por tempo

`App\Support\ConsultationBillingManager` debita minutos de consulta:

- tarifa = `price_per_minute_cents` do atendente para a modalidade, congelada no
  aceite;
- cada minuto iniciado gera **um** débito inteiro com a chave
  `consultation:{id}:minute:{n}`;
- `consultations.billed_minutes` é o cursor, atualizado na mesma transação;
- não existe débito parcial nem saldo negativo; sem saldo, a consulta é
  encerrada como `out_of_credits`;
- repetir comando/encerramento não duplica lançamentos;
- consulta finalizada não gera novos débitos.

A rotina detalhada, incluindo primeiro/último minuto e política de
desconexão, está em `docs/TIME_BILLING.md`.

## 5. Ajustes administrativos

- Rota: `POST /admin/financeiro/carteiras/{user}/ajustes`.
- Autorização: `role:admin` + `WalletPolicy::adjust` (admin ativo).
- Validação: tipo `credit|debit`, valor inteiro positivo em centavos, motivo de
  5 a 500 caracteres e `operation_id` UUID.
- Efeitos na mesma transação:
  - atualização da projeção de saldo;
  - `credit_ledger_entry` com `reason`, `created_by` e referência ao
    `audit_logs`;
  - `audit_logs.action = wallet.adjustment` com ator, valores antes/depois,
    motivo e chave idempotente.
- Reenviar o mesmo formulário (mesmo `operation_id`) não duplica saldo,
  lançamento nem auditoria.

## 6. Interface

- **Cliente**: `/creditos/comprar` (rota `client.credits.index`) exibe saldo e
  histórico paginado apenas da própria carteira. A rota é `GET`; não existe
  endpoint de escrita do cliente para saldo/débito.
- **Admin**: `/admin/financeiro` lista o livro-razão com filtros por cliente e
  tipo, mostra o valor do crédito e o saldo mínimo e permite editá-los
  (`admin.finance.pricing.update`, auditada); os preços por minuto de cada
  atendente ficam em `/admin/modalidades`
  (`admin.modalities.update`). `/admin/financeiro/carteiras/{user}` mostra a
  carteira individual e o formulário de ajuste.
- Valores são exibidos com `App\Support\Money`; o formulário administrativo
  trabalha explicitamente com **centavos inteiros**.

## 7. Fora de escopo deste prompt

- Provedor PIX real e credenciais de produção (Q-01). O fluxo sandbox do
  Prompt 14 está documentado em `docs/PIX_SANDBOX.md`; a confirmação usa
  `CreditWalletManager::credit` com a chave `payment:{id}`.
- Comissões de atendentes (Prompt 16).
- Bônus, cashback e expiração de créditos.
- Estorno automático de consultas; qualquer correção manual deve ser um novo
  lançamento auditado.

## 8. Verificação

Testes em `tests/Feature/CreditWalletTest.php` e
`tests/Feature/AdminFinanceTest.php` cobrem crédito, débito, saldo
insuficiente, duas tentativas de débito sobre o mesmo saldo, idempotência,
imutabilidade, consistência saldo×ledger, histórico do cliente, autorização e
ajuste administrativo auditado. `tests/Feature/ConsultationBillingTest.php`
cobre a cobrança por minuto e seus efeitos no saldo. `tests/Feature/PixPaymentTest.php` cobre a
entrada de crédito somente após webhook autenticado, incluindo duplicidade,
recusa, expiração e valor divergente.

