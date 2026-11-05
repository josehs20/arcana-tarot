<?php

namespace Database\Seeders;

use App\Enums\ConsultationEndReason;
use App\Models\Consultation;
use App\Models\TarotCard;
use App\Models\User;
use App\Support\ConsultationLifecycleManager;
use App\Support\ConsultationMessageManager;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Closure;

/**
 * Consultas de demonstração criadas pelos mesmos serviços do fluxo real.
 *
 * As transições, a cobrança por minuto, os snapshots do chat e a comissão são
 * produzidos por ConsultationLifecycleManager. O seeder não grava status
 * financeiro ou mensagens manualmente.
 *
 * Apenas consultas encerradas são semeadas. Nenhum atendimento em aberto
 * (solicitação pendente ou consulta ativa) é criado: assim, ao entrar no
 * painel, ninguém é chamado por um atendimento de uma conta fictícia que não
 * está logada. Quem inicia o atendimento é sempre o cliente autenticado.
 */
class ConsultationSeeder extends DemoSeeder
{
    public function run(
        ConsultationLifecycleManager $lifecycle,
        ConsultationMessageManager $messages,
    ): void {
        $this->ensureDemoEnvironment();

        $this->seedHistory($lifecycle, $messages);
        $this->seedOutOfCredits($lifecycle, $messages);
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
