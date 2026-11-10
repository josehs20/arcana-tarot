<?php

namespace App\Support\Payments\InfinitePay;

use App\Enums\InfinitePayEvent;
use App\Support\Payments\Exceptions\InfinitePayException;
use App\Support\Payments\Exceptions\InfinitePayWebhookException;
use App\Support\Payments\InfinitePay\Data\CheckoutLink;
use App\Support\Payments\InfinitePay\Data\CheckoutLinkRequest;
use App\Support\Payments\InfinitePay\Data\PaymentCheck;
use App\Support\Payments\InfinitePay\Data\PaymentCheckRequest;
use App\Support\Payments\InfinitePay\Data\WebhookNotification;
use App\Support\PhoneNumber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

/**
 * Cliente HTTP do Checkout Integrado da InfinitePay.
 *
 * Responsabilidades: gerar o link de pagamento, consultar `payment_check` e
 * normalizar a notificação do webhook. Nenhuma decisão de crédito é tomada
 * aqui — isso é papel do `InfinitePayCheckoutManager`.
 */
final class InfinitePayGateway
{
    public const NAME = 'infinitepay';

    /**
     * DDI usado no `customer.phone_number`. O provedor é brasileiro e a
     * plataforma só aceita telefones com DDD brasileiro, então o número
     * persistido (DDD + assinante) é sempre enviado com este DDI.
     */
    private const CUSTOMER_COUNTRY_CODE = '55';

    /**
     * Domínios reservados (RFC 2606/6761) e nomes de rede privada são recusados
     * pelo validador do provedor (`customer.email` → "must be a valid email").
     *
     * @var list<string>
     */
    private const UNROUTABLE_EMAIL_TLDS = [
        'corp',
        'example',
        'home',
        'internal',
        'intranet',
        'invalid',
        'lan',
        'local',
        'localdomain',
        'localhost',
        'onion',
        'test',
    ];

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * O handle (InfiniteTag) é obrigatório; a API key é opcional e apenas
     * habilita `options` avançadas na criação do link.
     */
    public function isConfigured(): bool
    {
        return $this->handle() !== '';
    }

    public function createCheckoutLink(CheckoutLinkRequest $request): CheckoutLink
    {
        $this->assertConfigured();

        $payload = [
            'handle' => $this->handle(),
            'items' => [
                [
                    'quantity' => 1,
                    'price' => $request->amountCents,
                    'description' => $request->description,
                ],
            ],
            'order_nsu' => $request->orderNsu,
        ];

        if (filled($request->redirectUrl)) {
            $payload['redirect_url'] = $request->redirectUrl;
        }

        if (filled($request->webhookUrl)) {
            $payload['webhook'] = [
                'url' => $request->webhookUrl,
                'events' => array_map(
                    static fn (InfinitePayEvent $event): string => $event->value,
                    InfinitePayEvent::cases(),
                ),
            ];
        }

        $customer = $this->customerPayload($request);

        if ($customer !== []) {
            $payload['customer'] = $customer;
        }

        // As `options` exigem a API Key. Como a recarga de créditos é um
        // serviço 100% digital (nada é entregue ao cliente), dispensamos a
        // etapa de endereço de entrega do checkout (`#delivery`).
        if ($this->hasApiKey()) {
            $payload['options'] = [
                'single_payment' => true,
                'require_delivery_address' => false,
            ];

            if ($request->validUntil !== null) {
                $payload['options']['valid_until'] = Carbon::instance($request->validUntil)
                    ->timezone(config('app.timezone'))
                    ->toIso8601String();
            }
        }
        $data = $this->post('/links', $payload, 'gerar o link de pagamento');

        $url = $this->extractCheckoutUrl($data);
        if ($url === null) {
            throw new InfinitePayException('A InfinitePay não retornou a URL do checkout.');
        }

        return new CheckoutLink(
            orderNsu: $request->orderNsu,
            url: $url,
            invoiceSlug: $this->firstString($data, ['invoice_slug', 'slug']),
        );
    }

    public function checkPayment(PaymentCheckRequest $request): PaymentCheck
    {
        $this->assertConfigured();

        $data = $this->post('/payment_check', [
            'handle' => $this->handle(),
            'order_nsu' => $request->orderNsu,
            'transaction_nsu' => $request->transactionNsu,
            'slug' => $request->invoiceSlug,
        ], 'consultar o pagamento');

        return new PaymentCheck(
            success: ($data['success'] ?? false) === true,
            paid: ($data['paid'] ?? false) === true,
            amountCents: $this->intOrNull($data['amount'] ?? null),
            paidAmountCents: $this->intOrNull($data['paid_amount'] ?? null),
            captureMethod: $this->firstString($data, ['capture_method']),
            installments: $this->intOrNull($data['installments'] ?? null) ?? 1,
        );
    }

    /**
     * @throws InfinitePayWebhookException
     */
    public function parseWebhook(Request $request): WebhookNotification
    {
        $body = $request->getContent();

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InfinitePayWebhookException('Corpo da notificação inválido.');
        }

        if (! is_array($payload)) {
            throw new InfinitePayWebhookException('Corpo da notificação inválido.');
        }

        $orderNsu = $this->firstString($payload, ['order_nsu']);
        $amountCents = $this->intOrNull($payload['amount'] ?? null);

        if ($orderNsu === null) {
            throw new InfinitePayWebhookException('Notificação sem order_nsu.');
        }

        if ($amountCents === null || $amountCents < 1) {
            throw new InfinitePayWebhookException('Notificação sem valor válido.');
        }

        $transactionNsu = $this->firstString($payload, ['transaction_nsu']);
        $invoiceSlug = $this->firstString($payload, ['invoice_slug', 'slug']);

        if (mb_strlen($orderNsu) > 120
            || mb_strlen((string) $transactionNsu) > 120
            || mb_strlen((string) $invoiceSlug) > 120) {
            throw new InfinitePayWebhookException('Notificação com identificadores inválidos.');
        }

        $event = InfinitePayEvent::fromWebhook($payload);
        $payloadHash = hash('sha256', $body);
        $providedId = $this->firstString($payload, ['event_id', 'id']);

        return new WebhookNotification(
            eventId: $providedId !== null && mb_strlen($providedId) <= 120
                ? $providedId
                : 'ip_'.$event->value.'_'.substr($payloadHash, 0, 40),
            event: $event,
            orderNsu: $orderNsu,
            amountCents: $amountCents,
            payloadHash: $payloadHash,
            transactionNsu: $transactionNsu,
            invoiceSlug: $invoiceSlug,
            paidAmountCents: $this->intOrNull($payload['paid_amount'] ?? null) ?? $amountCents,
            captureMethod: $this->firstString($payload, ['capture_method']),
            occurredAt: now(),
        );
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new InfinitePayException('A InfinitePay não está configurada neste ambiente.');
        }
    }

    /**
     * O bloco `customer` é opcional na API, mas o provedor rejeita o link
     * inteiro (HTTP 422) quando recebe um campo inválido. Por isso só enviamos
     * os campos que ele aceita: dados de demonstração como `*.local` ou
     * telefones fora do padrão E.164 são omitidos em vez de derrubar a cobrança.
     *
     * @return array<string, string>
     */
    private function customerPayload(CheckoutLinkRequest $request): array
    {
        $customer = [];

        $name = $this->cleanString($request->customerName);

        if ($name !== null) {
            $customer['name'] = $name;
        }

        $email = $this->acceptableEmail($request->customerEmail);

        if ($email !== null) {
            $customer['email'] = $email;
        }

        $phone = $this->acceptablePhone($request->customerPhone);

        if ($phone !== null) {
            $customer['phone_number'] = $phone;
        }

        return $customer;
    }

    private function acceptableEmail(?string $value): ?string
    {
        $email = $this->cleanString($value);

        if ($email === null) {
            return null;
        }

        $email = mb_strtolower($email);
        $at = strrpos($email, '@');
        $domain = $at === false ? '' : substr($email, $at + 1);
        $dot = strrpos($domain, '.');
        $tld = $dot === false ? '' : substr($domain, $dot + 1);

        $acceptable = filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && $tld !== ''
            && ! in_array($tld, self::UNROUTABLE_EMAIL_TLDS, true);

        if (! $acceptable) {
            Log::warning('infinitepay.customer_email_ignored', [
                'reason' => 'unroutable_email',
                'domain' => $domain,
            ]);
        }

        return $acceptable ? $email : null;
    }

    /**
     * A InfinitePay escolhe o país do comprador a partir do DDI de
     * `customer.phone_number` e o campo chega travado para edição no checkout.
     * Um número só com DDD + assinante (`+11999999999`) é lido como `+11`, de
     * outro país, e o checkout passa a recusar o telefone. Por isso o valor é
     * sempre reconstruído em E.164 a partir do telefone brasileiro persistido
     * (`+5511999999999`) e omitido quando não é um número nacional válido.
     */
    private function acceptablePhone(?string $value): ?string
    {
        $raw = $this->cleanString($value);

        if ($raw === null) {
            return null;
        }

        $digits = PhoneNumber::normalize($raw);

        if (! PhoneNumber::isValid($digits)) {
            Log::warning('infinitepay.customer_phone_ignored', [
                'reason' => 'invalid_brazilian_phone',
            ]);

            return null;
        }

        return PhoneNumber::toE164($digits, self::CUSTOMER_COUNTRY_CODE);
    }

    private function cleanString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Mensagem legível com os erros de validação devolvidos pelo provedor, para
     * que a recusa apareça no log sem precisar de `dd()` no gateway.
     */
    private function providerErrors(mixed $body): string
    {
        if (! is_array($body)) {
            return '';
        }

        $parts = [];

        $message = $body['message'] ?? null;

        if (is_string($message) && trim($message) !== '') {
            $parts[] = trim($message);
        }

        $errors = $body['errors'] ?? null;

        if (is_array($errors)) {
            array_walk_recursive($errors, static function (mixed $value) use (&$parts): void {
                if (is_string($value) && trim($value) !== '') {
                    $parts[] = trim($value);
                }
            });
        }

        return implode(' | ', array_values(array_unique($parts)));
    }

    private function handle(): string
    {
        return trim((string) config('payments.infinitepay.handle'));
    }

    private function hasApiKey(): bool
    {
        return trim((string) config('payments.infinitepay.api_key')) !== '';
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('payments.infinitepay.base_url', 'https://api.checkout.infinitepay.io'), '/');
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $headers = ['Accept' => 'application/json'];

        if ($this->hasApiKey()) {
            $headers['X-Api-Key'] = trim((string) config('payments.infinitepay.api_key'));
        }

        return $headers;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload, string $action): array
    {
        try {
            $response = Http::asJson()
                ->withHeaders($this->headers())
                ->connectTimeout(5)
                ->timeout(max(1, (int) config('payments.infinitepay.timeout_seconds', 15)))
                ->post($this->baseUrl().$path, $payload);
        } catch (ConnectionException $exception) {
            throw new InfinitePayException('Não foi possível contatar a InfinitePay para '.$action.'.', $exception);
        } catch (Throwable $exception) {
            throw new InfinitePayException('Falha inesperada ao tentar '.$action.' na InfinitePay.', $exception);
        }
        if ($response->failed()) {
            $details = $this->providerErrors($response->json());

            Log::warning('infinitepay.request_rejected', [
                'action' => $action,
                'status' => $response->status(),
                'details' => $details,
            ]);

            throw new InfinitePayException(
                'A InfinitePay recusou a tentativa de '.$action.' (HTTP '.$response->status().')'
                .($details === '' ? '.' : ': '.$details)
            );
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new InfinitePayException('Resposta inválida da InfinitePay ao '.$action.'.');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function extractCheckoutUrl(array $data): ?string
    {
        $candidate = $this->firstString($data, ['url', 'link', 'checkout_url', 'payment_url']);

        if ($candidate === null && is_array($data['data'] ?? null)) {
            $candidate = $this->firstString($data['data'], ['url', 'link', 'checkout_url', 'payment_url']);
        }

        if ($candidate === null || ! str_starts_with($candidate, 'https://')) {
            return null;
        }

        return filter_var($candidate, FILTER_VALIDATE_URL) === false ? null : $candidate;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     */
    private function firstString(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }
}
