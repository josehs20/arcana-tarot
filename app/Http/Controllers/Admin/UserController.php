<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ConsultationModality;
use App\Enums\Gender;
use App\Enums\LedgerEntryType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAttendantRequest;
use App\Http\Requests\Admin\StoreWalletAdjustmentRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\AttendantAccountManager;
use App\Support\AttendantModalityManager;
use App\Support\AttendantPhotoStorage;
use App\Support\CommissionRateManager;
use App\Support\ConsultationModalityCatalog;
use App\Support\CreditWalletManager;
use App\Support\InsufficientCreditBalanceException;
use App\Support\Money;
use App\Support\UserAccessManager;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * Contas pelo painel administrativo.
 *
 * A listagem é somente leitura de apoio; as rotas de cadastro e edição
 * concentram as regras: todo atendente criado aqui nasce offline, o perfil
 * público é editável na mesma tela e o saldo do cliente só muda pelo fluxo
 * auditado de ajuste de carteira.
 */
class UserController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', User::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::enum(UserRole::class)],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $search = trim((string) ($filters['q'] ?? ''));

        $users = User::query()
            ->with(['wallet', 'attendantProfile'])
            ->when($search !== '', function ($query) use ($search): void {
                $term = '%'.$search.'%';

                $query->where(function ($query) use ($term): void {
                    $query->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term);
                });
            })
            ->when(
                isset($filters['role']),
                fn ($query) => $query->where('role', $filters['role']),
            )
            ->when(
                ($filters['status'] ?? null) === 'active',
                fn ($query) => $query->where('is_active', true),
            )
            ->when(
                ($filters['status'] ?? null) === 'inactive',
                fn ($query) => $query->where('is_active', false),
            )
            ->orderByRaw("case role when 'admin' then 0 when 'attendant' then 1 else 2 end")
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'filters' => $filters,
            'roles' => UserRole::cases(),
        ]);
    }

    /**
     * Formulário de cadastro de atendente.
     */
    public function create(ConsultationModalityCatalog $catalog): View
    {
        Gate::authorize('create', User::class);

        return view('admin.users.form', [
            'user' => null,
            'profile' => null,
            'wallet' => null,
            'modalities' => ConsultationModality::cases(),
            'genders' => Gender::options(),
            'catalog' => $catalog,
            'ledgerTypes' => LedgerEntryType::cases(),
            'operationId' => (string) Str::uuid(),
        ]);
    }

    /**
     * Cadastro de atendente com perfil, foto, comissão e preços por modalidade.
     *
     * A conta já nasce ativa e verificada, mas o perfil entra offline: só o
     * próprio profissional declara presença no diretório.
     */
    public function store(
        StoreAttendantRequest $request,
        AttendantAccountManager $accounts,
        AttendantPhotoStorage $photos,
    ): RedirectResponse {
        $validated = $request->validated();

        $file = $request->file('photo');
        $photoPath = $file instanceof UploadedFile ? $photos->store($file) : null;

        try {
            [$user] = $accounts->create($request->user(), [
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'birth_date' => $validated['birth_date'] ?? null,
                'password' => $validated['password'],
                'display_name' => $validated['display_name'],
                'bio' => $validated['bio'] ?? null,
                'specialties' => $validated['specialties'] ?? [],
                'photo_path' => $photoPath,
                'commission_rate_bps' => (int) $request->commissionRateBps(),
                'is_published' => (bool) ($validated['is_published'] ?? false),
            ], $request->priceCents());
        } catch (Throwable $exception) {
            // O arquivo já está no disco: se a transação falhar, nada pode sobrar.
            $photos->delete($photoPath);

            throw $exception;
        }

        return redirect()
            ->route('admin.users.edit', $user)
            ->with('success', 'Atendente cadastrado. O perfil fica offline até o atendente entrar no ar.');
    }

    /**
     * Ficha de edição de cliente ou atendente.
     */
    public function edit(
        User $user,
        ConsultationModalityCatalog $catalog,
    ): View {
        Gate::authorize('update', $user);

        $user->loadMissing(['wallet', 'attendantProfile.modalityPrices']);

        return view('admin.users.form', [
            'user' => $user,
            'profile' => $user->attendantProfile,
            'wallet' => $user->wallet,
            'modalities' => ConsultationModality::cases(),
            'genders' => Gender::options(),
            'catalog' => $catalog,
            'ledgerTypes' => LedgerEntryType::cases(),
            'operationId' => (string) Str::uuid(),
        ]);
    }

    /**
     * Atualiza os dados cadastrais e, para atendentes, o perfil público.
     *
     * Cada bloco tem seu próprio gestor (conta, perfil, modalidades e
     * comissão) para que a auditoria registre alterações separadas. A foto
     * substituída só é apagada do disco depois do sucesso completo.
     */
    public function update(
        UpdateUserRequest $request,
        User $user,
        AttendantAccountManager $accounts,
        AttendantModalityManager $modalities,
        AttendantPhotoStorage $photos,
        CommissionRateManager $commissions,
    ): RedirectResponse {
        Gate::authorize('update', $user);

        $validated = $request->validated();
        $actor = $request->user();
        $profile = $user->attendantProfile;
        $previousPhotoPath = $profile?->photo_path;

        $file = $request->file('photo');
        $storedPhoto = $file instanceof UploadedFile && $profile !== null
            ? $photos->store($file)
            : null;

        try {
            $this->updateAccount($user, $validated, $actor);

            if ($profile !== null) {
                $removePhoto = (bool) ($validated['remove_photo'] ?? false);
                $photoPath = $storedPhoto ?? ($removePhoto ? null : $previousPhotoPath);

                $accounts->updateProfile($profile, $actor, [
                    'display_name' => $validated['display_name'],
                    'bio' => $validated['bio'] ?? null,
                    'specialties' => $validated['specialties'] ?? [],
                    'photo_path' => $photoPath,
                    'is_published' => (bool) ($validated['is_published'] ?? false),
                ]);

                /*
                 * Preço em branco desabilita a modalidade. Modalidades com
                 * infraestrutura desligada não são tocadas por esta tela.
                 */
                $modalities->syncPrices($profile, $actor, $request->priceCents());

                $rateBps = $request->commissionRateBps();

                if ($rateBps !== null) {
                    $commissions->update($user, $actor, $rateBps);
                }
            }
        } catch (Throwable $exception) {
            $photos->delete($storedPhoto);

            throw $exception;
        }

        if ($storedPhoto !== null || ($validated['remove_photo'] ?? false)) {
            $photos->delete($previousPhotoPath);
        }

        return redirect()
            ->route('admin.users.edit', $user)
            ->with('success', 'Dados atualizados com sucesso.');
    }

    /**
     * Ajuste de créditos a partir da ficha do cliente.
     *
     * Reaproveita o fluxo da tesouraria: motivo obrigatório, chave de
     * idempotência por operação e registro no extrato da carteira.
     */
    public function adjustCredit(
        StoreWalletAdjustmentRequest $request,
        User $user,
        CreditWalletManager $wallets,
    ): RedirectResponse {
        $validated = $request->validated();

        try {
            $entry = $wallets->adjust(
                target: $user,
                actor: $request->user(),
                type: LedgerEntryType::from($validated['type']),
                amountCents: (int) $validated['amount_cents'],
                reason: trim((string) $validated['reason']),
                idempotencyKey: 'admin_adjustment:'.$user->getKey().':'.$validated['operation_id'],
            );
        } catch (InsufficientCreditBalanceException $exception) {
            throw ValidationException::withMessages([
                'amount_cents' => $exception->getMessage(),
            ]);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'operation_id' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('admin.users.edit', $user)
            ->with('success', 'Ajuste registrado. Saldo atual: '.Money::format($entry->balance_after_cents).'.');
    }

    public function updateStatus(
        Request $request,
        User $user,
        UserAccessManager $manager,
    ): RedirectResponse {
        Gate::authorize('manageStatus', $user);

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $active = (bool) $validated['is_active'];

        $manager->setActive($user, $active, $request->user());

        return back()->with(
            'success',
            $active
                ? 'Conta ativada com sucesso.'
                : 'Conta desativada com sucesso.',
        );
    }

    /**
     * Dados cadastrais da conta: nome, e-mail, telefone, nascimento e senha.
     *
     * A senha é opcional; em branco, o acesso atual é preservado. A auditoria
     * guarda o antes e o depois, além de sinalizar a troca de senha (sem
     * registrar o valor).
     *
     * @param  array<string, mixed>  $validated
     */
    private function updateAccount(User $user, array $validated, User $actor): void
    {
        DB::transaction(function () use ($user, $validated, $actor): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());

            $before = [
                'name' => $locked->name,
                'email' => $locked->email,
                'phone' => $locked->phone,
                'birth_date' => $locked->birth_date?->toDateString(),
                'gender' => $locked->gender?->value,
            ];

            $locked->fill([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'birth_date' => $validated['birth_date'] ?? null,
                'gender' => $validated['gender'] ?? null,
            ]);

            $passwordChanged = filled($validated['password'] ?? null);

            if ($passwordChanged) {
                $locked->password = $validated['password'];
            }

            $locked->save();

            $after = [
                'name' => $locked->name,
                'email' => $locked->email,
                'phone' => $locked->phone,
                'birth_date' => $locked->birth_date?->toDateString(),
                'gender' => $locked->gender?->value,
            ];

            if ($before === $after && ! $passwordChanged) {
                return;
            }

            AuditLog::query()->create([
                'actor_id' => $actor->getKey(),
                'action' => 'user.updated_by_operator',
                'subject_type' => $locked->getMorphClass(),
                'subject_id' => $locked->getKey(),
                'metadata' => [
                    'role' => $locked->role->value,
                    'before' => $before,
                    'after' => $after,
                    'password_reset' => $passwordChanged,
                ],
            ]);
        });
    }
}
