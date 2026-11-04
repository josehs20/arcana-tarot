<x-layouts.panel variant="admin" title="Segurança do chat | Arcana Tarot">
    <x-layouts.page-header
        title="Segurança do chat"
        subtitle="Defina quando um atendimento inativo é encerrado e quanto tempo o navegador pode ficar desconectado antes da interrupção."
    >
        <x-slot name="actions">
            <a class="btn btn-ghost btn-sm" href="{{ route('admin.consultations.index') }}">Ver consultas</a>
        </x-slot>
    </x-layouts.page-header>

    <x-ui.alert type="info" :dismissible="false" class="mb-4">
        O servidor é a fonte da verdade. O navegador envia batimentos de presença, mas não informa
        quanto tempo ficou inativo: o encerramento usa os horários gravados nas requisições.
    </x-ui.alert>

    <div class="row g-4">
        <div class="col-lg-7">
            <x-ui.card class="h-100">
                <x-ui.card-header>
                    <h2 class="h6 mb-0">Limites de inatividade</h2>
                </x-ui.card-header>
                <x-ui.card-body>
                    <form method="POST" action="{{ route('admin.consultations.safety.update') }}" class="row g-3">
                        @csrf
                        @method('PATCH')

                        <div class="col-md-6">
                            <label class="form-label required" for="inactivity_timeout_minutes">
                                Tempo de inatividade (minutos)
                            </label>
                            <input
                                class="form-control @error('inactivity_timeout_minutes') is-invalid @enderror"
                                type="number"
                                id="inactivity_timeout_minutes"
                                name="inactivity_timeout_minutes"
                                min="1"
                                max="120"
                                step="1"
                                value="{{ old('inactivity_timeout_minutes', $settings['inactivity_timeout_minutes']) }}"
                                required
                            >
                            @error('inactivity_timeout_minutes')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <div class="form-text">
                                Sem mensagens, cartas ou interações de nenhum participante, o atendimento é encerrado.
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label required" for="reconnection_tolerance_seconds">
                                Tolerância de reconexão (segundos)
                            </label>
                            <input
                                class="form-control @error('reconnection_tolerance_seconds') is-invalid @enderror"
                                type="number"
                                id="reconnection_tolerance_seconds"
                                name="reconnection_tolerance_seconds"
                                min="0"
                                max="600"
                                step="1"
                                value="{{ old('reconnection_tolerance_seconds', $settings['reconnection_tolerance_seconds']) }}"
                                required
                            >
                            @error('reconnection_tolerance_seconds')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <div class="form-text">
                                Margem extra para quedas breves de rede antes de encerrar e cobrar o período.
                            </div>
                        </div>

                        <div class="col-12">
                            <x-ui.button type="submit" variant="primary" size="sm">Salvar limites</x-ui.button>
                        </div>
                    </form>
                </x-ui.card-body>
            </x-ui.card>
        </div>

        <div class="col-lg-5">
            <x-ui.card class="h-100">
                <x-ui.card-header>
                    <h2 class="h6 mb-0">Comportamento atual</h2>
                </x-ui.card-header>
                <x-ui.card-body>
                    <dl class="row small mb-0">
                        <dt class="col-7 text-ivory-muted fw-normal">Batimento de presença</dt>
                        <dd class="col-5 mb-2">{{ $activityPingSeconds }} s</dd>

                        <dt class="col-7 text-ivory-muted fw-normal">Sessão considerada online</dt>
                        <dd class="col-5 mb-2">{{ $presenceToleranceSeconds }} s</dd>

                        <dt class="col-7 text-ivory-muted fw-normal">Interrupção automática</dt>
                        <dd class="col-5 mb-2">{{ $interruptionThresholdSeconds }} s</dd>

                        <dt class="col-7 text-ivory-muted fw-normal">Aviso de inatividade na tela</dt>
                        <dd class="col-5 mb-0">{{ $settings['navigation_warning_seconds'] }} s</dd>
                    </dl>

                    <p class="form-text mt-3 mb-0">
                        Os batimentos mantêm a presença ativa, mas não contam como atividade. Assim, uma
                        aba aberta sem interação também é encerrada pelo tempo de inatividade.
                    </p>
                </x-ui.card-body>
            </x-ui.card>
        </div>
    </div>
</x-layouts.panel>
