<x-layouts.guest title="Confirme seu e-mail | Arcana Tarot">
    <h1 class="h3 mb-1">Confirme seu e-mail</h1>
    <p class="auth-card__subtitle mb-4">
        Sua conta já está criada. Enviamos um link de confirmação para
        <strong>{{ $email }}</strong> — abra a mensagem e clique no botão para
        liberar o acesso ao painel.
    </p>

    @if (session('status'))
        <x-ui.alert type="success" :dismissible="false" class="mb-4">{{ session('status') }}</x-ui.alert>
    @endif

    @if (session('warning'))
        <x-ui.alert type="warning" :dismissible="false" class="mb-4">{{ session('warning') }}</x-ui.alert>
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

    {{-- Reenvio: a rota tem limite de 6 tentativas por minuto por sessão. --}}
    <form method="POST" action="{{ route('verification.send') }}">
        @csrf
        <x-ui.button type="submit" variant="primary" size="lg" block>Reenviar e-mail</x-ui.button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="mt-3">
        @csrf
        <x-ui.button type="submit" variant="ghost" block>Sair</x-ui.button>
    </form>

    <p class="auth-card__subtitle mt-4 mb-0">
        Não achou a mensagem? Confira a caixa de spam ou lixo eletrônico.
        O link expira em {{ $expiresInMinutes }} minutos.
    </p>
</x-layouts.guest>
