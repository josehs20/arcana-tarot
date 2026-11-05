<?php

namespace App\Support;

use App\Enums\AttendantAvailability;
use App\Enums\ConsultationModality;
use App\Enums\UserRole;
use App\Models\AttendantModalityPrice;
use App\Models\AttendantProfile;
use App\Models\AuditLog;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Cadastro e edição administrativa de atendentes.
 *
 * Concentra as regras que o comando `arcana:create-attendant` já aplicava: o
 * perfil nasce offline (a presença online só é declarada pelo próprio
 * atendente), texto é a modalidade base e cada preço por minuto pertence ao
 * profissional, nunca a uma tarifa global.
 */
final class AttendantAccountManager
{
    /**
     * Cria conta e perfil profissional em uma única transação.
     *
     * @param  array{name: string, email: string, phone: string|null, birth_date: string|null, password: string, display_name: string, bio: string|null, specialties: array<int, string>, photo_path: string|null, commission_rate_bps: int, is_published: bool}  $attributes
     * @param  array<string, int>  $prices  Preço por minuto em centavos, indexado pelo valor da modalidade.
     * @return array{0: User, 1: AttendantProfile}
     */
    public function create(User $actor, array $attributes, array $prices): array
    {
        if (! $actor->is_active || ! $actor->isAdmin()) {
            throw new DomainException('Somente administradores ativos podem cadastrar atendentes.');
        }

        return DB::transaction(function () use ($actor, $attributes, $prices): array {
            $user = User::query()->create([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'phone' => $attributes['phone'],
                'birth_date' => $attributes['birth_date'],
                'email_verified_at' => now(),
                'password' => $attributes['password'],
                'role' => UserRole::Attendant->value,
                'is_active' => true,
            ]);

            $profile = AttendantProfile::query()->create([
                'user_id' => $user->getKey(),
                'display_name' => $attributes['display_name'],
                'bio' => $attributes['bio'],
                'photo_path' => $attributes['photo_path'],
                'specialties' => $attributes['specialties'],
                'offered_modalities' => array_values(array_unique(array_merge(
                    [ConsultationModality::Text->value],
                    array_keys($prices),
                ))),
                'commission_rate_bps' => $attributes['commission_rate_bps'],
                'is_published' => $attributes['is_published'],
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
                'actor_id' => $actor->getKey(),
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
    }

    /**
     * Atualiza os dados públicos do perfil.
     *
     * Despublicar também derruba o atendente para offline: um perfil fora do
     * site não pode continuar recebendo solicitações pelo diretório.
     *
     * @param  array{display_name: string, bio: string|null, specialties: array<int, string>, photo_path: string|null, is_published: bool}  $attributes
     */
    public function updateProfile(AttendantProfile $profile, User $actor, array $attributes): AttendantProfile
    {
        if (! $actor->is_active || ! $actor->isAdmin()) {
            throw new DomainException('Somente administradores ativos podem editar perfis de atendentes.');
        }

        return DB::transaction(function () use ($profile, $actor, $attributes): AttendantProfile {
            $locked = AttendantProfile::query()->lockForUpdate()->findOrFail($profile->getKey());

            $before = [
                'display_name' => $locked->display_name,
                'bio' => $locked->bio,
                'specialties' => $locked->specialties ?? [],
                'photo_path' => $locked->photo_path,
                'is_published' => $locked->is_published,
                'availability' => $locked->availability->value,
            ];

            $locked->fill([
                'display_name' => $attributes['display_name'],
                'bio' => $attributes['bio'],
                'specialties' => $attributes['specialties'],
                'photo_path' => $attributes['photo_path'],
                'is_published' => $attributes['is_published'],
            ]);

            if (! $attributes['is_published']) {
                $locked->availability = AttendantAvailability::Offline->value;
            }

            $locked->save();

            AuditLog::query()->create([
                'actor_id' => $actor->getKey(),
                'action' => 'attendant.profile_updated',
                'subject_type' => $locked->getMorphClass(),
                'subject_id' => $locked->getKey(),
                'metadata' => [
                    'before' => $before,
                    'after' => [
                        'display_name' => $locked->display_name,
                        'bio' => $locked->bio,
                        'specialties' => $locked->specialties ?? [],
                        'photo_path' => $locked->photo_path,
                        'is_published' => $locked->is_published,
                        'availability' => $locked->availability->value,
                    ],
                ],
            ]);

            return $locked;
        });
    }
}
