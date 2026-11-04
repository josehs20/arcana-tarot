<?php

namespace Database\Seeders;

use App\Enums\ApplicationStatus;
use App\Models\AttendantApplication;
use App\Support\AttendantApplicationReview;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Closure;

class AttendantApplicationSeeder extends DemoSeeder
{
    public function run(AttendantApplicationReview $review): void
    {
        $this->ensureDemoEnvironment();

        $admin = DemoData::user(DemoData::ADMIN_EMAIL);
        $now = now();

        $scenarios = [
            [
                'name' => 'Beatriz Almeida',
                'email' => 'candidatura.pendente@arcana.local',
                'status' => ApplicationStatus::Pending,
                'submitted_at' => $now->copy()->subHours(3),
            ],
            [
                'name' => 'Diego Martins',
                'email' => 'candidatura.em-analise@arcana.local',
                'status' => ApplicationStatus::UnderReview,
                'submitted_at' => $now->copy()->subDays(3),
                'notes' => 'Entrevista inicial agendada para a próxima semana.',
            ],
            [
                'name' => 'Elena Vasques',
                'email' => 'candidatura.aprovada@arcana.local',
                'status' => ApplicationStatus::Approved,
                'submitted_at' => $now->copy()->subDays(12),
                'notes' => 'Aprovada na triagem fictícia de demonstração. O acesso deve ser criado manualmente pela operação.',
            ],
            [
                'name' => 'Fábio Teles',
                'email' => 'candidatura.rejeitada@arcana.local',
                'status' => ApplicationStatus::Rejected,
                'submitted_at' => $now->copy()->subDays(20),
                'notes' => 'Candidatura fictícia de demonstração rejeitada por falta de informações obrigatórias.',
            ],
        ];

        foreach ($scenarios as $scenario) {
            $application = $this->withTestNow(
                $scenario['submitted_at'],
                fn (): AttendantApplication => AttendantApplication::query()->firstOrCreate(
                    ['email' => $scenario['email']],
                    [
                        'name' => $scenario['name'],
                        'phone' => '11988887777',
                        'city' => 'São Paulo',
                        'state' => 'SP',
                        'experience_summary' => 'Atendimento fictício de demonstração com foco em escuta acolhedora e leitura responsável.',
                        'message' => 'Dados exclusivos para testar o fluxo de candidaturas em ambiente local.',
                        'specialties' => ['Amor', 'Carreira', 'Autoconhecimento'],
                        'portfolio_url' => null,
                        'status' => ApplicationStatus::Pending->value,
                        'submitted_at' => $scenario['submitted_at'],
                        'consent_at' => $scenario['submitted_at'],
                        'status_changed_at' => $scenario['submitted_at'],
                    ],
                ),
            );

            if (! $application->wasRecentlyCreated) {
                continue;
            }

            if ($scenario['status'] === ApplicationStatus::Pending) {
                continue;
            }

            $reviewStartedAt = $scenario['submitted_at']->copy()->addDay();

            $this->withTestNow(
                $reviewStartedAt,
                fn (): AttendantApplication => $review->start(
                    $application,
                    $admin,
                    $scenario['notes'] ?? null,
                ),
            );

            if ($scenario['status'] === ApplicationStatus::UnderReview) {
                continue;
            }

            $this->withTestNow(
                $reviewStartedAt->copy()->addDay(),
                fn (): AttendantApplication => $scenario['status'] === ApplicationStatus::Approved
                    ? $review->approve($application, $admin, $scenario['notes'] ?? null)
                    : $review->reject($application, $admin, $scenario['notes'] ?? null),
            );
        }
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
