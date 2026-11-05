<?php

namespace App\Enums;

/**
 * Categorias de dado pessoal ou contato externo bloqueadas no chat.
 *
 * A mesma lista alimenta a detecção no servidor, o filtro do painel
 * administrativo e os rótulos exibidos nas ocorrências.
 */
enum SensitiveDataCategory: string
{
    case Cpf = 'cpf';
    case Cnpj = 'cnpj';
    case Email = 'email';
    case Phone = 'phone';
    case WhatsApp = 'whatsapp';
    case ExternalLink = 'external_link';
    case HiddenContact = 'hidden_contact';

    public function label(): string
    {
        return match ($this) {
            self::Cpf => 'CPF',
            self::Cnpj => 'CNPJ',
            self::Email => 'E-mail',
            self::Phone => 'Telefone',
            self::WhatsApp => 'WhatsApp',
            self::ExternalLink => 'Link externo',
            self::HiddenContact => 'Contato disfarçado',
        };
    }

    /**
     * Rótulo curto usado em filtros e indicadores compactos.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Cpf => 'CPF',
            self::Cnpj => 'CNPJ',
            self::Email => 'E-mail',
            self::Phone => 'Telefone',
            self::WhatsApp => 'WhatsApp',
            self::ExternalLink => 'Link',
            self::HiddenContact => 'Disfarçado',
        };
    }
}
