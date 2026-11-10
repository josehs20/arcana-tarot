{{--
    E-mail de verificação do transporte (arcana:send-test-email).

    Estilos inline porque clientes de e-mail descartam o CSS da aplicação. O
    conteúdo repete o resumo do terminal para que o teste valide também a
    renderização e o remetente efetivo, não só a autenticação SMTP.
--}}
@inject('siteContent', 'App\Support\SiteContent')

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subjectLine }}</title>
</head>
<body style="margin:0;padding:0;background-color:#0a0918;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#0a0918;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;background-color:#171433;border:1px solid #2c2650;border-radius:16px;">
                    <tr>
                        <td style="padding:28px 32px 0 32px;">
                            @if ($siteContent->logoUrl())
                                <img
                                    src="{{ $siteContent->logoUrl() }}"
                                    alt="{{ $siteContent->name() }}"
                                    style="display:block;max-height:48px;width:auto;border:0;"
                                >
                            @else
                                <span style="font-family:Georgia,'Times New Roman',serif;font-size:22px;letter-spacing:.12em;color:#c9a84c;text-transform:uppercase;">
                                    {{ $siteContent->name() }}
                                </span>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:20px 32px 8px 32px;">
                            <h1 style="margin:0 0 12px 0;font-family:Georgia,'Times New Roman',serif;font-size:22px;line-height:1.3;color:#f4f1e8;">
                                Transporte de e-mail funcionando
                            </h1>
                            <p style="margin:0;font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:1.7;color:#f4f1e8;">
                                Este é um e-mail de teste do {{ $siteContent->name() }}. Ele saiu pelo mailer
                                configurado no <code style="color:#c9a84c;">.env</code> e chegou até aqui.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 32px 8px 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-family:Helvetica,Arial,sans-serif;font-size:14px;line-height:1.7;color:#f4f1e8;">
                                <tr>
                                    <td style="padding:6px 0;color:#8a84a3;width:150px;">Destinatário</td>
                                    <td style="padding:6px 0;">{{ $recipientEmail }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:6px 0;color:#8a84a3;">Mailer</td>
                                    <td style="padding:6px 0;">{{ $mailerName }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:6px 0;color:#8a84a3;">Servidor</td>
                                    <td style="padding:6px 0;">{{ $serverDescription }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:6px 0;color:#8a84a3;">Ambiente</td>
                                    <td style="padding:6px 0;">{{ $environment }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:6px 0;color:#8a84a3;">Enviado em</td>
                                    <td style="padding:6px 0;">{{ $sentAt }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 32px 28px 32px;">
                            <div style="border-top:1px solid #2c2650;padding-top:16px;font-family:Helvetica,Arial,sans-serif;font-size:12px;line-height:1.6;color:#8a84a3;">
                                Mensagem automática gerada por
                                <a href="{{ route('home') }}" style="color:#c9a84c;text-decoration:underline;">{{ $siteContent->name() }}</a>.
                                Nenhuma ação é necessária.
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
