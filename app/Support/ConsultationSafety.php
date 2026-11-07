<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Política de inatividade e reconexão do atendimento.
 *
 * Os limites ficam no banco (`settings`) para a administração ajustar sem
 * deploy; `config/consultation.php` é apenas o fallback inicial. O navegador
 * nunca informa quanto tempo esteve inativo: o servidor compara os horários
 * gravados nas próprias requisições.
 */
final class ConsultationSafety
{
    /** @var array<string, string|null>|null */
    private ?array $settings = null;

    public function inactivityTimeoutMinutes(): int
    {
        $minutes = $this->integer('consultation_inactivity_timeout_minutes')
            ?? (int) config('consultation.inactivity_timeout_minutes', 5);

        return max(1, min(120, $minutes));
    }

    public function inactivityTimeoutSeconds(): int
    {
        return $this->inactivityTimeoutMinutes() * 60;
    }

    public function reconnectionToleranceSeconds(): int
    {
        $seconds = $this->integer('consultation_reconnection_tolerance_seconds')
            ?? (int) config('consultation.reconnection_tolerance_seconds', 90);

        return max(0, min(600, $seconds));
    }

    /**
     * Janela efetiva antes de o comando encerrar uma consulta inativa. Inclui a
     * tolerância de reconexão para uma queda breve de rede não encerrar (nem
     * cobrar) o atendimento.
     */
    public function interruptionThresholdSeconds(): int
    {
        return $this->inactivityTimeoutSeconds() + $this->reconnectionToleranceSeconds();
    }

    public function activityPingSeconds(): int
    {
        return max(5, min(60, (int) config('consultation.activity_ping_seconds', 60)));
    }

    /**
     * Sessão de presença é considerada online por três batimentos perdidos.
     */
    public function presenceToleranceSeconds(): int
    {
        return max(30, $this->activityPingSeconds() * 3);
    }

    public function navigationWarningSeconds(): int
    {
        return max(5, min(120, (int) config('consultation.navigation_warning_seconds', 60)));
    }

    /**
     * Janela do aviso de saldo baixo exibido ao cliente na tela de atendimento.
     *
     * Sai do `config` porque é uma decisão de interface, não da política de
     * inatividade: o servidor continua sendo quem cobra e quem encerra a
     * consulta quando a carteira não cobre o próximo minuto.
     */
    public function lowBalanceWarningSeconds(): int
    {
        return max(60, min(3600, (int) config('consultation.low_balance_warning_minutes', 5) * 60));
    }

    /**
     * Valores usados no formulário administrativo.
     *
     * @return array{
     *     inactivity_timeout_minutes: int,
     *     reconnection_tolerance_seconds: int,
     *     navigation_warning_seconds: int,
     *     activity_ping_seconds: int,
     *     low_balance_warning_seconds: int
     * }
     */
    public function values(): array
    {
        return [
            'inactivity_timeout_minutes' => $this->inactivityTimeoutMinutes(),
            'reconnection_tolerance_seconds' => $this->reconnectionToleranceSeconds(),
            'navigation_warning_seconds' => $this->navigationWarningSeconds(),
            'activity_ping_seconds' => $this->activityPingSeconds(),
            'low_balance_warning_seconds' => $this->lowBalanceWarningSeconds(),
        ];
    }

    private function integer(string $key): ?int
    {
        if ($this->settings === null) {
            $this->settings = Setting::query()
                ->whereIn('key', [
                    'consultation_inactivity_timeout_minutes',
                    'consultation_reconnection_tolerance_seconds',
                ])
                ->pluck('value', 'key')
                ->all();
        }

        $value = $this->settings[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
