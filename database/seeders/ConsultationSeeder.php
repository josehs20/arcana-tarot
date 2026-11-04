<?php

namespace Database\Seeders;

use App\Enums\ConsultationEndReason;
use App\Models\Consultation;
use App\Models\TarotCard;
use App\Models\User;
use App\Support\ConsultationBillingManager;
use App\Support\ConsultationLifecycleManager;
use App\Support\ConsultationMessageManager;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Closure;

/**
 * Consultas de demonstração criadas pelos mesmos serviços do fluxo real.
 *
 * As transições, a cobrança por minuto, os snapshots do chat e a comissão são
 * produzidos por ConsultationLifecycleManager/ConsultationBillingManager. O
 * seeder não grava status financeiro ou mensagens manualmente.
 */
class ConsultationSeeder extends DemoSeeder
{
    public function run(
        ConsultationLifecycleManager $lifecycle,
        ConsultationMessageManager $messages,
        ConsultationBillingManager $billing,
    ): void {
        $this->ensureDemoEnvironment();

        $this->seedHistory($lifecycle, $messages);
        $this->seedOutOfCredits($lifecycle, $messages);
        $this->seedActive($lifecycle, $messages, $billing);
        $this->seedPendingRequest($lifecycle);
    }

    private function seedHistory(
        ConsultationLifecycleManager $lifecycle,
        ConsultationMessageManager $messages,
    ): void {
        $client = DemoData::user(DemoData::CLIENTS['history']['email']);
        $attendant = DemoData::user(DemoData::ATTENDANTS['marina']['email']);

        if ($this->alreadySeeded($client, $attendant)) {
            return;
        }

        $start = now()->startOfDay()->subDays(2)->setTime(14, 0);

        $this->withTestNow($start, function () use ($client, $attendant, $messages, $lifecycle, $start): void {
            $consultation = $lifecycle->request($client, $attendant);
            $consultation = $lifecycle->accept($attendant, $consultation);

            Carbon::setTestNow($start->copy()->addMinute());
            $messages->sendText(
                $consultation,
                $client,
                'Olá, Marina. Gostaria de entender melhor meu momento profissional.',
            );

            Carbon::setTestNow($start->copy()->addMinutes(2));
            $messages->sendText(
                $consultation,
                $attendant,
                'Olá, Helena. Vamos olhar juntas para o que está disponível agora, com calma.',
            );

            Carbon::setTestNow($start->copy()->addMinutes(4));
            $messages->sendCard($consultation, $attendant, $this->card('o-bateleur')->getKey());

            Carbon::setTestNow($start->copy()->addMinutes(10));
            $messages->sendText(
                $consultation,
                $client,
                'Fez sentido. Obrigada pela clareza e pelo cuidado com a leitura.',
            );

            Carbon::setTestNow($start->copy()->addMinutes(12));
            $messages->sendText(
                $consultation,
                $attendant,
                'Que bom. Vou encerrar a consulta por aqui; todo o histórico fica salvo para você.',
            );

            Carbon::setTestNow($start->copy()->addMinutes(13));
            $consultation = $lifecycle->end($client, $consultation);
            $consultation->markMessagesReadBy($client);
            $consultation->markMessagesReadBy($attendant);
        });
    }

    private function seedOutOfCredits(
        ConsultationLifecycleManager $lifecycle,
        ConsultationMessageManager $messages,
    ): void {
        $client = DemoData::user(DemoData::CLIENTS['no_balance']['email']);
        $attendant = DemoData::user(DemoData::ATTENDANTS['marina']['email']);

        if ($this->alreadySeeded($client, $attendant)) {
            return;
        }

        $start = now()->startOfDay()->subDay()->setTime(9, 0);

        $this->withTestNow($start, function () use ($client, $attendant, $messages, $lifecycle, $start): void {
            $consultation = $lifecycle->request($client, $attendant);
            $consultation = $lifecycle->accept($attendant, $consultation);

            Carbon::setTestNow($start->copy()->addMinute());
            $messages->sendText(
                $consultation,
                $client,
                'Tenho apenas o crédito mínimo. Podemos aproveitar este primeiro minuto?',
            );

            Carbon::setTestNow($start->copy()->addMinutes(2));
            $consultation = $lifecycle->endBySystem($consultation, ConsultationEndReason::OutOfCredits);
            $consultation->markMessagesReadBy($client);
            $consultation->markMessagesReadBy($attendant);
        });
    }

    private function seedActive(
        ConsultationLifecycleManager $lifecycle,
        ConsultationMessageManager $messages,
        ConsultationBillingManager $billing,
    ): void {
        $client = DemoData::user(DemoData::CLIENTS['active']['email']);
        $attendant = DemoData::user(DemoData::ATTENDANTS['iris']['email']);

        if ($this->alreadySeeded($client, $attendant)) {
            return;
        }

        $start = now()->subMinutes(6);

        $this->withTestNow($start, function () use ($client, $attendant, $messages, $lifecycle, $billing, $start): void {
            $consultation = $lifecycle->request($client, $attendant);
            $consultation = $lifecycle->accept($attendant, $consultation);

            Carbon::setTestNow($start->copy()->addMinute());
            $messages->sendText(
                $consultation,
                $client,
                'Olá, Íris. Estou em dúvida entre duas propostas de trabalho.',
            );

            Carbon::setTestNow($start->copy()->addMinutes(2));
            $messages->sendText(
                $consultation,
                $attendant,
                'Vamos observar os prós e contras de cada caminho antes de escolher.',
            );
            $messages->sendCard($consultation, $attendant, $this->card('a-papisa')->getKey());

            Carbon::setTestNow($start->copy()->addMinutes(6));
            $billing->billDue($consultation, $start->copy()->addMinutes(6));
        });
    }

    private function seedPendingRequest(ConsultationLifecycleManager $lifecycle): void
    {
        $client = DemoData::user(DemoData::CLIENTS['pending']['email']);
        $attendant = DemoData::user(DemoData::ATTENDANTS['marina']['email']);

        if ($this->alreadySeeded($client, $attendant)) {
            return;
        }

        $start = now()->subMinutes(2);

        $this->withTestNow($start, function () use ($client, $attendant, $lifecycle): void {
            $lifecycle->request($client, $attendant);
        });
    }

    private function alreadySeeded(User $client, User $attendant): bool
    {
        return Consultation::query()
            ->where('client_id', $client->getKey())
            ->where('attendant_id', $attendant->getKey())
            ->exists();
    }

    private function card(string $slug): TarotCard
    {
        return TarotCard::query()
            ->where('slug', $slug)
            ->whereHas('deck', fn ($query) => $query->where('is_active', true))
            ->where('is_active', true)
            ->firstOrFail();
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function withTestNow(CarbonInterface $time, Closure $callback): mixed
    {
        Carbon::setTestNow($time);

        try {
            return $callback();
        } finally {
            Carbon::setTestNow();
        }
    }
}
