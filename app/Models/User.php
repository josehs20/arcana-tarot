<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'email_verified_at', 'password', 'remember_token', 'role', 'is_active'])]
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
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
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
}
