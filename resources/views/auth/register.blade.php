<x-layouts.guest title="Criar conta | Arcana Tarot">
    <h1 class="h3 mb-1">Criar conta</h1>
    <p class="auth-card__subtitle mb-4">Comece a explorar suas leituras em poucos passos.</p>

    @if ($errors->any())
        <x-ui.alert type="danger" :dismissible="false" class="mb-4">
            <ul class="mb-0 ps-3">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-ui.alert>
    @endif

    <form method="POST" action="{{ route('register') }}" novalidate>
        @csrf

        <x-ui.field
            name="name"
            label="Nome"
            type="text"
            placeholder="Como podemos te chamar?"
            autocomplete="name"
            required
        />

        <x-ui.field
            name="email"
            label="E-mail"
            type="email"
            placeholder="voce@exemplo.com"
            autocomplete="email"
            required
        />

        <x-ui.field
            name="password"
            label="Senha"
            type="password"
            autocomplete="new-password"
            help="Use ao menos 8 caracteres."
            required
        />

        <x-ui.field
            name="password_confirmation"
            label="Confirmar senha"
            type="password"
            autocomplete="new-password"
            required
        />

        <x-ui.button type="submit" variant="primary" size="lg" block>Criar conta</x-ui.button>
    </form>

    <div class="auth-card__links">
        <a href="{{ route('login') }}">Já tenho conta</a>
    </div>
</x-layouts.guest>
