{{--
    Lista de itens da linha do tempo. Usada na página e na resposta JSON de
    atualização automática (`admin.consultations.entries`).

    Espera: $entries (list<array>).
--}}
@foreach ($entries as $entry)
    @include('admin.consultations.partials.history-entry', ['entry' => $entry])
@endforeach
