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
        return new self('Este e-mail já está vinculado a outra conta Google. Entre com e-mail e senha.');
    }

    public static function inactive(): self
    {
        return new self('Esta conta está desativada. Fale com o suporte para reativá-la.');
    }
}
