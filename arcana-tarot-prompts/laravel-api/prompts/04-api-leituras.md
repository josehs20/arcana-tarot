# 04 — Endpoints de leitura

Leia ANALISE/STATUS. Implemente API v1 somente para consultas de leitura necessárias aos apps cliente e atendente:
- perfil/conta e saldo/histórico de créditos;
- lista/detalhe de atendentes e disponibilidade pública;
- modalidades e preços efetivos por atendente;
- baralhos/cartas disponíveis;
- consultas do usuário autenticado e detalhe/histórico autorizado.

Use Resources/DTOs e paginação. Retorne apenas campos necessários ao papel; não exponha dados privados de outros usuários, dados administrativos ou mensagens sem autorização. Cliente só acessa seus dados/consultas; atendente só consultas atribuídas a ele. Admin permanece no painel web.

Reutilize consultas e regras existentes. Não criar tabelas nem duplicar lógica. Testar autenticação, papéis, ownership, paginação e filtros. Documentar rotas e exemplos mínimos em `docs/api-mobile/ENDPOINTS.md`.
