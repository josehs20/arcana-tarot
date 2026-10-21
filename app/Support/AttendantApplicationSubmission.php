<?php

namespace App\Support;

use App\Enums\ApplicationStatus;
use App\Models\AttendantApplication;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AttendantApplicationSubmission
{
    /**
     * Após uma rejeição, a pessoa pode tentar novamente somente depois deste prazo.
     */
    public const REAPPLICATION_COOLDOWN_DAYS = 30;

    /**
     * Cria uma candidatura pending. Nunca cria usuário, papel ou credenciais.
     *
     * @param  array<string, mixed>  $data
     */
    public function submit(array $data): AttendantApplication
    {
        $email = Str::lower(trim((string) $data['email']));

        $this->ensureEmailCanApply($email);
        $now = now();

        return AttendantApplication::query()->create([
            'name' => $data['name'],
            'email' => $email,
            'phone' => $data['phone'],
            'city' => filled($data['city'] ?? null) ? $data['city'] : null,
            'state' => filled($data['state'] ?? null) ? $data['state'] : null,
            'experience_summary' => $data['experience_summary'],
            'message' => $data['message'],
            'specialties' => $this->parseSpecialties($data['specialties'] ?? null),
            'portfolio_url' => filled($data['portfolio_url'] ?? null) ? $data['portfolio_url'] : null,
            'status' => ApplicationStatus::Pending,
            'submitted_at' => $now,
            'consent_at' => $now,
            'status_changed_at' => $now,
        ]);
    }

    private function ensureEmailCanApply(string $email): void
    {
        $latest = AttendantApplication::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->latest('id')
            ->first();

        if (! $latest) {
            return;
        }

        if (in_array($latest->status, [ApplicationStatus::Pending, ApplicationStatus::UnderReview], true)) {
            throw ValidationException::withMessages([
                'email' => 'Já existe uma candidatura em análise para este e-mail. Aguarde o retorno da equipe antes de enviar outra.',
            ]);
        }

        if ($latest->status === ApplicationStatus::Approved) {
            throw ValidationException::withMessages([
                'email' => 'Este e-mail já possui uma candidatura aprovada. Fale com a equipe pelos canais oficiais se precisar de ajuda.',
            ]);
        }

        if ($latest->status === ApplicationStatus::Rejected) {
            $decidedAt = $latest->reviewed_at ?? $latest->updated_at;

            if ($decidedAt && $decidedAt->copy()->addDays(self::REAPPLICATION_COOLDOWN_DAYS)->isFuture()) {
                throw ValidationException::withMessages([
                    'email' => 'Você poderá enviar uma nova candidatura após '
                        .$decidedAt->copy()->addDays(self::REAPPLICATION_COOLDOWN_DAYS)->format('d/m/Y')
                        .'.',
                ]);
            }
        }
    }

    /**
     * @return list<string>|null
     */
    private function parseSpecialties(?string $raw): ?array
    {
        $items = collect(explode(',', (string) $raw))
            ->map(fn (string $item) => Str::of($item)->squish()->title()->toString())
            ->filter()
            ->unique(fn (string $item) => Str::lower($item))
            ->values();

        return $items->isEmpty() ? null : $items->all();
    }
}
