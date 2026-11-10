{{--
    E-mail de confirmação de cadastro.

    Estilos inline porque clientes de e-mail descartam o CSS da aplicação. O
    botão é o caminho principal; a URL também aparece em texto, para quem estiver
    com o cliente bloqueando botões ou links.
--}}
@inject('siteContent', 'App\Support\SiteContent')

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirme seu e-mail</title>
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
                            <div style="font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:1.7;color:#f4f1e8;">
                                <p style="margin:0 0 16px 0;">Olá, {{ $recipientName }}.</p>

                                <p style="margin:0 0 16px 0;">
                                    Recebemos seu cadastro em {{ $siteContent->name() }} com este endereço
                                    (<strong>{{ $recipientEmail }}</strong>). Clique no botão abaixo para
                                    confirmar que o e-mail é seu e liberar o acesso ao painel.
                                </p>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:8px 32px 8px 32px;">
                            <a
                                href="{{ $verificationUrl }}"
                                style="display:block;padding:14px 24px;background-color:#c9a84c;color:#171433;font-family:Helvetica,Arial,sans-serif;font-size:15px;font-weight:bold;text-align:center;text-decoration:none;border-radius:10px;"
                            >Confirmar meu e-mail</a>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:8px 32px 24px 32px;">
                            <div style="font-family:Helvetica,Arial,sans-serif;font-size:13px;line-height:1.7;color:#8a84a3;">
                                <p style="margin:16px 0 8px 0;">
                                    O link vale por {{ $expiresInMinutes }} minutos. Se ele expirar, abra a
                                    aplicação e use o botão "Reenviar e-mail".
                                </p>

                                <p style="margin:0 0 8px 0;">
                                    Se o botão não funcionar, copie e cole este endereço no navegador:
                                </p>

                                <p style="margin:0;word-break:break-all;">
                                    <a href="{{ $verificationUrl }}" style="color:#c9a84c;text-decoration:underline;">{{ $verificationUrl }}</a>
                                </p>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 32px 28px 32px;">
                            <div style="border-top:1px solid #2c2650;padding-top:16px;font-family:Helvetica,Arial,sans-serif;font-size:12px;line-height:1.6;color:#8a84a3;">
                                Se você não fez este cadastro, ignore esta mensagem: nenhuma conta é
                                ativada sem a confirmação do link.
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
