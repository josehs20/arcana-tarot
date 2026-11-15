<?php

namespace App\Support;

use RuntimeException;

/**
 * Falha esperada no vínculo/login com o Google.
 *
 * A mensagem é exibida ao cliente no formulário de login; nenhum detalhe do
 * provedor (códigos, tokens) é propagado para a tela.
 */
class GoogleAccountException extends RuntimeException
{
    public function __construct(string $message, private readonly int $status = 422)
    {
        parent::__construct($message);
    }

    /**
     * Código HTTP sugerido para a API. O fluxo web ignora este valor e sempre
     * volta para a tela de login com a mensagem.
     */
    public function status(): int
    {
        return $this->status;
    }

    public static function missingIdentity(): self
    {
        return new self('Não foi possível identificar sua conta Google. Tente novamente.');
    }

    public static function unverifiedEmail(): self
    {
        return new self('O e-mail da sua conta Google não está verificado. Confirme o e-mail no Google e tente novamente.');
    }

    public static function alreadyLinked(): self
    {
        return new self('Este e-mail já está vinculado a outra conta Google. Entre com e-mail e senha.', 409);
    }

    public static function inactive(): self
    {
        return new self('Esta conta está desativada. Fale com o suporte para reativá-la.', 403);
    }

    public static function invalidToken(): self
    {
        return new self('Não foi possível validar sua conta Google. Tente novamente.');
    }

    public static function unavailable(): self
    {
        return new self('O login com Google não está disponível no aplicativo.', 503);
    }
}
