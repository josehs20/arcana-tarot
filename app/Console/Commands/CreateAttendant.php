<?php

namespace App\Console\Commands;

use App\Enums\AttendantAvailability;
use App\Enums\ConsultationModality;
use App\Enums\UserRole;
use App\Models\AttendantModalityPrice;
use App\Models\AttendantProfile;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\ConsultationModalityCatalog;
use App\Support\Money;
use App\Support\Rate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CreateAttendant extends Command
{
    protected $signature = 'arcana:create-attendant
        {--name=}
        {--email=}
        {--password=}
        {--display-name=}
        {--commission-rate=0}
        {--price-text= : Preço por minuto em centavos para atendimento por texto}
        {--price-audio= : Preço por minuto em centavos para atendimento por áudio}
        {--price-video= : Preço por minuto em centavos para atendimento por vídeo}
        {--publish}';

    protected $description = 'Cria manualmente um atendente e seu perfil, sem vínculo automático com candidaturas';

    public function handle(ConsultationModalityCatalog $catalog): int
    {
        $name = trim((string) ($this->option('name') ?: $this->ask('Nome')));
        $email = Str::lower(trim((string) ($this->option('email') ?: $this->ask('E-mail'))));
        $displayName = trim((string) ($this->option('display-name') ?: $name));

        $password = $this->option('password');
        $generated = false;

        if ($password === null) {
            $password = $this->secret('Senha (deixe em branco para gerar uma aleatória)');
        }

        if ($password === null || $password === '') {
            $password = Str::password(16);
            $generated = true;
        }

        $commissionRate = Rate::parsePercentToBps((string) $this->option('commission-rate'));

        $priceOptions = [
            ConsultationModality::Text->value => $this->option('price-text'),
            ConsultationModality::Audio->value => $this->option('price-audio'),
            ConsultationModality::Video->value => $this->option('price-video'),
        ];

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'display_name' => $displayName,
            'password' => (string) $password,
            'commission_rate' => $commissionRate,
            'price_text' => $priceOptions[ConsultationModality::Text->value],
            'price_audio' => $priceOptions[ConsultationModality::Audio->value],
            'price_video' => $priceOptions[ConsultationModality::Video->value],
        ], [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'display_name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'min:8'],
            'commission_rate' => ['required', 'integer', 'between:0,10000'],
            'price_text' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'price_audio' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'price_video' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        /** @var array<string, int> $prices */
        $prices = [];

        foreach ($priceOptions as $value => $raw) {
            if ($raw === null || $raw === '') {
                continue;
            }

            $modality = ConsultationModality::from($value);

            if (! $catalog->isEnabled($modality)) {
                $this->error('A modalidade '.$modality->label().' está desativada na infraestrutura.');

                return self::FAILURE;
            }

            $prices[$modality->value] = (int) $raw;
        }

        if ($prices !== [] && ! array_key_exists(ConsultationModality::Text->value, $prices)) {
            $this->error('Informe --price-text: o atendimento por texto é a modalidade base e precisa de preço.');

            return self::FAILURE;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->error("Já existe um usuário com o e-mail {$email}.");

            return self::FAILURE;
        }

        $published = (bool) $this->option('publish');

        [$user, $profile] = DB::transaction(function () use (
            $name,
            $email,
            $displayName,
            $password,
            $commissionRate,
            $published,
            $prices,
        ): array {
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'email_verified_at' => now(),
                'password' => Hash::make((string) $password),
                'role' => UserRole::Attendant->value,
                'is_active' => true,
            ]);

            $profile = AttendantProfile::query()->create([
                'user_id' => $user->getKey(),
                'display_name' => $displayName,
                'commission_rate_bps' => (int) $commissionRate,
                'offered_modalities' => array_values(array_unique(array_merge(
                    [ConsultationModality::Text->value],
                    array_keys($prices),
                ))),
                'is_published' => $published,
                'availability' => AttendantAvailability::Offline->value,
            ]);

            foreach ($prices as $modality => $cents) {
                AttendantModalityPrice::query()->create([
                    'attendant_profile_id' => $profile->getKey(),
                    'modality' => $modality,
                    'price_per_minute_cents' => $cents,
                ]);
            }

            AuditLog::query()->create([
                'actor_id' => null,
                'action' => 'attendant.created_by_operator',
                'subject_type' => $profile->getMorphClass(),
                'subject_id' => $profile->getKey(),
                'metadata' => [
                    'user_id' => $user->getKey(),
                    'email' => $user->email,
                    'commission_rate_bps' => $profile->commission_rate_bps,
                    'is_published' => $profile->is_published,
                    'prices' => $prices,
                ],
            ]);

            return [$user, $profile];
        });

        $this->info("Atendente criado: {$user->name} <{$user->email}>");
        $this->line('Perfil: '.($profile->is_published ? 'publicado' : 'não publicado'));
        $this->line('Taxa inicial: '.Rate::formatBps($profile->commission_rate_bps));

        if ($prices !== []) {
            foreach ($prices as $modality => $cents) {
                $label = ConsultationModality::from($modality)->label();

                $this->line('Preço '.$label.': '.Money::format($cents).' por minuto');
            }
        } else {
            $this->warn('Sem preço por minuto, este atendente não pode receber solicitações. Defina os valores em Admin → Modalidades.');
        }

        if (! $profile->is_published) {
            $this->warn('O perfil permanece offline e não publicado até a ativação explícita pela operação.');
        }

        if ($generated) {
            $this->warn("Senha gerada (guarde-a agora): {$password}");
        }

        return self::SUCCESS;
    }
}
