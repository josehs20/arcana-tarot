# PIX em modo de teste

> Implementado no **Prompt 14**. O provedor PIX de produção **não foi escolhido**
> (Q-01). Este documento descreve o contrato, o mock sandbox, os estados, o
> webhook, a simulação local e o checklist de troca segura para produção.

## 1. Escopo e garantias

- **Nenhum dinheiro real é movimentado.** O único provedor implementado é
  `pix_sandbox`; ele não faz chamadas de rede, não usa credenciais bancárias e
  não gera um payload PIX/EMV válido.
- O código exibido ao cliente começa com `SANDBOX-NAO-E-PIX` e deve ser tratado
  como texto de teste, nunca como cobrança.
- Em produção o sandbox é desabilitado por padrão
  (`PAYMENT_SANDBOX_ENABLED` ausente + `APP_ENV=production`).
- Nenhum crédito é liberado por redirect, GET, query string ou ação do
  navegador. A confirmação passa por `POST /webhooks/pix`, autenticado pelo
  adaptador, e por uma transação que altera `payments` e
  `credit_ledger_entries` juntos.
- O cliente nunca informa saldo, quantidade de créditos ou preço. Ele informa
  apenas o valor pretendido em reais; o servidor valida o múltiplo do preço do
  crédito e recalcula a quantidade.

## 2. Provedores inspecionados

A escolha continua pendente (Q-01). A tabela registra apenas fatos das
documentações oficiais consultadas em 2026-10-21; não há endpoint, SDK ou
credencial inventados.

| Provedor | Autenticação de webhook | Replay/idempotência | Conciliação server-to-server | Observações |
|---|---|---|---|---|
| **Asaas** | Token configurável enviado no header `asaas-access-token`; validar antes de aceitar. | Entrega *at least once*; o `id` do evento permanece o mesmo em reenvios e deve ser único no banco. | API de cobranças permite consultar o pagamento. | Sandbox e IPs oficiais documentados; eventos de cobrança incluem `PAYMENT_RECEIVED`, `PAYMENT_CONFIRMED`, `PAYMENT_REFUNDED` etc. |
| **Efí/Gerencianet** | Webhooks exigem **mTLS** com certificado público da Efí; há também validação por IP e opção de HMAC na URL do webhook. | Reenvios ocorrem até 9 vezes; o evento precisa ser deduplicado pela referência recebida. | API Pix permite consultar cobranças e Pix recebidos. | Exige configuração de TLS/certificados no servidor; mais complexo para o primeiro provedor. |
| **Mercado Pago** | Header `x-signature` no formato `ts=...,v1=...`, com `x-request-id`; a assinatura é validada com o segredo da aplicação. | A notificação possui identificador próprio; persistir e deduplicar antes de processar. | Consulta de pagamento em `/v1/payments/{id}`. | A documentação alerta que notificações de integrações com Código QR não podem ser validadas pela assinatura secreta; isso precisa ser considerado se o produto usar esse fluxo. |

Fontes oficiais:

- Asaas — eventos de cobrança: <https://docs.asaas.com/docs/webhook-para-cobrancas>
- Asaas — recebimento de webhooks: <https://docs.asaas.com/docs/receba-eventos-do-asaas-no-seu-endpoint-de-webhook>
- Asaas — idempotência: <https://docs.asaas.com/docs/como-implementar-idempotencia-em-webhooks>
- Efí — webhooks Pix: <https://dev.efipay.com.br/docs/api-pix/webhooks/>
- Mercado Pago — webhooks: <https://www.mercadopago.com.br/developers/pt/docs/your-integrations/notifications/webhooks>

## 3. Arquitetura

A fronteira entre o domínio e o provedor é
`App\Support\Payments\Contracts\PixProvider`:

| Método | Responsabilidade |
|---|---|
| `name()` | Identificador persistido em `payments.provider`. |
| `isAvailable()` | Informa se o adaptador tem configuração mínima para operar. |
| `createCharge(PixChargeRequest)` | Gera a cobrança e devolve `PixCharge` normalizado. |
| `parseWebhook(Request)` | Valida assinatura, timestamp, formato e devolve `PixWebhookEvent` normalizado. |

Componentes:

| Arquivo | Papel |
|---|---|
| `app/Support/PaymentManager.php` | Cria pedidos e aplica transições; único ponto que confirma pagamento. |
| `app/Support/Payments/PixSandboxProvider.php` | Mock sandbox, HMAC local e assinatura de eventos de simulação. |
| `app/Http/Controllers/PixWebhookController.php` | Endpoint público server-to-server, sem CSRF. |
| `app/Http/Controllers/Client/CreditPurchaseController.php` | Compra, listagem e página de status do cliente. |
| `app/Console/Commands/SimulatePixSandboxWebhook.php` | Envia um webhook assinado internamente para testar o fluxo. |
| `app/Models/PaymentWebhookEvent.php` | Registro idempotente do evento recebido, sem payload bruto. |

A troca de provedor deve criar uma nova implementação do contrato e alterar
`PAYMENT_PROVIDER`. Um valor desconhecido falha na resolução do container, sem
fallback silencioso para o mock.

## 4. Configuração do sandbox

1. Gere um segredo local (não use segredo real de provedor):

   ```bash
   openssl rand -hex 32
   ```

2. Preencha o `.env`:

   ```dotenv
   PAYMENT_PROVIDER=pix_sandbox
   PAYMENT_SANDBOX_ENABLED=true
   PAYMENT_SANDBOX_WEBHOOK_SECRET=<segredo-gerado>
   PAYMENT_SANDBOX_WEBHOOK_TOLERANCE_SECONDS=300
   PAYMENT_SANDBOX_CHARGE_TTL_MINUTES=30
   PAYMENT_MIN_AMOUNT_CENTS=100
   PAYMENT_MAX_AMOUNT_CENTS=1000000
   ```

3. Garanta o preço do crédito configurado em `settings`:

   ```bash
   php artisan tinker --execute 'App\Models\Setting::updateOrCreate(["key" => "credit_unit_price_cents"], ["value" => "100", "type" => "integer"]);'
   ```

   O valor acima é apenas um exemplo de desenvolvimento. O preço oficial é uma
   decisão de negócio (Q-02) e não é fixado pela integração.

4. Aplique migrations e limpe configuração em cache:

   ```bash
   php artisan migrate
   php artisan config:clear
   ```

O formulário só é exibido quando o sandbox está habilitado **e** o segredo está
preenchido. O `.env` não é versionado; `.env.example` contém apenas o nome das
variáveis.

## 5. Fluxo e estados

```text
Cliente escolhe valor
        |
        v
POST /creditos/comprar (auth + role:client + CSRF)
        |
        v
Payment pending + external_reference + idempotency_key
        |
        v
PixProvider::createCharge()  -> instruções do sandbox
        |
        v
GET /creditos/recargas/{payment}  -> status pendente (não confirma nada)
        |
        v
POST /webhooks/pix (HMAC + timestamp + event_id)
        |
        +--> approved  -> confirmed -> crédito payment:{id} no ledger
        +--> declined  -> failed     -> nenhum crédito
        +--> expired   -> expired    -> nenhum crédito
        +--> pending   -> ignorado   -> nenhum crédito
```

`PaymentStatus`: `pending`, `confirmed`, `failed`, `expired`, `refunded`.
Somente `pending` pode transicionar. Eventos posteriores em estado final são
registrados como `ignored`; não rebaixam `confirmed` e não duplicam crédito.

Campos de `payments` usados no fluxo:

- `external_reference`: referência gerada pelo servidor e enviada ao provedor;
- `provider_payment_id`: identificador devolvido pelo provedor;
- `idempotency_key`: única por usuário + `operation_id` do formulário;
- `pix_copy_paste`: instruções do sandbox, criptografadas em repouso;
- `expires_at`, `raw_status`, `failure_reason`, `paid_at`.

## 6. Contrato do webhook mock

Endpoint: `POST /webhooks/pix` (sem autenticação de usuário/CSRF, com rate limit).

Headers obrigatórios:

```text
X-Arcana-Event-Id: evt_sandbox_...
X-Arcana-Timestamp: 1780000000
X-Arcana-Signature: v1=<hex hmac sha256>
```

Payload:

```json
{
  "id": "evt_sandbox_123",
  "event": "payment.updated",
  "payment": {
    "id": "sandbox_<uuid>",
    "status": "approved",
    "amount_cents": 10000
  }
}
```

Assinatura:

```text
HMAC-SHA256(
  key = PAYMENT_SANDBOX_WEBHOOK_SECRET,
  message = timestamp + "." + event_id + "." + raw_body
)
```

Validações executadas pelo adaptador, nesta ordem:

1. sandbox habilitado e segredo configurado;
2. headers presentes e `event_id` igual ao `id` do corpo;
3. timestamp numérico dentro de `PAYMENT_SANDBOX_WEBHOOK_TOLERANCE_SECONDS`;
4. formato `v1=<64 hex>` e comparação em tempo constante (`hash_equals`);
5. JSON e campos obrigatórios; status normalizado (`approved`, `declined`,
   `expired`, `pending`);
6. valor em centavos inteiro e positivo.

Respostas:

| Situação | HTTP | Efeito |
|---|---|---|
| Evento novo processado | 200 | Pode confirmar/recusar/expirar. |
| Mesmo `provider_event_id` | 200 | `duplicate: true`, sem novo efeito. |
| Evento novo em pagamento final | 200 | Registrado como `ignored`. |
| Assinatura/timestamp inválidos | 401 | Nenhum estado é alterado. |
| Valor divergente | 422 | Evento `failed` + auditoria; sem crédito. |
| Pagamento desconhecido | 404 | Evento `failed` + auditoria sem PII. |
| Sandbox desabilitado | 503 | Nenhum estado é alterado. |

## 7. Idempotência, replay e dados sensíveis

- `payment_webhook_events.provider_event_id` é **único**. O evento é persistido
  uma única vez; reenvios não executam a regra de negócio novamente.
- A janela de timestamp reduz replay de requisições capturadas. O ID único é a
  segunda barreira, inclusive para reenvios legítimos do provedor.
- O crédito usa a chave global `payment:{payment_id}`, então nem uma mudança de
  status nem um evento com ID novo podem creditar duas vezes.
- A transição de `payments`, o crédito no ledger e o registro do evento ocorrem
  na mesma transação. Falha em qualquer etapa desfaz tudo.
- `payload_hash` guarda SHA-256 do corpo; o payload bruto, dados do pagador,
  CPF, nome, endereço ou credenciais **não** são armazenados.
- `pix_copy_paste` usa o cast `encrypted` do Laravel e é exibido somente ao dono
  do pedido.

## 8. Simulação local e testes

Com o pedido pendente, execute no container:

```bash
docker compose exec app php artisan pix:sandbox-simulate <payment_id> approved
docker compose exec app php artisan pix:sandbox-simulate <payment_id> declined
docker compose exec app php artisan pix:sandbox-simulate <payment_id> expired
```

O comando monta o payload, assina com o segredo local e entrega a requisição ao
kernel HTTP da própria aplicação. A confirmação continua passando pelo endpoint
de webhook e por todas as validações; o comando não altera o pagamento
diretamente.

Testes em `tests/Feature/PixPaymentTest.php` cobrem:

- pedido pendente e instruções do sandbox;
- valor não múltiplo do preço do crédito;
- idempotência da criação por `operation_id`;
- webhook aprovado e crédito único;
- webhook recusado e expirado sem crédito;
- webhook repetido e novo evento após estado final;
- assinatura inválida, timestamp expirado e valor divergente;
- isolamento entre clientes e bloqueio de atendente;
- página de status não confirma por redirect/query string;
- comando de simulação ponta a ponta.

## 9. Troca para produção

O mock não é um caminho de produção e não deve ser habilitado fora de
desenvolvimento/testes. Checklist mínimo:

1. **Escolher o provedor (Q-01)** e ler a documentação oficial vigente de
   cobrança, webhook, assinatura, mTLS e conciliação.
2. Implementar uma nova classe que satisfaça `PixProvider` (idealmente um
   adaptador fino sobre o SDK oficial) usando DTOs do domínio.
3. Configurar credenciais reais **somente** no ambiente de produção/segredos,
   nunca no repositório, em `.env.example`, em testes ou em logs.
4. Alterar `PAYMENT_PROVIDER` para o novo driver e manter
   `PAYMENT_SANDBOX_ENABLED=false`.
5. Servir o webhook por HTTPS, com validação da assinatura/token **conforme o
   provedor** e, quando exigido, mTLS/IP allowlist (Efí).
6. Persistir o identificador único do evento antes ou na mesma transação da
   regra de negócio; responder 2xx apenas depois de persistir.
7. Implementar consulta server-to-server de conciliação para pagamentos
   pendentes, caso o provedor permita ou exija; nunca usar redirect como
   confirmação.
8. Executar testes com *fakes* do provedor, incluindo evento duplicado,
   assinatura inválida, valor divergente, indisponibilidade e reconciliação.
9. Revisar retenção de dados: guardar apenas IDs, valores, status, hash e
   auditoria necessários; não persistir payload bruto com PII.
10. Trocar a URL do webhook no painel do provedor e monitorar falhas/retries.

A interface atual cobre criação e webhook. Se o provedor escolhido só oferecer
consulta por polling, será necessário adicionar ao contrato um método de
consulta/reconciliação e uma rotina agendada; isso não deve ser simulado com
confirmação pelo navegador.

## 10. Limitações conhecidas

- Não há provedor real, SDK, endpoint externo ou credencial neste prompt.
- Não há estorno automático de recarga, cashback ou bônus.
- A expiração local é informativa; o estado só muda com evento válido do
  provedor (ou futura rotina de conciliação).
- A simulação é interna e protegida pelo segredo HMAC, mas não substitui um
  teste de integração com o sandbox oficial do provedor escolhido.
- O preço do crédito continua sendo uma decisão de negócio (Q-02); os limites
  de recarga são parâmetros de segurança, não uma tabela comercial.

