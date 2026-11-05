<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ConsultationModality;
use App\Enums\ConsultationStatus;
use App\Enums\MessageType;
use App\Enums\PrivacyIncidentStatus;
use App\Enums\SensitiveDataCategory;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\ConsultationMessage;
use App\Models\ConsultationPrivacyIncident;
use App\Models\User;
use App\Support\ConsultationAdminHistoryPresenter;
use App\Support\ConsultationCardImageStorage;
use App\Support\ConsultationStatePresenter;
use App\Support\Privacy\SensitiveDataDetector;
use App\Support\Realtime\RealtimeMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConsultationController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Consultation::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(ConsultationStatus::class)],
            'modality' => ['nullable', Rule::enum(ConsultationModality::class)],
            'incidents' => ['nullable', Rule::in(['with', 'without'])],
            'incident_type' => ['nullable', Rule::enum(SensitiveDataCategory::class)],
        ]);

        $search = trim((string) ($filters['q'] ?? ''));
        $incidentFilter = $filters['incidents'] ?? null;
        $incidentType = $filters['incident_type'] ?? null;

        $consultations = Consultation::query()
            ->with(['client', 'attendant.attendantProfile', 'commissionEntries'])
            ->withCount([
                'privacyIncidents',
                'privacyIncidents as open_privacy_incidents_count' => fn ($query) => $query
                    ->where('status', PrivacyIncidentStatus::Blocked->value),
            ])
            ->when($search !== '', function ($query) use ($search): void {
                $term = '%'.$search.'%';

                $query->where(function ($query) use ($term): void {
                    $query->whereHas('client', fn ($client) => $client
                        ->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term))
                        ->orWhereHas('attendant', fn ($attendant) => $attendant
                            ->where('name', 'like', $term)
                            ->orWhere('email', 'like', $term));
                });
            })
            ->when(
                isset($filters['status']),
                fn ($query) => $query->where('status', $filters['status']),
            )
            ->when(
                isset($filters['modality']),
                fn ($query) => $query->where('modality', $filters['modality']),
            )
            ->when($incidentFilter === 'with', fn ($query) => $query->whereHas('privacyIncidents'))
            ->when($incidentFilter === 'without', fn ($query) => $query->whereDoesntHave('privacyIncidents'))
            ->when(
                $incidentType !== null,
                fn ($query) => $query->whereHas(
                    'privacyIncidents',
                    fn ($incident) => $incident->where('category', $incidentType),
                ),
            )
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('admin.consultations.index', [
            'consultations' => $consultations,
            'filters' => $filters,
            'statuses' => ConsultationStatus::cases(),
            'modalities' => ConsultationModality::cases(),
            'incidentCategories' => SensitiveDataCategory::cases(),
        ]);
    }

    /**
     * Detalhe administrativo da consulta: participantes, tempos, cobrança e o
     * histórico completo do chat com as ocorrências de dado pessoal.
     */
    public function show(
        Request $request,
        Consultation $consultation,
        SensitiveDataDetector $detector,
    ): View {
        Gate::authorize('viewAdminHistory', $consultation);

        $consultation->load(['client', 'attendant.attendantProfile', 'commissionEntries', 'call']);

        $messages = $consultation->messages()
            ->with(['sender.attendantProfile', 'consultation.attendant.attendantProfile'])
            ->latest('id')
            ->paginate((int) config('consultation.message_page_size'), ['*'], 'mensagens')
            ->withQueryString();

        $incidents = $consultation->privacyIncidents()
            ->with(['user', 'reviewer'])
            ->latest('id')
            ->limit(200)
            ->get();

        $entries = ConsultationAdminHistoryPresenter::toArray(
            $consultation,
            $messages->getCollection(),
            $incidents,
            $detector,
            true,
        );

        $this->auditHistoryAccess($request, $consultation, $messages->total(), $incidents->count());

        return view('admin.consultations.show', [
            'consultation' => $consultation,
            'entries' => $entries,
            'messages' => $messages,
            'incidents' => $incidents,
            'openIncidents' => $incidents->filter(fn (ConsultationPrivacyIncident $incident): bool => $incident->isOpen())->count(),
            'lastMessageId' => (int) ($consultation->messages()->max('id') ?? 0),
            'lastIncidentId' => (int) ($consultation->privacyIncidents()->max('id') ?? 0),
            'realtimeMode' => RealtimeMode::current(),
            'pollIntervals' => config('consultation.realtime.poll'),
            'isLive' => $consultation->status === ConsultationStatus::Active,
        ]);
    }

    /**
     * Novos itens da linha do tempo para atualização automática da tela.
     *
     * Devolve o HTML já renderizado pelo mesmo partial da página para que a
     * formatação e o mascaramento fiquem em um único lugar.
     */
    public function entries(
        Request $request,
        Consultation $consultation,
        SensitiveDataDetector $detector,
    ): JsonResponse {
        Gate::authorize('viewAdminHistory', $consultation);

        $validated = $request->validate([
            'after_message_id' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'after_incident_id' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);

        $afterMessageId = (int) ($validated['after_message_id'] ?? 0);
        $afterIncidentId = (int) ($validated['after_incident_id'] ?? 0);
        $limit = 100;

        $consultation->loadMissing(['client', 'attendant.attendantProfile']);

        $messages = $consultation->messages()
            ->with(['sender.attendantProfile', 'consultation.attendant.attendantProfile'])
            ->where('id', '>', $afterMessageId)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $incidents = $consultation->privacyIncidents()
            ->with(['user', 'reviewer'])
            ->where('id', '>', $afterIncidentId)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $entries = ConsultationAdminHistoryPresenter::toArray($consultation, $messages, $incidents, $detector, true);

        return response()->json([
            'html' => view('admin.consultations.partials.history-entries', [
                'consultation' => $consultation,
                'entries' => $entries,
            ])->render(),
            'last_message_id' => $messages->last()?->getKey() ?? $afterMessageId,
            'last_incident_id' => $incidents->last()?->getKey() ?? $afterIncidentId,
            'has_more' => $messages->count() === $limit || $incidents->count() === $limit,
            'consultation' => ConsultationStatePresenter::toArray($consultation),
        ]);
    }

    /**
     * Marca a ocorrência como revisada pela administração.
     */
    public function reviewIncident(
        Request $request,
        Consultation $consultation,
        ConsultationPrivacyIncident $incident,
    ): RedirectResponse {
        Gate::authorize('viewAdminHistory', $consultation);

        abort_unless($incident->consultation_id === $consultation->getKey(), 404);

        $validated = $request->validate([
            'review_notes' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $incident->forceFill([
            'status' => PrivacyIncidentStatus::Reviewed,
            'reviewed_by' => $user->getKey(),
            'reviewed_at' => now(),
            'review_notes' => $validated['review_notes'] ?? null,
        ])->save();

        AuditLog::query()->create([
            'actor_id' => $user->getKey(),
            'action' => 'consultation_privacy_incident.reviewed',
            'subject_type' => $consultation->getMorphClass(),
            'subject_id' => $consultation->getKey(),
            'metadata' => [
                'incident_id' => $incident->getKey(),
                'category' => $incident->category?->value,
                'ip' => $request->ip(),
            ],
        ]);

        return redirect()
            ->to(route('admin.consultations.show', $consultation).'#ocorrencias')
            ->with('success', 'Ocorrência marcada como revisada.');
    }

    /**
     * Imagem da carta no painel.
     *
     * A rota de participantes é negada ao administrador de propósito; esta
     * variante autoriza pela habilidade administrativa e registra o acesso.
     */
    public function image(
        Consultation $consultation,
        ConsultationMessage $message,
        ConsultationCardImageStorage $images,
    ): StreamedResponse {
        Gate::authorize('viewAdminHistory', $consultation);

        abort_unless($message->consultation_id === $consultation->getKey(), 404);
        abort_unless($message->type === MessageType::TarotCard, 404);

        AuditLog::query()->create([
            'actor_id' => request()->user()?->getKey(),
            'action' => 'consultation_history.card_image_viewed',
            'subject_type' => $consultation->getMorphClass(),
            'subject_id' => $consultation->getKey(),
            'metadata' => [
                'message_id' => $message->getKey(),
                'ip' => request()->ip(),
            ],
        ]);

        return $images->response($message);
    }

    /**
     * Registra o acesso administrativo ao histórico (dado sensível).
     */
    private function auditHistoryAccess(
        Request $request,
        Consultation $consultation,
        int $messageTotal,
        int $incidentTotal,
    ): void {
        AuditLog::query()->create([
            'actor_id' => $request->user()?->getKey(),
            'action' => 'consultation_history.viewed',
            'subject_type' => $consultation->getMorphClass(),
            'subject_id' => $consultation->getKey(),
            'metadata' => [
                'is_live' => $consultation->status === ConsultationStatus::Active,
                'messages' => $messageTotal,
                'privacy_incidents' => $incidentTotal,
                'ip' => $request->ip(),
            ],
        ]);
    }
}
