{{-- Versão texto do e-mail de teste: mesmo conteúdo, sem HTML. --}}
@inject('siteContent', 'App\Support\SiteContent')
Transporte de e-mail funcionando

Este é um e-mail de teste do {{ $siteContent->name() }}. Ele saiu pelo mailer configurado no .env e chegou até aqui.

Destinatário: {{ $recipientEmail }}
Mailer: {{ $mailerName }}
Servidor: {{ $serverDescription }}
Ambiente: {{ $environment }}
Enviado em: {{ $sentAt }}

--
{{ $siteContent->name() }}
{{ route('home') }}
