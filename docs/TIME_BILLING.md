# Cobrança por tempo da consulta

> Implementado no **Prompt 15**. Este documento é a referência das regras de
> cobrança por minuto, do encerramento automático por saldo e da política de
> falha/reconexão. As invariantes financeiras gerais continuam em
> `docs/WALLET_LEDGER.md`.

## 1. Princípio

O tempo oficial da consulta é calculado **exclusivamente no servidor** a partir
de `consultations.started_at` e `consultations.ended_at`. O contador exibido no
navegador é apenas informativo: nenhum valor de saldo, duração ou cobrança é
aceito do cliente.

A tarifa é **congelada no aceite**:

```text
preço do minuto = attendant_modality_prices.price_per_minute_cents
                  (modalidade escolhida no pedido)
```

- o preço é definido **por atendente e por modalidade** no painel
  administrativo (`/admin/modalidades`);
- `credit_unit_price_cents` continua global e é usado apenas para converter
  recargas em créditos e para consultas legadas;
- `consultations.price_per_minute_cents` é copiado no aceite (`snapshot`);
- alterações posteriores no preço do atendente só afetam novas consultas;
- sem preço configurado para a modalidade, o servidor recusa a solicitação e o
  aceite (`Preço não configurado`), sem fallback global.

## 2. Regra de arredondamento

Cobra-se **por minuto iniciado**, com mínimo de 1 minuto e sem período de
carência:

| Duração transcorrida | Minutos cobrados |
|---|---|
| 0 s a 60 s | 1 |
| mais de 60 s até 120 s | 2 (o segundo minuto começou) |
| mais de 120 s até 180 s | 3 |
| 183 s | 4 |

O cálculo é `max(1, ceil(segundos / 60))`. Qualquer segundo dentro de um novo
minuto já o torna devido. Não existe tolerância configurável no MVP (Q-03).

## 3. Linha do tempo da cobrança

1. **Aceite** — o atendente aceita a solicitação. A consulta passa a `active`,
   `started_at` é gravado e o **primeiro minuto é debitado imediatamente**.
   - Se o saldo não cobrir o primeiro minuto, o aceite falha e toda a transação
     é revertida: a consulta permanece `requested`, sem `started_at` e sem
     lançamento no livro-razão.
2. **Consulta ativa** — o comando `consultations:charge-minutes` roda a cada
   minuto e cobra todos os minutos vencidos ainda não registrados.
3. **Encerramento pelo cliente/atendente** — o status vai para `ended`,
   `ended_at` é gravado e o último minuto iniciado é liquidado na mesma
   transação.
4. **Encerramento pelo sistema** — usado pela cobrança quando o saldo acaba;
   grava `ended`, `ended_at` e `ended_reason = out_of_credits`, liquidando o que
   ainda for possível.
5. **Desconexão/falha** — `consultations:interrupt-disconnected` interrompe a
   sessão e liquida os minutos vencidos até `ended_at`.

Depois de um estado final **não há novas cobranças**: a rotina só seleciona
consultas `active`, e `ended_at` congela o cálculo dos minutos devidos.

## 4. Cursor e idempotência

`consultations.billed_minutes` é um cursor server-side que indica quantos
minutos já foram lançados. Ele nunca é aceito de formulário ou API.

Cada minuto tem uma chave determinística:

```text
consultation:{consultation_id}:minute:{n}
```

O fluxo de cobrança executa em transação:

1. `SELECT ... FOR UPDATE` na consulta;
2. cálculo de `dueMinutes` a partir de `started_at`/`ended_at`;
3. para cada minuto ainda não cobrado, `CreditWalletManager::debit` com a chave
   acima;
4. atualização do cursor na mesma transação dos lançamentos.

Consequências:

- repetir o comando ou o encerramento **não duplica** débitos;
- atraso ou reinício do scheduler apenas cobra os minutos pendentes;
- não existe débito parcial: cada minuto é um lançamento inteiro;
- o `UPDATE` condicional da carteira impede saldo negativo mesmo sob
  concorrência.

## 5. Saldo insuficiente e encerramento automático

A cobrança percorre os minutos devidos em ordem. Ao encontrar um minuto sem
saldo:

- os minutos anteriores já cobrados permanecem;
- **nenhum débito parcial** é criado;
- o cursor não avança além do último minuto pago;
- o comando encerra a consulta via `endBySystem(..., OutOfCredits)`;
- `ended_reason` passa a `out_of_credits`;
- novas execuções não cobram a consulta finalizada.

O aceite também exige o saldo mínimo configurado
(`minimum_balance_minutes × price_per_minute_cents` do atendente). Esse valor
apenas autoriza o início; a sessão continua enquanto cada novo minuto puder ser
pago.

## 6. Falha, desconexão e reconexão

- A cobrança não depende do WebSocket. A presença usa batimentos HTTP, mas o
  débito é calculado exclusivamente no servidor.
- Reconectar o chat **não** reinicia a contagem nem o preço: `started_at` e os
  snapshots permanecem os mesmos.
- Se nenhum participante interagir por
  `consultation_inactivity_timeout_minutes` mais
  `consultation_reconnection_tolerance_seconds`, a consulta é interrompida com
  `ended_reason = inactivity` e o último minuto iniciado é liquidado. O
  batimento de presença não conta como atividade; detalhes em
  `docs/SEGURANCA_E_USABILIDADE_CHAT.md`.
- `CONSULTATION_PRESENCE_TIMEOUT_MINUTES` (padrão 15) controla apenas a
  liberação da disponibilidade do atendente, não a interrupção da consulta.
- Uma sessão em estado final não é retomada. Um novo atendimento é uma nova
  solicitação, com tarifa vigente no novo aceite.
- Não há tempo máximo de sessão além do limite de inatividade. O enum `timeout`
  existe para evolução futura, mas nenhuma rotina o utiliza hoje.
- Não há estorno automático de consulta já iniciada (Q-15); correções
  financeiras exigem lançamento administrativo auditado.

## 7. Administração da tarifa

- Preço por minuto: **Admin → Modalidades** (`/admin/modalidades`), por
  atendente e por modalidade; grava em `attendant_modality_prices` e audita
  `attendant.modalities_updated`.
- Valor do crédito e saldo mínimo: **Admin → Financeiro**
  (`PATCH /admin/financeiro/tarifa`, rota `admin.finance.pricing.update`),
  exclusiva de administrador ativo.
- Campos globais: `credit_unit_price_cents` e `minimum_balance_minutes`
  (inteiros positivos).
- A atualização global grava os dois valores em `settings` e um
  `audit_logs.action = consultation_pricing.updated` com `before`/`after` na
  mesma transação.
- O painel `/admin/financeiro` mostra o valor do crédito e o saldo mínimo e
  encaminha para **Modalidades**, onde os preços por atendente são definidos.
  Sem preço para a modalidade, o pedido e o aceite daquele atendente são
  recusados.
- Consultas já aceitas continuam usando o snapshot gravado.

## 8. Interface

- `resources/js/consultation-timer.js` formata a diferença entre `started_at`
  (e `ended_at`, quando houver) e o relógio do navegador.
- O contador não calcula saldo nem preço e é atualizado a cada segundo,
  inclusive ao voltar para a aba.
- A tela da consulta exibe o valor do minuto para o cliente e os minutos
  cobrados para cliente/admin, sempre derivados dos dados do servidor.

## 9. Auditoria

Além do livro-razão, a auditoria registra:

- `consultation.accepted`: preço do minuto (snapshot do atendente), saldo
  mínimo, comissão snapshot, minutos e centavos cobrados no aceite;
- `consultation.ended`: motivo final, duração em segundos, minutos e centavos
  liquidados;
- `consultation.interrupted`: motivo da interrupção e valores liquidados;
- `consultation_pricing.updated`: valor do crédito e saldo mínimo (anteriores e
  novos);
- `attendant.modalities_updated`: modalidades e preços por modalidade do
  atendente (anteriores e novos).

## 10. Configuração

| Chave | Origem | Uso |
|---|---|---|
| `attendant_modality_prices.price_per_minute_cents` | `admin/modalities` (por atendente) | Preço do minuto da modalidade; snapshot no aceite. |
| `credit_unit_price_cents` | `settings` (admin/PIX) | Valor do crédito em centavos, usado nas recargas. |
| `minimum_balance_minutes` | `settings` (admin), fallback `config('consultation.minimum_balance_minutes')` | Minutos mínimos exigidos no aceite (× preço do atendente). |
| `consultation_inactivity_timeout_minutes` | `settings` (admin), fallback `CONSULTATION_INACTIVITY_TIMEOUT_MINUTES` | Tempo sem interação antes da interrupção automática. |
| `consultation_reconnection_tolerance_seconds` | `settings` (admin), fallback `CONSULTATION_RECONNECTION_TOLERANCE_SECONDS` | Margem extra para quedas breves de rede. |
| `CONSULTATION_ACTIVITY_PING_SECONDS` | `.env`/config | Intervalo do batimento de presença do navegador. |
| `CONSULTATION_NAVIGATION_WARNING_SECONDS` | `.env`/config | Aviso local após tempo sem interação. |
| `CONSULTATION_PRESENCE_TIMEOUT_MINUTES` | `.env`/config | Janela para liberar a disponibilidade do atendente; não interrompe a consulta. |

O scheduler (`routes/console.php`) executa:

```text
consultations:expire-requests
consultations:interrupt-disconnected
consultations:charge-minutes
```

Todos com `everyMinute()` e `withoutOverlapping()`.

## 11. Cobertura de testes

`tests/Feature/ConsultationBillingTest.php` cobre, com relógio controlado:

- cobrança do primeiro minuto no aceite e chave idempotente;
- avanço por minuto iniciado sem duplicar lançamentos;
- fronteira exata de 60 s sem nova cobrança, cobrando apenas depois de iniciar
  o próximo minuto;
- execução repetida do comando sem nova cobrança;
- encerramento automático por saldo, sem saldo negativo e sem débito parcial;
- encerramento manual liquidando o último minuto e bloqueando cobranças futuras;
- encerramento manual sem saldo com motivo `out_of_credits`;
- interrupção por desconexão liquidando minutos e mantendo o motivo;
- uso do snapshot de preço após alteração do preço do atendente.

`tests/Feature/AdminFinanceTest.php` cobre a atualização do valor do crédito e
do saldo mínimo, validação, bloqueio de não administradores e auditoria
`before`/`after`.
`tests/Feature/ConsultationModalityTest.php` cobre o preço por atendente e
modalidade, incluindo a recusa quando falta preço configurado.
`tests/Feature/CreditWalletTest.php` cobre as invariantes de saldo e
idempotência reutilizadas pela cobrança.

## 12. Fora de escopo

- Comissão do atendente sobre os débitos (Prompt 16).
- Estorno/cancelamento financeiro de consulta iniciada (Q-15).
- Tarifa individual por atendente.
- Tempo máximo de sessão além da interrupção por inatividade.
- Cobrança durante indisponibilidade do scheduler além dos minutos pendentes:
  ao voltar, todos os minutos vencidos são cobrados de uma vez, respeitando o
  saldo.

> **Modalidades:** a tarifa por minuto é configurada por modalidade (texto, áudio
> e vídeo), preservando esta mesma regra e os snapshots descritos acima.
> Consulte [MODALIDADES_E_PRECIFICACAO.md](MODALIDADES_E_PRECIFICACAO.md).
