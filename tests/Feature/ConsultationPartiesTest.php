<?php

namespace Tests\Feature;

use App\Models\AttendantProfile;
use App\Models\Consultation;
use App\Models\User;
use App\Models\Wallet;
use App\Support\ConsultationPartyPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cartões "quem está do outro lado" da tela de atendimento.
 *
 * O cartão principal é sempre o da outra parte, com números calculados no
 * servidor (`ConsultationPartyPresenter`). Estes testes garantem três coisas:
 * os valores exibidos batem com o presenter, a visão é sempre a do observador
 * (comissão só para o próprio atendente) e nenhum dado de contato vaza pelo
 * cartão — o chat bloqueia a troca de e-mail e telefone.
 */
class ConsultationPartiesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: User, 2: Consultation}
     */
    private function conversation(): array
    {
        $client = User::factory()->create([
            'name' => 'Clara Cliente',
            'email' => 'clara@example.com',
            'phone' => '11988887777',
            'birth_date' => '1994-06-15',
        ]);

        Wallet::factory()->create(['user_id' => $client->id, 'balance_cents' => 900]);

        $attendant = User::factory()->attendant()->create([
            'name' => 'Atendente Interno',
            'email' => 'atendente@example.com',
            'phone' => '11977776666',
            'birth_date' => '1988-02-03',
        ]);

        AttendantProfile::factory()->for($attendant)->create([
            'display_name' => 'Mestre Aurora',
            'commission_rate_bps' => 3000,
            'specialties' => ['Amor', 'Trabalho'],
        ]);

        $consultation = Consultation::factory()->active()->create([
            'client_id' => $client->id,
            'attendant_id' => $attendant->id,
            // Snapshots do aceite: saldo de R$ 9,00 ÷ R$ 3,00 por minuto = 3
            // minutos estimados; 2 minutos cobrados = R$ 6,00.
            'price_per_minute_cents' => 300,
            'credit_unit_price_cents' => 150,
            'billed_minutes' => 2,
            'commission_rate_bps' => 3000,
        ]);

        return [$client, $attendant, $consultation];
    }

    public function test_party_presenter_builds_server_side_identity_numbers(): void
    {
        [$client, $attendant, $consultation] = $this->conversation();

        $parties = ConsultationPartyPresenter::toArray($consultation->fresh(), $client);

        $this->assertSame('client', $parties['client']['role']);
        $this->assertSame('Cliente', $parties['client']['role_label']);
        $this->assertSame('Clara Cliente', $parties['client']['name']);
        $this->assertSame('CC', $parties['client']['initials']);
        $this->assertSame('15/06/1994', $parties['client']['birth_date_label']);
        $this->assertSame($client->ageLabel(), $parties['client']['age_label']);
        $this->assertSame('1 atendimento', $parties['client']['consultations_label']);
        $this->assertSame(3, $parties['client']['available_minutes']);
        $this->assertSame('3 minutos', $parties['client']['available_label']);
        $this->assertSame('Saldo de R$ 9,00 ÷ R$ 3,00 por minuto.', $parties['client']['available_explanation']);
        $this->assertSame('R$ 6,00', $parties['client']['charged_label']);
        $this->assertSame('R$ 3,00', $parties['client']['price_per_minute_label']);

        $this->assertSame('attendant', $parties['attendant']['role']);
        $this->assertSame('Atendente', $parties['attendant']['role_label']);
        $this->assertSame('Mestre Aurora', $parties['attendant']['name']);
        $this->assertSame('MA', $parties['attendant']['initials']);
        $this->assertSame(['Amor', 'Trabalho'], $parties['attendant']['specialties']);
        $this->assertNull($parties['attendant']['available_label']);
        $this->assertSame('R$ 6,00', $parties['attendant']['charged_label']);

        // A comissão só existe para o próprio atendente: o cliente nunca recebe
        // esse número, nem por engano de template.
        $this->assertNull($parties['attendant']['commission_rate_label']);

        $asAttendant = ConsultationPartyPresenter::toArray($consultation->fresh(), $attendant);

        $this->assertSame('30%', $asAttendant['attendant']['commission_rate_label']);

        // Nenhum dado de contato entra no cartão.
        foreach (['client', 'attendant'] as $role) {
            $this->assertArrayNotHasKey('email', $parties[$role]);
            $this->assertArrayNotHasKey('phone', $parties[$role]);
        }

        $encoded = json_encode($parties);

        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('clara@example.com', $encoded);
        $this->assertStringNotContainsString('atendente@example.com', $encoded);
        $this->assertStringNotContainsString('11988887777', $encoded);
        $this->assertStringNotContainsString('11977776666', $encoded);
    }

    public function test_client_workspace_shows_the_attendant_card_and_the_own_summary(): void
    {
        [$client, , $consultation] = $this->conversation();

        $this->actingAs($client)
            ->get(route('consultations.workspace', $consultation))
            ->assertOk()
            ->assertSee('data-consultation-parties', false)
            ->assertSee('Seu atendente')
            // O cliente vê só o cartão da outra parte; o seu próprio resumo
            // aparece em "Seus dados neste atendimento".
            ->assertSee('data-party-role="attendant"', false)
            ->assertDontSee('data-party-role="client"', false)
            ->assertSee('Mestre Aurora')
            ->assertSee('Amor')
            ->assertSee('data-metric="charged_label"', false)
            ->assertSee('R$ 6,00')
            // O resumo "Seus dados neste atendimento" usa a mesma fonte.
            ->assertSee('Seus dados neste atendimento')
            ->assertSee('Seus dados de contato (e-mail e telefone) não são exibidos no chat.')
            // A presença das duas partes é atualizada pelo batimento.
            ->assertSee('data-presence-role="attendant"', false)
            ->assertSee('data-presence-role="client"', false)
            ->assertDontSee('Comissão')
            // O cartão é identificação: data de nascimento e preço por minuto
            // saíram da tela para não repetir o que já está nos indicadores.
            ->assertDontSee('Nascimento')
            ->assertDontSee('15/06/1994')
            ->assertDontSee('Preço por minuto')
            ->assertDontSee('por minuto</span>', false)
            // O e-mail do próprio usuário aparece no menu da barra superior; o
            // que não pode vazar é o contato da outra parte.
            ->assertDontSee('atendente@example.com')
            ->assertDontSee('11988887777')
            ->assertDontSee('11977776666')
            ->assertViewHas(
                'parties',
                fn (array $parties): bool => $parties['attendant']['commission_rate_label'] === null,
            );
    }

    public function test_attendant_workspace_shows_the_client_card_with_the_remaining_balance(): void
    {
        [$client, $attendant, $consultation] = $this->conversation();

        $this->actingAs($attendant)
            ->get(route('consultations.workspace', $consultation))
            ->assertOk()
            ->assertSee('Cliente em atendimento')
            ->assertSee('data-party-role="client"', false)
            ->assertDontSee('data-party-role="attendant"', false)
            ->assertSee('Clara Cliente')
            ->assertSee('1 atendimento')
            // O tempo restante de saldo saiu do cartão da parte e virou um
            // indicador do topo, com contador alimentado pelo servidor.
            ->assertSee('Tempo restante')
            ->assertSee('data-consultation-countdown', false)
            ->assertSee('data-remaining-seconds="180"', false)
            ->assertSee('data-metric="remaining_explanation"', false)
            ->assertSee('Saldo de R$ 9,00 ÷ R$ 3,00 por minuto.')
            ->assertDontSee('data-metric="available_label"', false)
            ->assertDontSee('clara@example.com')
            ->assertDontSee('11988887777')
            ->assertViewHas(
                'parties',
                fn (array $parties): bool => $parties['client']['available_minutes'] === 3
                    && $parties['attendant']['commission_rate_label'] === '30%'
                    && $parties['client']['charged_label'] === 'R$ 6,00',
            );
    }

    /**
     * A tela da consulta (fora do workspace) mantém o cartão para as duas
     * partes e o esconde da administração, que tem a lista de "Participantes"
     * com os contatos no lugar dele.
     */
    public function test_consultation_page_shows_the_card_to_participants_only(): void
    {
        [$client, $attendant, $consultation] = $this->conversation();

        $this->actingAs($client)
            ->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertSee('data-consultation-parties', false)
            ->assertSee('data-party-role="attendant"', false)
            ->assertDontSee('atendente@example.com')
            ->assertDontSee('11977776666');

        $this->actingAs($attendant)
            ->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertSee('data-consultation-parties', false)
            ->assertSee('data-party-role="client"', false)
            ->assertDontSee('clara@example.com');

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertDontSee('data-consultation-parties', false)
            ->assertSee('Participantes')
            ->assertSee('clara@example.com');
    }
}
