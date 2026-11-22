{{--
    Heartbeat de acessos: avisa o servidor, a cada minuto, que a aba continua
    aberta e visível. Sem ele o tempo no site seria apenas o intervalo entre
    page views. O endpoint responde 204 e não expõe dados do visitante.
--}}
@if (config('access_log.enabled'))
    <meta name="visit-heartbeat-endpoint" content="{{ route('access.heartbeat') }}">
    <meta name="visit-heartbeat-interval" content="{{ (int) config('access_log.heartbeat_seconds', 60) }}">
@endif
