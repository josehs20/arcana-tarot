# 02 — API v1 e Sanctum

Leia ANALISE/STATUS. Prepare API REST sem afetar web.

- Confirme se Sanctum já existe e se é compatível com versões instaladas; instale/configure apenas se ausente e necessário.
- Organize rotas sob `/api/v1` sem duplicar rotas existentes inadvertidamente.
- Configure autenticação Bearer para app; mantenha sessão web intacta.
- Defina formato JSON consistente para sucesso, validação, não autenticado, proibido e não encontrado; respeite padrões existentes quando bons.
- Configure expiração/revogação de tokens conforme suporte da versão. Tokens com abilities mínimas; autorização real também via Policies/Gates.
- Configure rate limits por endpoint sensível. CORS somente se houver necessidade real para frontends web; não usar `*`.
- Crie endpoint autenticado mínimo de identidade (`GET /api/v1/me`) e teste token válido/inválido, anônimo e sessão web preservada.

Não implemente ainda todos os recursos. Documente instalação/migrations e rollback seguro. Hostoo deve servir API via PHP/Laravel normal, sem Docker/Redis.
