<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SendBulkEmailRequest;
use App\Http\Requests\Admin\UpdateBulkEmailTemplateRequest;
use App\Models\EmailCampaign;
use App\Models\User;
use App\Support\BulkEmailDispatcher;
use App\Support\BulkEmailTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Disparo de e-mails em massa (/admin/emails).
 *
 * A tela reúne seleção de destinatários, editor do corpo padrão e histórico. A
 * requisição só cria a campanha e enfileira um job por destinatário: nenhum
 * e-mail é enviado durante o POST, então a lista pode ter centenas de contas
 * sem estourar o tempo de resposta.
 */
class EmailCampaignController extends Controller
{
    public function index(
        Request $request,
        BulkEmailDispatcher $dispatcher,
        BulkEmailTemplate $template,
    ): View {
        Gate::authorize('viewAny', User::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::enum(UserRole::class)],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        return view('admin.emails.index', [
            'recipients' => $dispatcher->recipientsQuery($filters)->paginate(50)->withQueryString(),
            'filters' => $filters,
            'roles' => UserRole::cases(),
            'campaigns' => EmailCampaign::query()
                ->with('creator')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(10, ['*'], 'campaigns_page')
                ->withQueryString(),
            'template' => [
                'subject' => $template->subject(),
                'body' => $template->body(),
            ],
            'maxRecipients' => BulkEmailDispatcher::MAX_RECIPIENTS,
            'mailerIsLog' => config('mail.default') === 'log',
            'queueIsSync' => config('queue.default') === 'sync',
        ]);
    }

    public function store(SendBulkEmailRequest $request, BulkEmailDispatcher $dispatcher): RedirectResponse
    {
        $validated = $request->validated();

        $campaign = $dispatcher->create(
            actor: $request->user(),
            subject: (string) $validated['subject'],
            body: (string) $validated['body'],
            userIds: array_map('intval', (array) $validated['recipients']),
        );

        return redirect()
            ->route('admin.emails.show', $campaign)
            ->with('success', sprintf(
                'Disparo criado para %d destinatário(s). O envio está na fila.',
                $campaign->recipients_count,
            ));
    }

    public function updateTemplate(
        UpdateBulkEmailTemplateRequest $request,
        BulkEmailTemplate $template,
    ): RedirectResponse {
        $validated = $request->validated();

        $template->save(
            (string) $validated['subject'],
            (string) $validated['body'],
            $request->user(),
        );

        return redirect()
            ->route('admin.emails.index')
            ->with('success', 'Corpo padrão salvo. Ele será usado nos próximos disparos.');
    }

    public function show(EmailCampaign $campaign): View
    {
        Gate::authorize('viewAny', User::class);

        return view('admin.emails.show', [
            'campaign' => $campaign->load('creator'),
            'recipients' => $campaign->recipients()
                ->orderByRaw("case status when 'failed' then 0 when 'pending' then 1 else 2 end")
                ->orderBy('name')
                ->paginate(50)
                ->withQueryString(),
            'pendingCount' => $campaign->pendingCount(),
        ]);
    }

    public function resend(
        Request $request,
        EmailCampaign $campaign,
        BulkEmailDispatcher $dispatcher,
    ): RedirectResponse {
        Gate::authorize('viewAny', User::class);

        $requeued = $dispatcher->resendFailed($campaign, $request->user());

        return redirect()
            ->route('admin.emails.show', $campaign)
            ->with('success', sprintf('%d falha(s) reenfileirada(s) para um novo envio.', $requeued));
    }
}
