<?php

namespace App\Support\Sms;

use App\Enums\SmsVerificationResult;
use App\Support\Sms\Contracts\SmsVerificationProvider;
use Throwable;
use Twilio\Exceptions\RestException;
use Twilio\Rest\Client;

/**
 * Provedor Twilio Verify.
 *
 * O Twilio gera, envia e confere o código; a aplicação nunca armazena o valor.
 * Erros de rede/credenciais viram `SmsVerificationException` para que o
 * cadastro continue disponível (o cliente pode tentar novamente), enquanto
 * códigos errados viram resultados de domínio.
 *
 * @see https://www.twilio.com/docs/verify/api
 */
final class TwilioVerifyProvider implements SmsVerificationProvider
{
    public const NAME = 'twilio_verify';

    /** Código de erro do Twilio para "número máximo de tentativas atingido". */
    private const ERROR_MAX_ATTEMPTS_REACHED = 60202;

    /** Códigos de erro do Twilio para código inválido/expirado no check. */
    private const ERROR_INVALID_CODE = 60200;

    private const ERROR_INVALID_CHANNEL = 60201;

    public function __construct(
        private readonly string $serviceSid,
        private readonly Client $client,
    ) {}

    /**
     * Monta o provedor a partir das credenciais configuradas, devolvendo null
     * quando faltar qualquer uma delas.
     *
     * Prefere API Key (recomendado pela Twilio por ser revogável) e cai para o
     * Auth Token da conta quando só ele estiver preenchido.
     */
    public static function fromConfig(): ?self
    {
        $accountSid = self::stringConfig('services.twilio.account_sid');
        $serviceSid = self::stringConfig('services.twilio.verify_service_sid');
        $apiKeySid = self::stringConfig('services.twilio.api_key_sid');
        $apiKeySecret = self::stringConfig('services.twilio.api_key_secret');
        $authToken = self::stringConfig('services.twilio.auth_token');

        if ($accountSid === null || $serviceSid === null) {
            return null;
        }

        $client = match (true) {
            $apiKeySid !== null && $apiKeySecret !== null => new Client($apiKeySid, $apiKeySecret, $accountSid),
            $authToken !== null => new Client($accountSid, $authToken),
            default => null,
        };

        return $client === null ? null : new self($serviceSid, $client);
    }

    public function send(string $e164Phone): void
    {
        try {
            $this->client->verify->v2->services($this->serviceSid)
                ->verifications
                ->create($e164Phone, 'sms');
        } catch (Throwable $exception) {
            throw new SmsVerificationException(
                SmsVerificationException::sendFailed()->getMessage(),
                previous: $exception,
            );
        }
    }

    public function check(string $e164Phone, string $code): SmsVerificationResult
    {
        try {
            $check = $this->client->verify->v2->services($this->serviceSid)
                ->verificationChecks
                ->create(['to' => $e164Phone, 'code' => $code]);
        } catch (RestException $exception) {
            return $this->mapRestException($exception);
        } catch (Throwable $exception) {
            throw new SmsVerificationException(
                SmsVerificationException::checkFailed()->getMessage(),
                previous: $exception,
            );
        }

        return $check->status === 'approved'
            ? SmsVerificationResult::Approved
            : SmsVerificationResult::Invalid;
    }

    /**
     * Traduz os erros do Verify para o resultado de domínio correspondente.
     */
    private function mapRestException(RestException $exception): SmsVerificationResult
    {
        return match (true) {
            $exception->getCode() === self::ERROR_MAX_ATTEMPTS_REACHED => SmsVerificationResult::TooManyAttempts,
            // A verificação expira no Twilio e deixa de existir (404).
            $exception->getStatusCode() === 404 => SmsVerificationResult::Expired,
            in_array($exception->getCode(), [self::ERROR_INVALID_CODE, self::ERROR_INVALID_CHANNEL], true) => SmsVerificationResult::Invalid,
            default => throw new SmsVerificationException(
                SmsVerificationException::checkFailed()->getMessage(),
                previous: $exception,
            ),
        };
    }

    private static function stringConfig(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
