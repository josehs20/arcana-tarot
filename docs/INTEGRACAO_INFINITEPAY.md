# InfinitePay — Checkout Integrado

> Recarga de créditos pelo Checkout Integrado da InfinitePay (link hospedado pelo
> provedor). Este documento descreve a configuração, o fluxo, o contrato do
> webhook, a confirmação server-side (`payment_check`) e os testes.

## 1. Escopo e garantias

- O cliente escolhe um **pacote de créditos**, nunca um valor em reais. O
  servidor deriva o preço de `config('payments.packages')` × preço do crédito
  vigente (setting administrativo `credit_unit_price_cents`, lido por
  `App\Support\ConsultationPricing`).
- O link de pagamento é criado **no servidor** (`POST /links`) e o navegador é
  apenas redirecionado para ele.
- **Nenhum crédito é liberado sem `payment_check` aprovado.** O webhook é um
  gatilho de conferência, não uma prova de pagamento; o retorno do navegador
  (`redirect_url`) também reconfere o pedido no provedor.
- Valor divergente entre webhook/`payment_check` e pedido → recusa registrada,
  sem crédito.
- Duas camadas de idempotência: `payments.idempotency_key` (criação do pedido) e
  `payment_webhook_events.provider_event_id` (notificação), ambas aplicadas
  dentro de transação com `lockForUpdate`.
- Estorno é registrado e sinalizado para análise manual; créditos **não** são
  removidos automaticamente (podem já ter sido consumidos em atendimento).

## 2. Configuração

Variáveis em `.env` (nunca versionar valores reais):

```dotenv
PAYMENT_PROVIDER=infinitepay

INFINITEPAY_HANDLE=sua-infinite-tag
INFINITEPAY_API_KEY=
INFINITEPAY_BASE_URL=https://api.checkout.infinitepay.io
INFINITEPAY_WEBHOOK_URL=
INFINITEPAY_CHARGE_TTL_MINUTES=1440
INFINITEPAY_TIMEOUT_SECONDS=15
```

| Chave | Obrigatória | Uso |
|---|---|---|
| `payments.provider` | sim | Precisa ser `infinitepay` para o checkout aparecer. |
| `payments.infinitepay.handle` | sim | InfiniteTag que recebe o pagamento. Sem ela o checkout fica desabilitado. |
| `payments.infinitepay.api_key` | não | Enviada no header `X-Api-Key`; habilita `options` (pagamento único, validade e dispensa do endereço de entrega). |
| `payments.infinitepay.base_url` | não | Base da API (`https://api.checkout.infinitepay.io`). |
| `payments.infinitepay.webhook_url` | recomendada | URL pública do webhook. Vazio = `route('webhooks.infinitepay')`. |
| `payments.infinitepay.charge_ttl_minutes` | não | Validade registrada em `payments.expires_at`. |
| `payments.infinitepay.timeout_seconds` | não | Timeout das chamadas HTTP (connect timeout fixo em 5s). |

Pacotes de créditos (apenas quantidade de créditos; o preço é sempre calculado):

O catálogo é **gerenciado pela administração** em `/admin/financeiro/pacotes`
(“Pacotes e recarga”, também acessível pelo atalho no cartão do financeiro). Ele
fica gravado no setting `credit_packages` (JSON) e é a fonte usada pelo checkout:

```json
[
    { "credits": 50, "label": null },
    { "credits": 100, "label": "Pacote inicial" }
]
```

A chave interna de cada pacote é derivada da quantidade (`credits_50`,
`credits_100`, ...), com sufixo `_2`, `_3` em caso de repetição. Toda alteração
gera um `AuditLog` com `action = credit_packages.updated` (antes/depois).

`config/payments.php` permanece apenas como **fallback** para instalações novas,
enquanto o admin ainda não salvou um catálogo:

```php
// config/payments.php
'packages' => [
    'credits_50' => 50,
    'credits_100' => 100,
    'credits_250' => 250,
    'credits_500' => 500,
],
```

Faixa de recarga (mínimo e máximo) também é **gerenciada pela administração** na
mesma página, gravada nos settings `payment_min_amount_cents` e
`payment_max_amount_cents` (centavos) por `App\Support\PaymentLimits`, com
`AuditLog` `payment_limits.updated`. O mínimo precisa cobrir ao menos 1 crédito e
o máximo não pode ser menor que o mínimo. `config('payments.min_amount_cents')` /
`config('payments.max_amount_cents')` só valem enquanto o admin não salvar nada.

`App\Support\CreditPackages` multiplica cada quantidade pelo preço do crédito e
descarta pacotes fora dessa faixa, e `UpdateCreditPackagesRequest` rejeita um
catálogo com pacote fora dela — para que nenhum pacote salvo desapareça
silenciosamente do checkout.

Webhook a cadastrar no painel da InfinitePay:

```
https://SEU-DOMINIO/webhooks/infinitepay
```

## 3. Fluxo do checkout

| Rota | Nome | Papel |
|---|---|---|
| `POST /creditos/checkout` | `client.credits.checkout.store` | Cria/reaproveita o pedido e redireciona ao link. |
| `GET /creditos/checkout/retorno` | `client.credits.checkout.return` | Retorno do navegador; reconfere no provedor. |
| `POST /webhooks/infinitepay` | `webhooks.infinitepay` | Notificação assíncrona do provedor. |

1. `StoreCreditCheckoutRequest` valida `package` (chave do catálogo) e
   `operation_id` (UUID gerado pelo formulário).
2. `CreditCheckoutController@store` chama
   `InfinitePayCheckoutManager::startCheckout()`.
3. O manager valida cliente ativo, recalcula valor/créditos, grava `payments`
   (status `pending`, `package_key`, `external_reference = recharge_<ULID>`) e
   registra auditoria `payment.created`.
4. `InfinitePayGateway::createCheckoutLink()` chama `POST /links` enviando
   `redirect_url` e o objeto `webhook` (`url` + lista de eventos) e grava
   `checkout_url`, `invoice_slug` e `raw_status`. Falha do provedor marca o
   pedido com `failure_reason` e devolve mensagem ao cliente.
5. O usuário é redirecionado com `redirect()->away($payment->checkout_url)`.

Repetir o mesmo `operation_id` com o mesmo pacote devolve o pedido existente
(sem criar outro e sem reenviar chamada ao provedor quando o link já existe).
Reutilizar o `operation_id` com outro pacote/valor resulta em erro de validação.

## 4. Webhook e confirmação server-side

`POST /webhooks/infinitepay` (throttle `120/min`, sem CSRF):

1. `InfinitePayGateway::parseWebhook()` exige JSON válido, `order_nsu` e
   `amount` inteiro > 0, limita identificadores a 120 caracteres e calcula o
   `sha256` do corpo.
2. `InfinitePayEvent::fromWebhook()` normaliza `event`/`event_type`/`type`/`status`
   (ex.: `approved` → `paid`, `cancelled`/`expired` → `canceled`). Notificação sem
   evento tipado é tratada como pagamento aprovado — e por isso **sempre** passa
   por `payment_check`.
3. `InfinitePayCheckoutManager::handleWebhook()` localiza o pedido por
   `external_reference`; pedido inexistente é registrado com
   `failure_reason = unknown_order` e responde **400**.
4. Para eventos `paid`/`settled`, o gateway chama `POST /payment_check` com
   `handle`, `order_nsu`, `transaction_nsu` e `slug`. Sem `transaction_nsu`/
   `invoice_slug` (nem no payload, nem no pedido) a notificação é recusada com
   `missing_reference`.
5. `apply()` roda em transação com lock no pedido e no evento:

| Verificação | Resultado |
|---|---|
| Evento já registrado (`provider_event_id`) | `duplicate` → **200** com `duplicate: true` |
| Pedido em `failed`/`refunded`/`expired` | ignorado, sem crédito |
| Pedido `confirmed` + evento de estorno | `payment.refunded` (auditoria com `manual_review_required`) |
| Pedido `confirmed` + outro evento | ignorado (`payment_already_confirmed`) |
| Pedido pendente + evento de estorno | ignorado (`refund_without_payment`) |
| `canceled` / `payment_failed` em pedido pendente | `PaymentStatus::Failed` + `failure_reason` `provider_canceled` / `provider_declined` |
| Evento `created` | ignorado (`awaiting_payment`) |
| `amount` do webhook ≠ `payments.amount_cents` | `amount_mismatch` → **400** |
| `payment_check` sem `success`/`paid` | `not_paid_confirmed` → **400** |
| `payment_check` sem valor | `amount_unavailable` → **400** |
| `payment_check.amount` ≠ pedido | `amount_mismatch` → **400** |
| Tudo confere | `confirmed` + crédito na carteira na **mesma** transação |

Respostas HTTP do webhook:

- **200** — notificação aceita (processada, duplicada ou ignorada).
- **400** — notificação recusada: corpo inválido (JSON malformado, sem
  `order_nsu`/`amount`), `order_nsu` desconhecido ou divergência/não confirmação.
  A doc da InfinitePay recomenda 400 para erro e reenvia qualquer resposta fora
  de 2xx; a idempotência por `provider_event_id` absorve os reenvios.
- **503** — provedor indisponível ou falha inesperada; o provedor deve reenviar.

## 5. Retorno do navegador

`GET /creditos/checkout/retorno?order_nsu=...&transaction_nsu=...&slug=...`

A InfinitePay devolve o código da fatura na `redirect_url` como **`slug`** (no
webhook ele vem como `invoice_slug`). O controller lê `slug` e mantém
`invoice_slug` como alternativa; se ambos faltarem, usa o `invoice_slug`
gravado na criação do link.

- O pedido é buscado por `external_reference` **e** `user_id` do usuário
  autenticado; pedido de outro cliente cai na listagem de recargas sem nenhuma
  chamada ao provedor.
- Nenhum parâmetro da URL concede crédito: `reconcile()` consulta `payment_check`
  e usa a mesma transação do webhook.
- Mensagens: confirmação com crédito liberado, pagamento em análise, recusa ou
  falha temporária de conciliação (nesse caso o webhook continua sendo a fonte
  definitiva).

## 6. Banco de dados

Migração `2026_11_05_120000_add_infinitepay_checkout_fields_to_payments_table`:

| Coluna | Tipo | Uso |
|---|---|---|
| `package_key` | varchar(40) | Pacote escolhido (auditoria/relatórios). |
| `checkout_url` | text | Link hospedado devolvido pelo provedor. |
| `invoice_slug` | varchar(120), indexado | Enviado ao `payment_check`. |
| `transaction_nsu` | varchar(120), **único** | NSU da transação no provedor. |
| `capture_method` | varchar(20) | `pix`, `credit_card` etc. devolvido pelo provedor. |
| `verified_at` | timestamp | Momento da confirmação server-side. |

Aplicar com:

```bash
docker compose exec app php artisan migrate
```

## 7. Testes

`tests/Feature/InfinitePayCheckoutTest.php` (20 casos, HTTP fakeado):

- pedido pendente com valor calculado no servidor e payload correto em `/links`;
- repetição do mesmo `operation_id` sem duplicar pedido/chamada;
- pacote inexistente recusado antes de chamar o provedor;
- `customer.email` omitido em domínio reservado (`*.local`) e enviado quando o
  domínio é público;
- recusa de validação do provedor (HTTP 422) propagada com os detalhes no
  `InfinitePayException` e pedido marcado como `failed`/`provider_error`;
- webhook aprovado com `payment_check` creditando uma única vez;
- webhook duplicado sem duplicar crédito;
- `payment_check` não confirmado, valor divergente e falta de referências;
- pedido desconhecido e corpo inválido do webhook (ambos recusados com 400);
- `canceled` marcando falha e estorno preservando créditos (análise manual);
- retorno do navegador sem confirmação não credita e com confirmação credita;
- isolamento entre clientes;
- página de créditos listando os pacotes;
- catálogo definido pela administração (`credit_packages`) prevalecendo sobre
  `config('payments.packages')` no preço e na chave do pedido.

```bash
docker compose exec app php artisan test --compact tests/Feature/InfinitePayCheckoutTest.php
```

## 8. Operação e diagnóstico

Logs (canal padrão da aplicação):

| Evento | Contexto |
|---|---|
| `infinitepay.checkout_creation_failed` | Falha ao gerar link (pedido marcado). |
| `infinitepay.webhook_invalid` | Corpo recusado (400). |
| `infinitepay.webhook_provider_unavailable` | Falha HTTP/timeout no `payment_check` (503). |
| `infinitepay.payment_refunded` | Estorno registrado para análise manual. |
| `infinitepay.webhook_rejected` | Notificação reprovada na conferência (400). |
| `infinitepay.webhook_unknown_order` | `order_nsu` sem pedido local (400). |
| `infinitepay.return_reconcile_failed` | Retorno do navegador sem conciliação. |
| `infinitepay.request_rejected` | HTTP de erro do provedor com `status` e `details` (mensagens de validação). |
| `infinitepay.customer_email_ignored` | `customer.email` não enviado (formato ou TLD reservado). |
| `infinitepay.customer_phone_ignored` | `customer.phone_number` não enviado (fora de 10–15 dígitos). |

### Dados do cliente no `/links`

O bloco `customer` é opcional, mas a InfinitePay **recusa o link inteiro**
(HTTP 422, `{"errors":{"customer":{"email":["must be a valid email"]}}}`)
quando recebe um e-mail que o validador dela não aceita — caso dos domínios
reservados usados pelas contas de demonstração (`cliente.livre@arcana.local`).
Por isso o gateway só envia o que o provedor aceita:

- `email`: `FILTER_VALIDATE_EMAIL` + TLD obrigatório e fora da lista reservada
  (`local`, `localhost`, `test`, `invalid`, `example`, `internal`, `lan`,
  `home`, `corp`, `intranet`, `onion`, `localdomain`);
- `phone_number`: dígitos normalizados para E.164 (`+5511999999999`), enviado
  apenas com 10–15 dígitos;
- `name`: sempre que preenchido.

Campos descartados vão para o log (`customer_email_ignored` /
`customer_phone_ignored`) e não bloqueiam a cobrança.

Auditoria (`audit_logs`): `payment.created`, `payment.confirmed`,
`payment.failed`, `payment.refunded`, `payment.provider_failed`,
`payment.webhook_rejected`.

## 9. Contrato conferido na documentação oficial

Validado contra <https://www.infinitepay.io/checkout-documentacao>:

1. **Criação do link** — `POST /links` com `handle`, `items[].quantity`,
   `items[].price` (centavos), `items[].description`, `order_nsu`,
   `redirect_url`, `customer` (`name`/`email`/`phone_number`) e `options`
   (`single_payment`, `require_delivery_address`, `valid_until` em ISO 8601).
   O valor mínimo é R$ 1,00 e `options` só é aceito junto com a API Key — o
   gateway só a envia quando `INFINITEPAY_API_KEY` está definida.
   Como a recarga de créditos é um serviço digital (nada é entregue), o gateway
   envia `require_delivery_address: false` para dispensar a etapa `#delivery`
   do checkout. A doc é explícita: *"Dispensar o endereço, sim:
   `options.require_delivery_address: false`. Exige API Key."* — sem a chave o
   checkout continua pedindo o endereço.
2. **Webhook** — é configurado na criação de cada link. O gateway usa o formato
   elaborado `webhook: { url, events }` com todos os eventos (`created`,
   `paid`, `settled`, `canceled`, `refunded`, `payment_failed`); o formato
   simples `webhook_url` (apenas aprovação) também é aceito pela InfinitePay.
   O webhook não tem assinatura: a garantia é o `payment_check` + valor +
   idempotência.
3. **URL do checkout** — vem na resposta do `/links`. Como a doc não fixa o
   nome do campo, o gateway aceita `url`, `link`, `checkout_url` ou
   `payment_url` (inclusive dentro de `data`).
4. **Consulta** — `POST /payment_check` recebe `handle`, `order_nsu`,
   `transaction_nsu` e `slug`, devolvendo `success`, `paid`, `amount`,
   `paid_amount`, `installments` e `capture_method`. O `slug`/`transaction_nsu`
   chegam no webhook como `invoice_slug`/`transaction_nsu` e na `redirect_url`
   como `slug`/`transaction_nsu`; só `order_nsu` não basta.
5. **Valor** — a comparação usa `amount` (valor original do pedido). A doc
   orienta **não** exigir `amount == paid_amount`, pois `paid_amount` pode
   incluir acréscimos.
6. **Estorno/recusa** — chegam como `refunded`/`canceled`/`payment_failed`; o
   corpo sem evento tipado é tratado como pagamento e cai na reconferência.

## 10. Limitações conhecidas

- Créditos de pedidos estornados não são debitados automaticamente; a carteira
  pode ficar com saldo a maior até a análise manual.
- Não há rotina agendada de conciliação de pedidos pendentes; a confirmação
  depende do webhook ou de o cliente abrir a página de retorno.
- Não há suporte a múltiplos itens por pedido: um pagamento = um pacote.
- Estorno parcial, boleto e assinatura recorrente estão fora de escopo.

