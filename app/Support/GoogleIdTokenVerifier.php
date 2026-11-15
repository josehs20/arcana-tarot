<?php

namespace App\Support;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;
use UnexpectedValueException;

/**
 * Valida o ID token emitido pelo SDK oficial do Google no app móvel.
 *
 * A assinatura é conferida localmente contra o JWKS publicado pelo Google;
 * depois checamos emissor, audiência (client IDs configurados) e
 * `email_verified`. Nenhum e-mail/nome enviado pelo app é usado: a identidade
 * vem exclusivamente das claims assinadas.
 *
 * Usa `firebase/php-jwt`, que já acompanha o Socialite. O token do Google não
 * é armazenado nem vira token de sessão da API.
 */
final class GoogleIdTokenVerifier
{
    private const JWKS_CACHE_KEY = 'api.google.jwks';

    private const JWKS_CACHE_SECONDS = 3600;

    private const CLOCK_SKEW_SECONDS = 60;

    /** @var list<string> */
    private const ISSUERS = [
        'accounts.google.com',
        'https://accounts.google.com',
    ];

    /**
     * @return array{google_id: string, email: string, name: string, email_verified: bool}
     */
    public function verify(string $idToken): array
    {
        $idToken = trim($idToken);
        $clientIds = AuthenticationSettings::googleClientIds();

        if ($idToken === '' || $clientIds === []) {
            throw GoogleAccountException::invalidToken();
        }

        try {
            $payload = $this->decode($idToken);
        } catch (Throwable) {
            // Não propague detalhes de criptografia/claims para o cliente.
            throw GoogleAccountException::invalidToken();
        }

        if (! in_array((string) ($payload->iss ?? ''), self::ISSUERS, true)) {
            throw GoogleAccountException::invalidToken();
        }

        if (! $this->audienceIsAllowed($payload->aud ?? null, $clientIds)) {
            throw GoogleAccountException::invalidToken();
        }

        $subject = trim((string) ($payload->sub ?? ''));
        $email = Str::lower(trim((string) ($payload->email ?? '')));

        if (
            $subject === ''
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || filter_var($payload->email_verified ?? false, FILTER_VALIDATE_BOOLEAN) !== true
        ) {
            throw GoogleAccountException::invalidToken();
        }

        return [
            'google_id' => $subject,
            'email' => $email,
            'name' => Str::squish((string) ($payload->name ?? '')),
            'email_verified' => true,
        ];
    }

    /**
     * @param  list<string>  $clientIds
     */
    private function audienceIsAllowed(mixed $audience, array $clientIds): bool
    {
        $audiences = is_array($audience) ? $audience : [$audience];

        foreach ($audiences as $value) {
            if (is_string($value) && in_array($value, $clientIds, true)) {
                return true;
            }
        }

        return false;
    }

    private function decode(string $idToken): object
    {
        $previousLeeway = JWT::$leeway;
        JWT::$leeway = self::CLOCK_SKEW_SECONDS;

        try {
            try {
                return JWT::decode($idToken, $this->keys());
            } catch (UnexpectedValueException $exception) {
                // O Google pode rotacionar chaves antes do cache expirar.
                if (! str_contains($exception->getMessage(), 'kid')) {
                    throw $exception;
                }

                return JWT::decode($idToken, $this->keys(refresh: true));
            }
        } finally {
            JWT::$leeway = $previousLeeway;
        }
    }

    /**
     * @return array<string, Key>
     */
    private function keys(bool $refresh = false): array
    {
        if ($refresh) {
            Cache::forget(self::JWKS_CACHE_KEY);
        }

        $jwks = Cache::remember(
            self::JWKS_CACHE_KEY,
            self::JWKS_CACHE_SECONDS,
            function (): array {
                $response = Http::timeout(5)
                    ->acceptJson()
                    ->get((string) config(
                        'services.google.jwks_uri',
                        'https://www.googleapis.com/oauth2/v3/certs',
                    ));

                if (! $response->successful()) {
                    throw GoogleAccountException::invalidToken();
                }

                $payload = $response->json();

                if (! is_array($payload) || ! isset($payload['keys']) || ! is_array($payload['keys'])) {
                    throw GoogleAccountException::invalidToken();
                }

                return $payload;
            },
        );

        return JWK::parseKeySet($jwks, 'RS256');
    }
}
