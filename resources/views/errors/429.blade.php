<x-layouts.error
    code="429"
    title="Muitas tentativas"
    text="Você fez muitas solicitações em pouco tempo. Aguarde alguns instantes e tente novamente."
>
    <a class="btn btn-ghost" href="{{ url()->previous() }}">Voltar</a>
</x-layouts.error>
