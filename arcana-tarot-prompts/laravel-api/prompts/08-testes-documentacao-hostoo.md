# 08 — Validação, documentação e Hostoo

Leia ANALISE/STATUS/ENDPOINTS. Execute a suíte existente e complete testes essenciais para API e web: autenticação sessão/token, papéis cliente/atendente/admin, ownership, validação, rate limit, mensagens/cartas, consultas, integridade financeira/idempotência, canais Reverb e funcionamento sem Reverb.

Corrija regressões introduzidas nesta iniciativa; não remova/afrouxe testes. Gere/atualize:
- `docs/api-mobile/ENDPOINTS.md`: método, rota, auth, papel, parâmetros, resposta e erros;
- `docs/api-mobile/DEPLOY.md`: Hostoo sem Docker (PHP/extensões/Composer, migrations, storage, cache, HTTPS, variáveis sem segredos), ambiente com containers e Reverb opcional;
- `docs/api-mobile/FLUTTER-CONTRACT.md`: base URL, Sanctum Bearer, login/Google, formato de erros, endpoints, canais/eventos e requisitos de conexão.

Confirme por teste que `/api/v1` funciona como HTTP Laravel sem Docker/Redis/Reverb. Não publicar segredos, não executar deploy nem alterar produção. Informe comandos realmente executados, testes e limitações restantes. Atualize STATUS para concluído/parcial por item.
