<?php

namespace App\Http\Controllers;

use App\Enums\CallEndReason;
use App\Enums\CallSignalType;
use App\Models\Consultation;
use App\Support\ConsultationCallManager;
use App\Support\ConsultationCallPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Endpoints JSON da chamada de audio/video.
 *
 * A autorizacao usa a mesma policy da consulta e o manager revalida papel e
 * estado. O GET de estado tambem entrega os sinais pendentes, servindo de
 * fallback quando o WebSocket (Reverb) nao esta disponivel.
 */
class ConsultationCallController extends Controller
{
    public function state(
        Request $request,
        Consultation $consultation,
        ConsultationCallManager $calls,
    ): JsonResponse {
        Gate::authorize('call', $consultation);

        $validated = $request->validate([
            'after' => ['nullable', 'integer', 'min:0'],
        ]);

        $consultation->loadMissing(['call']);

        $signals = $calls->signalsAfter($consultation, (int) ($validated['after'] ?? 0))
            ->map(fn ($signal): array => ConsultationCallPresenter::signal($signal))
            ->values();

        return response()->json([
            'consultation' => [
                'id' => (int) $consultation->getKey(),
                'status' => $consultation->status?->value,
                'status_label' => $consultation->status?->label(),
                'modality' => $consultation->modality?->value,
                'modality_label' => $consultation->modality?->label(),
                'price_per_minute_cents' => $consultation->pricePerMinuteCents(),
                'price_per_minute_label' => $consultation->pricePerMinuteLabel(),
            ],
            'call' => ConsultationCallPresenter::toArray($consultation->call),
            'signals' => $signals,
            'role' => $consultation->isClient($request->user())
                ? 'client'
                : ($consultation->isAttendant($request->user()) ? 'attendant' : 'admin'),
            'ice_servers' => (array) config('consultation.web_rtc.ice_servers', []),
            'poll_ms' => (int) config('consultation.web_rtc.signal_poll_ms', 2500),
            'ring_timeout_seconds' => (int) config('consultation.web_rtc.ring_timeout_seconds', 90),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function store(
        Request $request,
        Consultation $consultation,
        ConsultationCallManager $calls,
    ): JsonResponse {
        Gate::authorize('call', $consultation);

        $call = $calls->request($consultation, $request->user());

        return $this->response($consultation, $call);
    }

    public function accept(
        Request $request,
        Consultation $consultation,
        ConsultationCallManager $calls,
    ): JsonResponse {
        Gate::authorize('call', $consultation);

        $call = $calls->accept($consultation, $request->user());

        return $this->response($consultation, $call);
    }

    public function reject(
        Request $request,
        Consultation $consultation,
        ConsultationCallManager $calls,
    ): JsonResponse {
        Gate::authorize('call', $consultation);

        $call = $calls->reject($consultation, $request->user());

        return $this->response($consultation, $call);
    }

    public function connected(
        Request $request,
        Consultation $consultation,
        ConsultationCallManager $calls,
    ): JsonResponse {
        Gate::authorize('call', $consultation);

        $call = $calls->markConnected($consultation, $request->user());

        return $this->response($consultation, $call);
    }

    public function end(
        Request $request,
        Consultation $consultation,
        ConsultationCallManager $calls,
    ): JsonResponse {
        Gate::authorize('call', $consultation);

        $call = $calls->end($consultation, $request->user());

        return $this->response($consultation, $call);
    }

    public function fail(
        Request $request,
        Consultation $consultation,
        ConsultationCallManager $calls,
    ): JsonResponse {
        Gate::authorize('call', $consultation);

        $validated = $request->validate([
            'reason' => ['required', Rule::in([
                CallEndReason::MediaUnavailable->value,
                CallEndReason::ConnectionFailed->value,
            ])],
        ]);

        $call = $calls->fail(
            $consultation,
            $request->user(),
            CallEndReason::from($validated['reason']),
        );

        return $this->response($consultation, $call);
    }

    public function signal(
        Request $request,
        Consultation $consultation,
        ConsultationCallManager $calls,
    ): JsonResponse {
        Gate::authorize('call', $consultation);

        $validated = $request->validate([
            'type' => ['required', Rule::enum(CallSignalType::class)],
            'payload' => ['required', 'array'],
            'payload.sdp' => ['nullable', 'string', 'max:20000'],
            'payload.candidate' => ['nullable', 'array'],
        ]);

        $signal = $calls->storeSignal(
            $consultation,
            $request->user(),
            CallSignalType::from($validated['type']),
            (array) $validated['payload'],
        );

        return response()->json([
            'signal' => ConsultationCallPresenter::signal($signal),
            'call' => ConsultationCallPresenter::toArray($calls->currentCall($consultation)),
            'web_rtc' => [
                'ice_servers' => (array) config('consultation.web_rtc.ice_servers', []),
                'poll_ms' => (int) config('consultation.web_rtc.signal_poll_ms', 2500),
            ],
        ], 201);
    }

    private function response(Consultation $consultation, $call): JsonResponse
    {
        $consultation->refresh();

        return response()->json([
            'call' => ConsultationCallPresenter::toArray($call),
            'consultation' => [
                'id' => (int) $consultation->getKey(),
                'status' => $consultation->status?->value,
                'status_label' => $consultation->status?->label(),
            ],
        ]);
    }
}
