@php($maxMb = max(1, intdiv(max(1, (int) config('images.max_upload_kb', 25600)), 1024)))

<x-layouts.error
    code="413"
    title="Arquivo muito grande"
    :text="'O envio ultrapassou o limite aceito pelo servidor. Cada imagem pode ter até '.$maxMb.' MB. Se a foto veio do celular, reduza a resolução ou envie novamente pelo formulário — o navegador otimiza arquivos grandes antes do envio.'"
>
    <a class="btn btn-ghost" href="{{ url()->previous() }}">Voltar</a>
</x-layouts.error>
