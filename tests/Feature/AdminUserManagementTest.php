<?php

namespace Tests\Feature;

use App\Enums\AttendantAvailability;
use App\Enums\Gender;
use App\Enums\LedgerEntryType;
use App\Enums\UserRole;
use App\Models\AttendantProfile;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Cadastro e edição de contas pelo painel administrativo.
 *
 * Cobre as duas telas novas: cadastro de atendente (conta verificada, perfil
 * offline, comissão e preços por minuto) e edição de conta (dados cadastrais,
 * perfil público, preços e ajuste de créditos do cliente).
 */
class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_an_attendant_with_profile_and_prices(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Marina Duarte',
            'email' => 'Marina.Duarte@Example.com',
            'phone' => '(11) 98888-7777',
            'birth_date' => '1990-05-10',
            'password' => 'senha-provisoria',
            'password_confirmation' => 'senha-provisoria',
            'display_name' => 'Marina do Tarot',
            'bio' => 'Leituras de tarot focadas em decisões práticas.',
            'specialties' => 'Amor, Trabalho, Espiritualidade',
            'commission_rate' => '25,5',
            'is_published' => '1',
            'prices' => ['text' => 250],
        ]);

        $attendant = User::query()->where('email', 'marina.duarte@example.com')->firstOrFail();

        $response
            ->assertRedirect(route('admin.users.edit', $attendant))
            ->assertSessionHas('success');

        $this->assertSame(UserRole::Attendant, $attendant->role);
        $this->assertTrue($attendant->is_active);
        $this->assertNotNull($attendant->email_verified_at);
        $this->assertSame('11988887777', $attendant->phone);
        $this->assertTrue(Hash::check('senha-provisoria', $attendant->password));

        $profile = $attendant->attendantProfile()->firstOrFail();

        $this->assertSame('Marina do Tarot', $profile->display_name);
        $this->assertSame(['Amor', 'Trabalho', 'Espiritualidade'], $profile->specialties);
        $this->assertSame(2550, $profile->commission_rate_bps);
        $this->assertSame(AttendantAvailability::Offline, $profile->availability);
        $this->assertSame(250, $profile->priceForModality('text'));

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'attendant.created_by_operator',
            'subject_id' => $profile->id,
        ]);
    }

    public function test_attendant_creation_requires_a_price_for_text_consultations(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->from(route('admin.users.create'))
            ->post(route('admin.users.store'), [
                'name' => 'Marina Duarte',
                'email' => 'marina@example.com',
                'password' => 'senha-provisoria',
                'password_confirmation' => 'senha-provisoria',
                'display_name' => 'Marina do Tarot',
                'commission_rate' => '30',
                'is_published' => '0',
                'prices' => ['text' => ''],
            ])
            ->assertRedirect(route('admin.users.create'))
            ->assertSessionHasErrors('prices.text');

        $this->assertDatabaseMissing('users', ['email' => 'marina@example.com']);
    }

    public function test_admin_can_update_client_account_and_password(): void
    {
        $admin = User::factory()->admin()->create();
        $client = User::factory()->create([
            'name' => 'Cliente Antigo',
            'email' => 'cliente.antigo@example.com',
            'password' => 'senha-atual',
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.users.update', $client), [
                'name' => 'Cliente Atualizado',
                'email' => 'cliente.novo@example.com',
                'phone' => '(21) 97777-6666',
                'birth_date' => '1992-03-04',
                'gender' => 'female',
                'password' => 'nova-senha-2026',
                'password_confirmation' => 'nova-senha-2026',
            ])
            ->assertRedirect(route('admin.users.edit', $client))
            ->assertSessionHas('success');

        $client->refresh();

        $this->assertSame('Cliente Atualizado', $client->name);
        $this->assertSame('cliente.novo@example.com', $client->email);
        $this->assertSame('21977776666', $client->phone);
        $this->assertSame('1992-03-04', $client->birth_date?->toDateString());
        $this->assertSame(Gender::Female, $client->gender);
        $this->assertTrue(Hash::check('nova-senha-2026', $client->password));

        $audit = AuditLog::query()->where('action', 'user.updated_by_operator')->latest('id')->firstOrFail();

        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame($client->id, $audit->subject_id);
        $this->assertTrue((bool) $audit->metadata['password_reset']);
        $this->assertSame('Cliente Antigo', $audit->metadata['before']['name']);
        $this->assertSame('Cliente Atualizado', $audit->metadata['after']['name']);
    }

    public function test_admin_can_update_attendant_profile_commission_and_prices(): void
    {
        $admin = User::factory()->admin()->create();
        $attendant = User::factory()->attendant()->create(['email' => 'atendente@example.com']);
        $profile = AttendantProfile::factory()->online()->for($attendant)->create([
            'display_name' => 'Nome Antigo',
            'offered_modalities' => ['text'],
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.users.update', $attendant), [
                'name' => 'Atendente Atualizado',
                'email' => 'atendente@example.com',
                'display_name' => 'Nome Novo',
                'bio' => 'Nova apresentação do perfil.',
                'specialties' => 'Amor, Finanças',
                'commission_rate' => '40',
                'is_published' => '0',
                'prices' => ['text' => 400],
            ])
            ->assertRedirect(route('admin.users.edit', $attendant))
            ->assertSessionHas('success');

        $profile = $profile->fresh();

        $this->assertSame('Nome Novo', $profile->display_name);
        $this->assertSame(['Amor', 'Finanças'], $profile->specialties);
        $this->assertSame(4000, $profile->commission_rate_bps);
        $this->assertFalse($profile->is_published);
        $this->assertSame(AttendantAvailability::Offline, $profile->availability);
        $this->assertSame(400, $profile->priceForModality('text'));

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'attendant.profile_updated',
            'subject_id' => $profile->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'attendant.modalities_updated',
            'subject_id' => $profile->id,
        ]);
    }

    public function test_admin_cannot_edit_another_admin_account(): void
    {
        $admin = User::factory()->admin()->create();
        $otherAdmin = User::factory()->admin()->create(['email' => 'outro.admin@example.com']);

        $this->actingAs($admin)
            ->get(route('admin.users.edit', $otherAdmin))
            ->assertForbidden();

        $this->actingAs($admin)
            ->patch(route('admin.users.update', $otherAdmin), [
                'name' => 'Tentativa de Alteração',
                'email' => 'outro.admin@example.com',
            ])
            ->assertForbidden();

        $this->assertNotSame('Tentativa de Alteração', $otherAdmin->fresh()->name);
    }

    public function test_admin_can_open_attendant_and_client_edit_forms(): void
    {
        $admin = User::factory()->admin()->create();
        $client = User::factory()->create(['name' => 'Cliente do Formulário']);
        $attendant = User::factory()->attendant()->create();
        AttendantProfile::factory()->for($attendant)->create(['display_name' => 'Perfil do Formulário']);

        $this->actingAs($admin)
            ->get(route('admin.users.create'))
            ->assertOk()
            ->assertSee('Novo atendente');

        $this->actingAs($admin)
            ->get(route('admin.users.edit', $client))
            ->assertOk()
            ->assertSee('Cliente do Formulário')
            ->assertSee('Créditos do cliente');

        $this->actingAs($admin)
            ->get(route('admin.users.edit', $attendant))
            ->assertOk()
            ->assertSee('Perfil do Formulário')
            ->assertSee('Comissão');
    }

    public function test_client_cannot_access_attendant_creation(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client)->get(route('admin.users.create'))->assertForbidden();

        $this->actingAs($client)
            ->post(route('admin.users.store'), [])
            ->assertForbidden();
    }

    public function test_admin_can_credit_a_client_wallet_with_an_audited_adjustment(): void
    {
        $admin = User::factory()->admin()->create();
        $client = User::factory()->create();
        Wallet::factory()->create(['user_id' => $client->id, 'balance_cents' => 1000]);

        $this->actingAs($admin)
            ->post(route('admin.users.credit.store', $client), [
                'type' => LedgerEntryType::Credit->value,
                'amount_cents' => 2000,
                'reason' => 'Bônus de cortesia combinado no suporte.',
                'operation_id' => (string) Str::uuid(),
            ])
            ->assertRedirect(route('admin.users.edit', $client))
            ->assertSessionHas('success');

        $this->assertSame(3000, Wallet::query()->where('user_id', $client->id)->value('balance_cents'));

        $audit = AuditLog::query()->where('action', 'wallet.adjustment')->latest('id')->firstOrFail();

        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame(2000, $audit->metadata['amount_cents']);
        $this->assertSame(3000, $audit->metadata['balance_after_cents']);
    }

    public function test_credit_adjustment_rejects_a_debit_above_the_balance(): void
    {
        $admin = User::factory()->admin()->create();
        $client = User::factory()->create();
        Wallet::factory()->create(['user_id' => $client->id, 'balance_cents' => 500]);

        $this->actingAs($admin)
            ->from(route('admin.users.edit', $client))
            ->post(route('admin.users.credit.store', $client), [
                'type' => LedgerEntryType::Debit->value,
                'amount_cents' => 900,
                'reason' => 'Estorno solicitado pelo cliente.',
                'operation_id' => (string) Str::uuid(),
            ])
            ->assertRedirect(route('admin.users.edit', $client))
            ->assertSessionHasErrors('amount_cents');

        $this->assertSame(500, Wallet::query()->where('user_id', $client->id)->value('balance_cents'));
    }

    public function test_credit_adjustment_is_rejected_for_attendants_and_non_admins(): void
    {
        $admin = User::factory()->admin()->create();
        $attendant = User::factory()->attendant()->create();
        $client = User::factory()->create();
        Wallet::factory()->create(['user_id' => $client->id, 'balance_cents' => 1000]);

        $payload = [
            'type' => LedgerEntryType::Credit->value,
            'amount_cents' => 500,
            'reason' => 'Tentativa de ajuste indevido.',
            'operation_id' => (string) Str::uuid(),
        ];

        $this->actingAs($admin)
            ->post(route('admin.users.credit.store', $attendant), $payload)
            ->assertForbidden();

        $this->actingAs($client)
            ->post(route('admin.users.credit.store', $client), $payload)
            ->assertForbidden();

        $this->assertSame(1000, Wallet::query()->where('user_id', $client->id)->value('balance_cents'));
    }
}
