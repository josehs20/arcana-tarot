<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateConsultationSafetyRequest;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Support\ConsultationSafety;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Limites de inatividade e reconexão do atendimento.
 *
 * O navegador nunca é a fonte da verdade: os valores gravados aqui são lidos
 * pelo servidor no comando de interrupção, no batimento de presença e no
 * encerramento por logout.
 */
class ConsultationSafetyController extends Controller
{
    public function index(ConsultationSafety $safety): View
    {
        Gate::authorize('access-admin-panel');

        return view('admin.consultations.safety', [
            'settings' => $safety->values(),
            'activityPingSeconds' => $safety->activityPingSeconds(),
            'presenceToleranceSeconds' => $safety->presenceToleranceSeconds(),
            'interruptionThresholdSeconds' => $safety->interruptionThresholdSeconds(),
        ]);
    }

    public function update(
        UpdateConsultationSafetyRequest $request,
        ConsultationSafety $safety,
    ): RedirectResponse {
        $validated = $request->validated();

        $before = $safety->values();
        $after = [
            'inactivity_timeout_minutes' => (int) $validated['inactivity_timeout_minutes'],
            'reconnection_tolerance_seconds' => (int) $validated['reconnection_tolerance_seconds'],
        ];

        DB::transaction(function () use ($request, $before, $after): void {
            Setting::query()->updateOrCreate(
                ['key' => 'consultation_inactivity_timeout_minutes'],
                ['value' => (string) $after['inactivity_timeout_minutes'], 'type' => 'integer'],
            );

            Setting::query()->updateOrCreate(
                ['key' => 'consultation_reconnection_tolerance_seconds'],
                ['value' => (string) $after['reconnection_tolerance_seconds'], 'type' => 'integer'],
            );

            AuditLog::query()->create([
                'actor_id' => $request->user()->getKey(),
                'action' => 'consultation_safety.updated',
                'metadata' => [
                    'before' => $before,
                    'after' => $after,
                ],
            ]);
        });

        return redirect()
            ->route('admin.consultations.safety')
            ->with('success', 'Limites de inatividade e reconexão atualizados.');
    }
}
