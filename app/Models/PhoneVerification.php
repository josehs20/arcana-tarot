<?php

namespace App\Models;

use App\Enums\PhoneVerificationStatus;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Cadastro aguardando a confirmação do celular por SMS.
 *
 * @property int $id
 * @property string $phone
 * @property PhoneVerificationStatus $status
 * @property int $attempts
 * @property array<string, mixed>|null $payload
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property string|null $ip_address
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['phone', 'status', 'attempts', 'payload', 'expires_at', 'consumed_at', 'ip_address'])]
class PhoneVerification extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PhoneVerificationStatus::class,
            'attempts' => 'integer',
            'payload' => 'array',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === PhoneVerificationStatus::Pending && $this->consumed_at === null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Número para exibição na confirmação: mantém o DDD e os quatro últimos
     * dígitos, ocultando o resto (o cliente reconhece o número sem expor o
     * celular inteiro em uma tela que pode estar sendo observada).
     */
    public function maskedPhone(): string
    {
        $digits = PhoneNumber::normalize($this->phone);

        if (strlen($digits) < 10) {
            return PhoneNumber::format($digits);
        }

        $areaCode = substr($digits, 0, 2);
        $visible = substr($digits, -4);

        return sprintf('(%s) %s-%s', $areaCode, str_repeat('*', strlen($digits) - 6), $visible);
    }

    /**
     * Dados do cadastro guardados até a confirmação do código.
     *
     * @return array{name: string, email: string, password_hash: string, birth_date: string}
     */
    public function registrationPayload(): array
    {
        $payload = $this->payload ?? [];

        return [
            'name' => is_string($payload['name'] ?? null) ? $payload['name'] : '',
            'email' => is_string($payload['email'] ?? null) ? $payload['email'] : '',
            'password_hash' => is_string($payload['password_hash'] ?? null) ? $payload['password_hash'] : '',
            // A data de nascimento é obrigatória no cadastro (18+) e precisa
            // sobreviver à etapa do SMS: sem ela a conta nasceria incompleta.
            'birth_date' => is_string($payload['birth_date'] ?? null) ? $payload['birth_date'] : '',
        ];
    }
}
