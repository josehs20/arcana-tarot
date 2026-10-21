<x-layouts.guest title="Recuperar senha | Arcana Tarot">
    <h1 class="h3 mb-1">Recuperar senha</h1>
    <p class="auth-card__subtitle mb-4">
        Informe o e-mail cadastrado e enviaremos o link de redefinição.
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

    <form method="POST" action="{{ route('password.email') }}" novalidate>
        @csrf

        <x-ui.field
            name="email"
            label="E-mail"
            type="email"
            placeholder="voce@exemplo.com"
            autocomplete="email"
            required
        />

        <x-ui.button type="submit" variant="primary" size="lg" block>Enviar link de redefinição</x-ui.button>
    </form>

    <div class="auth-card__links">
        <a href="{{ route('login') }}">Voltar para o login</a>
    </div>
</x-layouts.guest>
