<x-layouts.error
    code="419"
    title="Sessão expirada"
    text="Sua sessão expirou por segurança. Recarregue a página e tente novamente — nada foi perdido."
>
    <a class="btn btn-ghost" href="{{ url()->previous() }}">Recarregar</a>
</x-layouts.error>
