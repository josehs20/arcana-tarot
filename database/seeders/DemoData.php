<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\ConsultationPricing;
use InvalidArgumentException;

/**
 * Identificadores estáveis dos dados de demonstração.
 *
 * Os e-mails são as chaves de idempotência. Nomes, senhas de contas já
 * existentes e escolhas feitas durante testes manuais não são sobrescritos.
 */
final class DemoData
{
    public const ADMIN_EMAIL = 'admin@arcana.local';

    /**
     * @var array<string, array{email: string, name: string, display_name: string, bio: string, specialties: list<string>, request_sound: string, commission_rate_bps: int, availability: string}>
     */
    public const ATTENDANTS = [
        'marina' => [
            'email' => 'marina.luz@arcana.local',
            'name' => 'Marina Luz',
            'display_name' => 'Marina Luz',
            'bio' => 'Leituras acolhedoras de Tarot com foco em amor, carreira e autoconhecimento.',
            'specialties' => ['Amor', 'Carreira', 'Autoconhecimento'],
            'request_sound' => 'siren',
            'commission_rate_bps' => 3000,
            'availability' => 'online',
        ],
        'rafael' => [
            'email' => 'rafael.monteiro@arcana.local',
            'name' => 'Rafael Monteiro',
            'display_name' => 'Rafael Monteiro',
            'bio' => 'Atendimento objetivo para decisões profissionais, estudos e projetos pessoais.',
            'specialties' => ['Carreira', 'Prosperidade', 'Estudos'],
            'request_sound' => 'siren',
            'commission_rate_bps' => 2500,
            'availability' => 'offline',
        ],
        'iris' => [
            'email' => 'iris.campos@arcana.local',
            'name' => 'Íris Campos',
            'display_name' => 'Íris Campos',
            'bio' => 'Leituras espiritualizadas e cuidadosas, com escuta atenta e linguagem simples.',
            'specialties' => ['Espiritualidade', 'Família', 'Autoconhecimento'],
            'request_sound' => 'siren',
            'commission_rate_bps' => 2000,
            'availability' => 'online',
        ],
    ];

    /**
     * Contas de cliente da demonstração.
     *
     * As chaves `pending` e `active` são nomes herdados dos cenários antigos de
     * solicitação pendente e consulta ativa. O seeder não abre atendimentos em
     * nome de contas que não estão logadas, então essas duas contas hoje têm
     * apenas créditos: elas servem para o cliente iniciar a consulta pelo site.
     *
     * @var array<string, array{email: string, name: string}>
     */
    public const CLIENTS = [
        'free' => [
            'email' => 'cliente.livre@arcana.local',
            'birth_date' => '1995-04-18',
            'name' => 'Clara Freitas',
        ],
        'no_balance' => [
            'email' => 'cliente.sem-saldo@arcana.local',
            'birth_date' => '1988-11-02',
            'name' => 'Caio Nogueira',
        ],
        'history' => [
            'email' => 'cliente.historico@arcana.local',
            'birth_date' => '1991-07-25',
            'name' => 'Helena Prado',
        ],
        'pending' => [
            'email' => 'cliente.pendente@arcana.local',
            'birth_date' => '1979-01-30',
            'name' => 'Paulo Mendes',
        ],
        'active' => [
            'email' => 'cliente.ativo@arcana.local',
            'birth_date' => '2000-09-14',
            'name' => 'Alice Ribeiro',
        ],
    ];

    public static function password(): string
    {
        $password = trim((string) config('demo.password'));

        // if (strlen($password) < 8) {
        //     throw new InvalidArgumentException(
        //         'ARCANA_DEMO_PASSWORD deve ter pelo menos 8 caracteres para os seeders de demonstração.',
        //     );
        // }

        return $password;
    }

    public static function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    public static function creditUnitPriceCents(): int
    {
        $price = app(ConsultationPricing::class)->creditUnitPriceCents();

        if ($price === null) {
            throw new InvalidArgumentException(
                'Configure credit_unit_price_cents antes de semear os dados financeiros de demonstração.',
            );
        }

        return $price;
    }

    /**
     * Créditos por minuto usados no cenário de demonstração. O preço real é
     * sempre por atendente; aqui servem apenas para gerar preços iniciais
     * distintos por modalidade.
     */
    public const DEMO_CREDITS_PER_MINUTE = [
        'text' => 1,
        'audio' => 2,
        'video' => 3,
    ];

    /**
     * Preço por minuto de demonstração em centavos para a modalidade.
     */
    public static function modalityPricePerMinuteCents(string $modality): int
    {
        $credits = self::DEMO_CREDITS_PER_MINUTE[$modality] ?? 1;

        return $credits * self::creditUnitPriceCents();
    }

    public static function minimumBalanceMinutes(): int
    {
        return app(ConsultationPricing::class)->minimumBalanceMinutes();
    }
}
