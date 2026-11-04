<?php

namespace App\Console\Commands;

use App\Enums\AttendantAvailability;
use App\Enums\UserRole;
use App\Models\AttendantProfile;
use App\Models\AuditLog;
use App\Models\User;
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
        {--publish}';

    protected $description = 'Cria manualmente um atendente e seu perfil, sem vínculo automático com candidaturas';

    public function handle(): int
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
        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'display_name' => $displayName,
            'password' => (string) $password,
            'commission_rate' => $commissionRate,
        ], [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'display_name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'min:8'],
            'commission_rate' => ['required', 'integer', 'between:0,10000'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

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
                'is_published' => $published,
                'availability' => AttendantAvailability::Offline->value,
            ]);

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
                ],
            ]);

            return [$user, $profile];
        });

        $this->info("Atendente criado: {$user->name} <{$user->email}>");
        $this->line('Perfil: '.($profile->is_published ? 'publicado' : 'não publicado'));
        $this->line('Taxa inicial: '.Rate::formatBps($profile->commission_rate_bps));

        if (! $profile->is_published) {
            $this->warn('O perfil permanece offline e não publicado até a ativação explícita pela operação.');
        }

        if ($generated) {
            $this->warn("Senha gerada (guarde-a agora): {$password}");
        }

        return self::SUCCESS;
    }
}
