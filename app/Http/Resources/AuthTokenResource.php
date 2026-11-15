<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\NewAccessToken;

/**
 * Resposta de login/cadastro: token em texto puro (única vez em que ele é
 * exposto), metadados e a identidade pública do usuário.
 *
 * @mixin NewAccessToken
 */
class AuthTokenResource extends JsonResource
{
    public function __construct(
        NewAccessToken $token,
        private readonly User $user,
    ) {
        parent::__construct($token);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'token' => $this->plainTextToken,
            'token_type' => 'Bearer',
            'abilities' => $this->accessToken->abilities,
            'expires_at' => $this->accessToken->expires_at?->toIso8601String(),
            'user' => new UserResource($this->user),
        ];
    }
}
