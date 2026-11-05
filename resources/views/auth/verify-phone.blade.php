<x-layouts.guest title="Confirmar celular | Arcana Tarot">
    <h1 class="h3 mb-1">Confirme seu celular</h1>
    <p class="auth-card__subtitle mb-4">
        Enviamos um código por SMS para <strong>{{ $phone }}</strong>.
        Informe o código de {{ $codeLength }} dígitos para concluir seu cadastro.
    </p>

    @if (session('status'))
        <x-ui.alert type="success" :dismissible="false" class="mb-4">{{ session('status') }}</x-ui.alert>
    @endif

    @if ($errors->any())
        <x-ui.alert type="danger" :dismissible="false" class="mb-4">
            <ul class="mb-0 ps-3">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-ui.alert>
    @endif

    @if ($expired)
        <x-ui.alert type="warning" :dismissible="false" class="mb-4">
            O código anterior expirou. Use "Reenviar código" para receber um novo SMS.
        </x-ui.alert>
    @endif

    <form method="POST" action="{{ route('register.verify.store') }}" novalidate>
        @csrf

        <x-ui.field
            name="code"
            label="Código recebido por SMS"
            type="text"
            placeholder="000000"
            inputmode="numeric"
            autocomplete="one-time-code"
            maxlength="{{ $codeLength }}"
            data-code-input
            data-code-length="{{ $codeLength }}"
            :help="'O código vale por '.$expiresInMinutes.' minuto(s). Tentativas restantes: '.$remainingAttempts.'.'"
            autofocus
            required
        />

        <x-ui.button type="submit" variant="primary" size="lg" block>Confirmar código</x-ui.button>
    </form>

    <form method="POST" action="{{ route('register.verify.resend') }}" class="mt-3">
        @csrf
        <x-ui.button
            type="submit"
            variant="ghost"
            block
            data-resend-button
            data-resend-seconds="{{ $secondsUntilResend }}"
            data-resend-label="Reenviar código"
        >Reenviar código</x-ui.button>
    </form>

    <div class="auth-card__links">
        <span>Número errado?</span>
        <a href="{{ route('register') }}">Recomeçar cadastro</a>
    </div>
</x-layouts.guest>
