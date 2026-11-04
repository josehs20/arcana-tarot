<x-layouts.panel variant="admin" title="Usuários | Arcana Tarot">
    <x-layouts.page-header
        title="Usuários"
        subtitle="Gerencie contas de clientes, atendentes e administradores."
    >
        <x-slot name="actions">
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.dashboard') }}">Voltar ao painel</a>
        </x-slot>
    </x-layouts.page-header>

    <x-ui.card class="mb-4">
        <x-ui.card-body>
            <form method="GET" action="{{ route('admin.users.index') }}" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label" for="q">Buscar</label>
                    <input
                        class="form-control"
                        type="search"
                        id="q"
                        name="q"
                        value="{{ $filters['q'] ?? '' }}"
                        placeholder="Nome ou e-mail"
                        maxlength="100"
                    >
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="role">Papel</label>
                    <select class="form-select" id="role" name="role">
                        <option value="">Todos</option>
                        @foreach ($roles as $role)
                            <option value="{{ $role->value }}" @selected(($filters['role'] ?? '') === $role->value)>
                                {{ match ($role->value) {
                                    'admin' => 'Administrador',
                                    'attendant' => 'Atendente',
                                    'client' => 'Cliente',
                                    default => $role->value,
                                } }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="status">Status</label>
                    <select class="form-select" id="status" name="status">
                        <option value="">Todos</option>
                        <option value="active" @selected(($filters['status'] ?? '') === 'active')>Ativas</option>
                        <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Inativas</option>
                    </select>
                </div>

                <div class="col-md-2 d-flex gap-2">
                    <x-ui.button type="submit" variant="primary" size="sm">Filtrar</x-ui.button>
                    <a class="btn btn-ghost btn-sm" href="{{ route('admin.users.index') }}">Limpar</a>
                </div>
            </form>
        </x-ui.card-body>
    </x-ui.card>

    <x-ui.card>
        @if ($users->isEmpty())
            <x-ui.card-body>
                <x-ui.empty-state
                    icon="◇"
                    title="Nenhum usuário encontrado"
                    text="Ajuste os filtros para localizar contas cadastradas."
                />
            </x-ui.card-body>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Usuário</th>
                            <th scope="col">Papel</th>
                            <th scope="col">Status</th>
                            <th scope="col">Presença</th>
                            <th scope="col">Comissão</th>
                            <th scope="col">Saldo</th>
                            <th scope="col" class="text-end">Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($users as $user)
                            <tr>
                                <td>
                                    <span class="fw-medium">{{ $user->name }}</span>
                                    <div class="form-text">{{ $user->email }}</div>
                                </td>
                                <td>
                                    {{ match ($user->role->value) {
                                        'admin' => 'Administrador',
                                        'attendant' => 'Atendente',
                                        'client' => 'Cliente',
                                        default => $user->role->value,
                                    } }}
                                </td>
                                <td>
                                    @if ($user->is_active)
                                        <x-ui.status-badge status="online" label="Ativa" />
                                    @else
                                        <x-ui.status-badge status="inactive" />
                                    @endif
                                </td>
                                <td>
                                    @if ($user->attendantProfile)
                                        <x-ui.status-badge :status="$user->attendantProfile->availability->value" />
                                    @else
                                        <span class="text-ivory-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($user->isAttendant() && $user->attendantProfile)
                                        {{ \App\Support\Rate::formatBps($user->attendantProfile->commission_rate_bps) }}
                                    @else
                                        <span class="text-ivory-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    {{ $user->wallet ? \App\Support\Money::format($user->wallet->balance_cents) : '—' }}
                                </td>
                                <td class="text-end">
                                    @if ($user->isClient())
                                        <a class="btn btn-ghost btn-sm" href="{{ route('admin.finance.wallets.show', $user) }}">Carteira</a>
                                    @endif
                                    @if ($user->isAttendant() && $user->attendantProfile)
                                        <a class="btn btn-ghost btn-sm" href="{{ route('admin.commissions.attendant', $user) }}">Comissão</a>
                                        <a class="btn btn-ghost btn-sm" href="{{ route('admin.attendants.index', ['q' => $user->email]) }}">Som</a>
                                    @endif
                                    @can('manageStatus', $user)
                                        <form method="POST" action="{{ route('admin.users.status', $user) }}" class="d-inline-flex">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="is_active" value="{{ $user->is_active ? 0 : 1 }}">
                                            <x-ui.button
                                                type="submit"
                                                variant="ghost"
                                                size="sm"
                                                :class="$user->is_active ? 'text-danger' : ''"
                                            >
                                                {{ $user->is_active ? 'Desativar' : 'Ativar' }}
                                            </x-ui.button>
                                        </form>
                                    @else
                                        <span class="form-text">Protegido</span>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-ui.card-footer>
                {{ $users->links() }}
            </x-ui.card-footer>
        @endif
    </x-ui.card>
</x-layouts.panel>

