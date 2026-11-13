<?php

namespace App\Support;

use Illuminate\Mail\Mailables\Address;
use Throwable;

/**
 * Identidade do remetente dos e-mails transacionais.
 *
 * O endereço continua vindo do `.env` (`MAIL_FROM_ADDRESS`), porque precisa
 * estar verificado no provedor. O nome exibido, porém, acompanha o campo
 * "Nome do site" de /admin/conteudo: `MAIL_FROM_NAME` envelhece assim que a
 * identidade é editada no painel, e obrigar o operador a alterar o `.env` (e
 * recarregar a configuração) a cada troca de nome é um erro fácil de cometer.
 *
 * O `.env` permanece como fallback para instalações que ainda não salvaram a
 * identidade no banco.
 */
final class MailIdentity
{
    /**
     * Remetente padrão dos e-mails da aplicação.
     */
    public static function from(): Address
    {
        return new Address(
            address: (string) config('mail.from.address'),
            name: self::siteName(),
        );
    }

    /**
     * Nome exibido do remetente.
     *
     * Uma instância nova de SiteContent é usada de propósito: o singleton
     * memoiza as configurações e, em um worker de fila de vida longa, manteria
     * o nome antigo mesmo depois da alteração no painel. Se o banco não
     * estiver disponível (por exemplo, ao testar apenas o SMTP), cai para o
     * nome configurado no `.env` em vez de impedir o envio.
     */
    public static function siteName(): string
    {
        try {
            return SiteContent::currentName();
        } catch (Throwable) {
            $fallback = trim((string) config('mail.from.name'));

            return $fallback !== ''
                ? $fallback
                : (string) config('site.profile_keys.site_name', (string) config('app.name'));
        }
    }
}
