<?php

namespace App\Support\Legacy;

use App\Enums\LegacyPasswordFormat;
use App\Support\PhoneNumber;
use Illuminate\Support\Str;

/**
 * Converte uma linha do dump em `LegacyUserRecord`, recusando registros que
 * não podem virar usuário no Arcana.
 *
 * O mapa de tabela/coluna vem do `LegacyImportPlan`; nenhum nome de coluna
 * ausente é inventado.
 */
final class LegacyUserRecordMapper
{
    private const MAX_NAME_LENGTH = 255;

    public function __construct(private readonly LegacyImportPlan $plan) {}

    /**
     * @return LegacyUserRecord|string registro normalizado ou motivo da recusa
     */
    public function map(LegacyRow $row, int $externalBalanceCents = 0, ?LegacyProfileRecord $profile = null): LegacyUserRecord|string
    {
        $email = Str::lower($row->text($this->plan->emailColumn));

        if ($email === '') {
            return 'registro sem e-mail';
        }

        if (mb_strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'e-mail inválido: '.mb_substr($email, 0, 80);
        }

        $name = $row->text($this->plan->nameColumn);

        if ($name === '') {
            $name = $this->nameFromEmail($email);
        }

        $balance = $externalBalanceCents;

        if ($this->creditsComeFromUserTable()) {
            $balance = $this->parseCents($row->get($this->plan->creditsBalanceColumn));

            if ($balance === null) {
                return "saldo inválido em {$this->plan->creditsBalanceColumn}";
            }
        }

        $password = $row->get($this->plan->passwordColumn) ?? '';

        return new LegacyUserRecord(
            legacyKey: $this->legacyKey($row, $email),
            email: $email,
            name: mb_substr($name, 0, self::MAX_NAME_LENGTH),
            passwordValue: $password,
            passwordFormat: LegacyPasswordFormat::detect($password),
            phone: $this->phone($row, $profile),
            balanceCents: $balance,
            birthDate: $profile?->birthDate,
            gender: $profile?->gender,
            profileMatched: $profile !== null,
        );
    }

    /**
     * Interpreta um valor monetário legado em centavos, sem usar float.
     *
     * Aceita `1234.56`, `1234,56` e `1.234,56`; valores vazios valem zero e
     * valores acima da precisão de centavos são arredondados.
     */
    public function parseCents(?string $value): ?int
    {
        $raw = trim((string) $value);

        if ($raw === '') {
            return 0;
        }

        $raw = str_replace([' ', 'R$'], '', $raw);

        if (str_contains($raw, ',')) {
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        }

        if (preg_match('/^(-)?(\d{1,15})(?:\.(\d+))?$/', $raw, $matches) !== 1) {
            return null;
        }

        $whole = (int) $matches[2];

        if ($whole > intdiv(PHP_INT_MAX - 100, 100)) {
            return null;
        }

        $decimals = $matches[3] ?? '';
        $cents = (int) str_pad(substr($decimals, 0, 2), 2, '0', STR_PAD_RIGHT);

        if (strlen($decimals) > 2 && (int) $decimals[2] >= 5) {
            $cents++;
        }

        $total = $whole * 100 + $cents;

        return ($matches[1] ?? '') === '-' ? -$total : $total;
    }

    private function creditsComeFromUserTable(): bool
    {
        return $this->plan->hasCredits()
            && $this->plan->userTable !== null
            && $this->plan->creditsTable->is($this->plan->userTable);
    }

    private function legacyKey(LegacyRow $row, string $email): string
    {
        $key = $row->text($this->plan->legacyKeyColumn);

        return $key === '' ? $email : $key;
    }

    /**
     * Telefone da tabela de usuários; quando ela não tem o dado, usa o da
     * tabela de dados complementares. Só valores válidos com DDD passam.
     */
    private function phone(LegacyRow $row, ?LegacyProfileRecord $profile): ?string
    {
        $phone = PhoneNumber::normalize($row->get($this->plan->phoneColumn));

        if (PhoneNumber::isValid($phone)) {
            return $phone;
        }

        $fallback = $profile?->phone;

        return $fallback !== null && PhoneNumber::isValid($fallback) ? $fallback : null;
    }

    private function nameFromEmail(string $email): string
    {
        $localPart = Str::before($email, '@');

        return Str::headline(str_replace(['.', '_', '-', '+'], ' ', $localPart));
    }
}
