<?php

namespace App\Models;

use App\Enums\Gender;
use App\Enums\ThemePreference;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'phone', 'phone_verified_at', 'birth_date', 'gender', 'email_verified_at', 'google_id', 'password', 'remember_token', 'role', 'is_active', 'theme_preference', 'chat_send_on_enter', 'chat_sound_enabled'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'birth_date' => 'date',
            'gender' => Gender::class,
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'theme_preference' => ThemePreference::class,
            'chat_send_on_enter' => 'boolean',
            'chat_sound_enabled' => 'boolean',
        ];
    }

    public function attendantProfile(): HasOne
    {
        return $this->hasOne(AttendantProfile::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function clientConsultations(): HasMany
    {
        return $this->hasMany(Consultation::class, 'client_id');
    }

    public function attendantConsultations(): HasMany
    {
        return $this->hasMany(Consultation::class, 'attendant_id');
    }

    public function sentMessages(): HasMany
    {
        return $this->hasMany(ConsultationMessage::class, 'sender_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function reviewedApplications(): HasMany
    {
        return $this->hasMany(AttendantApplication::class, 'reviewed_by');
    }

    public function commissionEntries(): HasMany
    {
        return $this->hasMany(CommissionLedgerEntry::class, 'attendant_id');
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isAttendant(): bool
    {
        return $this->role === UserRole::Attendant;
    }

    public function isClient(): bool
    {
        return $this->role === UserRole::Client;
    }

    /**
     * Rota inicial do painel conforme o papel, usada após login/cadastro.
     */
    public function dashboardRouteName(): string
    {
        return match ($this->role) {
            UserRole::Admin => 'admin.dashboard',
            UserRole::Attendant => 'attendant.dashboard',
            UserRole::Client => 'client.dashboard',
        };
    }

    /**
     * Data de nascimento no formato brasileiro, ou null quando não informada
     * (contas antigas e cadastros via Google).
     */
    public function birthDateLabel(): ?string
    {
        return $this->birth_date?->format('d/m/Y');
    }

    /**
     * Sexo já formatado para exibição ("Feminino"), ou null quando não
     * informado (contas antigas, Google e importações sem o dado).
     */
    public function genderLabel(): ?string
    {
        return $this->gender?->label();
    }

    /**
     * Idade em anos completos, calculada a partir da data de nascimento.
     */
    public function age(): ?int
    {
        return $this->birth_date?->age;
    }

    /**
     * Idade já formatada para exibição ("34 anos"), ou null sem data.
     */
    public function ageLabel(): ?string
    {
        $age = $this->age();

        return $age === null ? null : $age.' '.($age === 1 ? 'ano' : 'anos');
    }

    /**
     * Indica se o perfil ainda precisa de dados obrigatórios do cadastro.
     */
    public function hasCompleteProfile(): bool
    {
        return $this->birth_date !== null;
    }
}
