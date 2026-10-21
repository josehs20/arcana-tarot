<x-layouts.guest title="Redefinir senha | Arcana Tarot">
    <h1 class="h3 mb-1">Redefinir senha</h1>
    <p class="auth-card__subtitle mb-4">Escolha uma nova senha para acessar sua conta.</p>

    @if ($errors->any())
        <x-ui.alert type="danger" :dismissible="false" class="mb-4">
            <ul class="mb-0 ps-3">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-ui.alert>
    @endif

    <form method="POST" action="{{ route('password.store') }}" novalidate>
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <x-ui.field
            name="email"
            label="E-mail"
            type="email"
            autocomplete="email"
            :value="$request->email"
            required
        />

        <x-ui.field
            name="password"
            label="Nova senha"
            type="password"
            autocomplete="new-password"
            help="Use ao menos 8 caracteres."
            required
        />

        <x-ui.field
            name="password_confirmation"
            label="Confirmar nova senha"
            type="password"
            autocomplete="new-password"
            required
        />

        <x-ui.button type="submit" variant="primary" size="lg" block>Redefinir senha</x-ui.button>
    </form>

    <div class="auth-card__links">
        <a href="{{ route('login') }}">Voltar para o login</a>
    </div>
</x-layouts.guest>
