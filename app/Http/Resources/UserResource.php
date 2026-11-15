<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Identidade pública do usuário autenticado.
 *
 * Nunca inclua senha, remember_token ou qualquer dado financeiro: o payload é
 * o mesmo para cliente, atendente e administrador, e o app decide as telas
 * pelo campo `role`.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'birth_date' => $this->birth_date?->toDateString(),
            'gender' => $this->gender?->value,
            'role' => $this->role->value,
            'is_active' => $this->is_active,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'phone_verified_at' => $this->phone_verified_at?->toIso8601String(),
            'profile_complete' => $this->hasCompleteProfile(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
