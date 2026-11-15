# 06 — Reverb e canais privados multiplataforma

Leia ANALISE/STATUS e eventos existentes. Faça web e futuro Flutter compartilharem os eventos atuais, sem quebrar o chat web.

- Reutilize nomes/payloads de eventos quando seguros; padronize somente o necessário.
- Autorize canais privados/presence por usuário, papel e vínculo com a consulta. Sanctum deve autenticar a autorização de broadcasting para app conforme versão Laravel instalada.
- Não permitir que cliente/atendente assine consultas alheias.
- Documente configuração do cliente Flutter (protocolo Pusher/Echo, auth endpoint, headers Bearer, eventos e canais), sem escrever Flutter.
- Reverb é opcional por ambiente. Sem Reverb, API REST, site e operações persistidas continuam funcionando; UI deve indicar indisponibilidade e usar fallback simples/limitado se o fluxo atual já tiver polling.
- Não exigir Redis ou worker para o caminho básico Hostoo. Se eventos dependem de fila, documente modo síncrono/alternativa compatível sem comprometer consistência.
- Não alegar tempo real na Hostoo sem processo Reverb ativo.

Testar autorização de canal, eventos web existentes e fallback com broadcasting desabilitado. Documentar requisitos de serviço persistente para produção.
