# Política de comissões dos atendentes

Este documento define como a Arcana Tarot calcula e registra a comissão
interna dos atendentes no MVP. A comissão é uma participação na receita do
serviço; **não é pagamento automático, transferência bancária, garantia de
saque ou saldo disponível para o atendente**.

## 1. Taxa do atendente

- Cada atendente possui uma taxa percentual explícita em
  `attendant_profiles.commission_rate_bps`, armazenada em **basis points**
  (1 bps = 0,01%; faixa de `0` a `10000`, equivalente a 0,00%–100,00%).
- Somente administradores ativos podem alterar a taxa. A taxa não aparece em
  perfil público nem em qualquer tela do cliente.
- O percentual é validado no servidor com até duas casas decimais. A conversão
  de texto para bps é feita por aritmética inteira, sem `float`.
- Não existe taxa padrão aplicada implicitamente no cálculo. O valor inicial
  de cadastro pode ser definido pela administração, mas a taxa efetiva de cada
  atendente é sempre o valor explícito gravado em seu perfil.
- Toda alteração gera um `AuditLog` com administrador responsável, horário,
  valor anterior e valor novo. O total acumulado de comissões nunca é editável
  diretamente.

## 2. Snapshot no início oficial

No aceite da consulta, quando a sessão passa de `requested` para `active`, o
servidor copia a taxa vigente para `consultations.commission_rate_bps`.

A comissão de encerramento lê exclusivamente esse snapshot. Alterações
posteriores no perfil valem apenas para consultas iniciadas depois da
alteração e nunca recalculam consultas antigas ou já iniciadas.

O snapshot também é copiado para cada lançamento do razão de comissões, o que
preserva a taxa histórica mesmo se o perfil ou a consulta forem consultados no
futuro.

## 3. Base de cálculo monetária

A base é o **valor efetivamente cobrado do cliente pela consulta, em centavos**.
Ela é obtida do livro-razão de créditos (`credit_ledger_entries`), somando os
débitos gerados pela própria consulta com as chaves
`consultation:{id}:minute:{n}`.

Regras:

- Não são usados recargas, saldo remanescente, créditos comprados ou a
  quantidade bruta de créditos como se fossem reais.
- Cada crédito vale o preço unitário congelado em
  `consultations.credit_unit_price_cents` no aceite. Portanto a conversão
  crédito→dinheiro é a configuração oficial da plataforma, não uma suposição
  de que 1 crédito equivale a R$ 1.
- O valor por minuto vem do preço do **atendente para a modalidade**
  (`attendant_modality_prices.price_per_minute_cents`), congelado no aceite em
  `consultations.price_per_minute_cents`.
- Consulta recusada, cancelada ou expirada antes de começar não possui débitos
  e não gera comissão.
- Consulta iniciada com cobrança positiva gera comissão mesmo que termine como
  `ended` ou `interrupted`, inclusive por saldo insuficiente, desde que exista
  valor efetivamente cobrado.
- Não há estorno/reembolso de créditos ao cliente no MVP. Se esse recurso for
  criado, a base de comissão deverá passar a ser o valor líquido dos estornos
  registrados; até lá, a base é a soma dos débitos oficiais da consulta.

## 4. Fórmula e arredondamento

A comissão é calculada com inteiros:

```text
comissao_cents = floor((base_cents × taxa_bps + 5000) / 10000)
```

O termo `+ 5000` implementa **arredondamento comercial half-up** para o centavo
mais próximo, pois o divisor é 10000. Exemplos:

| Base | Taxa | Cálculo exato | Comissão |
| ---: | ---: | ---: | ---: |
| R$ 100,00 (10000) | 25,00% (2500) | R$ 25,00 | R$ 25,00 |
| R$ 1,02 (102) | 25,00% (2500) | 25,5 centavos | R$ 0,26 |
| R$ 1,01 (101) | 25,00% (2500) | 25,25 centavos | R$ 0,25 |
| R$ 0,02 (2) | 25,00% (2500) | 0,5 centavo | R$ 0,01 |

Nenhum valor monetário é calculado com `float`.

## 5. Lançamentos imutáveis

`commission_ledger_entries` é um livro-razão imutável:

- `accrual`: comissão original da consulta, gerada uma única vez.
- `reversal`: estorno total da comissão original. O valor é armazenado como
  positivo, mas **subtrai** do acumulado por causa do tipo.
- `adjustment`: correção interna positiva, com motivo e autor, quando
  necessária.

Cada lançamento guarda taxa snapshot, base, valor, consulta, atendente,
idempotência, data e, quando aplicável, motivo e administrador responsável.

A chave de idempotência do accrual é
`consultation:{id}:commission:accrual`. O índice único e o bloqueio da consulta
garantem uma única comissão original mesmo com retries, dois workers ou
encerramento repetido. Estornos usam
`consultation:{id}:commission:reversal:{accrual_id}` e referenciam o lançamento
original em `reversed_entry_id`.

Lançamentos nunca são editados ou apagados. Correções geram novos lançamentos
com tipo, motivo e autor.

## 6. Estornos e ajustes

- Um estorno só pode referenciar um `accrual` ainda não estornado.
- O estorno repete a base, a taxa snapshot e o valor da comissão original.
- O motivo é obrigatório e o administrador responsável fica registrado no
  lançamento e na auditoria.
- Repetir a solicitação de estorno é idempotente e não cria um segundo
  lançamento.

## 7. Exibição e relatórios

- O atendente vê somente os próprios lançamentos: consulta, taxa aplicada,
  base, comissão, estornos e total acumulado.
- O administrador pode filtrar por atendente, período e consulta, e comparar:
  - receita de serviço com comissão (soma das bases dos accruals);
  - comissões acumuladas (accruals + adjustments − reversals);
  - receita após comissões (receita de serviço − comissões).
- **Receita após comissão não é lucro líquido.** Impostos, custos
  operacionais e taxas de pagamento não estão modelados no MVP.
- Dashboard e relatório usam o mesmo razão como fonte única; não há saldo de
  comissão paralelo ou editável.
- As definições completas dos indicadores e o valor consumido por consulta estão
  em `docs/ADMIN_REPORTING.md`.

## 8. Fora do escopo do MVP

- Pagamento, transferência, saque ou antecipação de comissão.
- Carteira de comissão para o atendente.
- Cálculo de impostos, custos e lucro líquido.
- Estorno/reembolso de créditos ao cliente.
