<x-layouts.guest title="Entrar | Arcana Tarot">
    <h1 class="h3 mb-1">Entrar</h1>
    <p class="text-ivory-muted mb-4">Acesse sua conta para acompanhar suas consultas.</p>

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

    <form method="POST" action="{{ route('login') }}" novalidate>
        @csrf

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
            autocomplete="current-password"
            required
        />

        <div class="form-check mb-4">
            <input class="form-check-input" type="checkbox" name="remember" id="remember" value="1" @checked(old('remember'))>
            <label class="form-check-label" for="remember">Lembrar de mim</label>
        </div>

        <x-ui.button type="submit" variant="primary" size="lg" block>Entrar</x-ui.button>
    </form>

    <div class="auth-card__links">
        <a href="{{ route('password.request') }}">Esqueceu a senha?</a>
        <span aria-hidden="true">·</span>
        <a href="{{ route('register') }}">Criar conta</a>
    </div>
</x-layouts.guest>
