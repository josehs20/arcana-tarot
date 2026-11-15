# 03 — Autenticação mobile e Google

Leia ANALISE/STATUS e reutilize login/cadastro/Google atuais.

Implemente endpoints necessários em `/api/v1/auth`: login, cadastro (se permitido pelas regras atuais), logout/revogação, usuário autenticado e fluxo Google mobile. Use Sanctum para emitir/revogar tokens. Preserve autenticação web por sessão e o fluxo Google web existente.

Google mobile: validar no backend a credencial/token emitido pelo SDK oficial e sua audiência/client ID; nunca confiar em email/nome recebidos sem validação. Vincular conta por identificador Google verificado, evitando takeover e duplicidade. Não armazenar token Google como token de sessão da API. Respeitar verificação de telefone/email e bônus de primeiro acesso já existentes.

Aplicar validação, rate limit, respostas sem dados sensíveis e testes para sucesso, falha, conta existente, vínculo seguro, logout e bloqueios. Não implementar interface Flutter nem instalar SDK mobile. Se a configuração Google atual não suportar fluxo mobile, documente exatamente os IDs/ajustes necessários sem inventar credenciais.
