<?php

namespace Tests\Feature;

use App\Enums\AttendantAvailability;
use App\Enums\ConsultationModality;
use App\Enums\UserRole;
use App\Models\AttendantProfile;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\ConsultationModalityCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendantOnboardingCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_create_published_attendant_with_explicit_rate(): void
    {
        $this->artisan('arcana:create-attendant', [
            '--name' => 'Atendente Operacional',
            '--email' => 'atendente.operacional@example.com',
            '--password' => 'senha-segura-123',
            '--display-name' => 'Atendente Operacional',
            '--commission-rate' => '25',
            '--publish' => true,
        ])->assertExitCode(0);

        $user = User::query()
            ->where('email', 'atendente.operacional@example.com')
            ->sole();

        $this->assertSame(UserRole::Attendant, $user->role);
        $this->assertTrue($user->is_active);

        $profile = $user->attendantProfile;
        $this->assertNotNull($profile);
        $this->assertTrue($profile->is_published);
        $this->assertSame(AttendantAvailability::Offline, $profile->availability);
        $this->assertSame(2500, $profile->commission_rate_bps);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'attendant.created_by_operator',
            'subject_id' => $profile->id,
        ]);
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'attendant.created_by_operator')
            ->count());
    }

    public function test_command_rejects_duplicate_email_and_invalid_rate(): void
    {
        User::factory()->create(['email' => 'existente@example.com']);

        $this->artisan('arcana:create-attendant', [
            '--name' => 'Duplicado',
            '--email' => 'existente@example.com',
            '--password' => 'senha-segura-123',
            '--commission-rate' => '10',
        ])->assertExitCode(1);

        $this->artisan('arcana:create-attendant', [
            '--name' => 'Taxa Inválida',
            '--email' => 'taxa.invalida@example.com',
            '--password' => 'senha-segura-123',
            '--commission-rate' => '100.01',
        ])->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['email' => 'taxa.invalida@example.com']);
        $this->assertSame(0, AttendantProfile::query()->count());
    }

    public function test_operator_can_create_attendant_with_per_modality_prices(): void
    {
        $this->artisan('arcana:create-attendant', [
            '--name' => 'Atendente Precificado',
            '--email' => 'atendente.precificado@example.com',
            '--password' => 'senha-segura-123',
            '--commission-rate' => '30',
            '--price-text' => '100',
            '--price-audio' => '200',
            '--publish' => true,
        ])->assertExitCode(0);

        $profile = User::query()
            ->where('email', 'atendente.precificado@example.com')
            ->sole()
            ->attendantProfile;

        $this->assertSame(['text', 'audio'], $profile->offeredModalityValues());
        $this->assertSame(100, $profile->priceForModality(ConsultationModality::Text));
        $this->assertSame(200, $profile->priceForModality(ConsultationModality::Audio));
        $this->assertNull($profile->priceForModality(ConsultationModality::Video));
    }

    public function test_command_requires_text_price_when_any_price_is_informed(): void
    {
        $this->artisan('arcana:create-attendant', [
            '--name' => 'Sem Texto',
            '--email' => 'sem.texto@example.com',
            '--password' => 'senha-segura-123',
            '--price-audio' => '200',
        ])->assertExitCode(1);

        $this->artisan('arcana:create-attendant', [
            '--name' => 'Preço Inválido',
            '--email' => 'preco.invalido@example.com',
            '--password' => 'senha-segura-123',
            '--price-text' => '0',
        ])->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['email' => 'sem.texto@example.com']);
        $this->assertDatabaseMissing('users', ['email' => 'preco.invalido@example.com']);
        $this->assertSame(0, AttendantProfile::query()->count());
    }

    public function test_published_attendant_without_prices_cannot_be_requested(): void
    {
        $this->artisan('arcana:create-attendant', [
            '--name' => 'Atendente Sem Preço',
            '--email' => 'atendente.sem.preco@example.com',
            '--password' => 'senha-segura-123',
            '--publish' => true,
        ])->assertExitCode(0);

        $profile = User::query()
            ->where('email', 'atendente.sem.preco@example.com')
            ->sole()
            ->attendantProfile;

        $this->assertSame(['text'], $profile->offeredModalityValues());
        $this->assertSame(0, $profile->modalityPrices()->count());

        $this->assertSame(
            'O preco desta modalidade ainda nao foi configurado para este atendente.',
            app(ConsultationModalityCatalog::class)->unavailableReason($profile, ConsultationModality::Text),
        );
    }
}
