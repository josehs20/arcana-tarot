# Prompt 18 — Segurança, privacidade e auditoria

Faça uma revisão de segurança dos módulos já implementados e corrija problemas dentro do escopo do MVP.

## Verificar
- Autorização no servidor em rotas, controllers, canais Reverb e consultas ao banco.
- Proteção contra IDOR/acesso cruzado.
- CSRF, XSS, validação de entradas e escaping.
- Rate limiting em login, mensagens e criação de solicitações.
- Proteção de segredos e arquivos `.env`.
- Upload de imagens de baralho/carta: validar MIME/conteúdo e tamanho no servidor, aceitar apenas formatos raster necessários (JPEG, PNG ou WebP), rejeitar SVG e executáveis, usar nomes aleatórios gerados pelo servidor e storage não público.
- Rotas que entregam imagens de cartas: verificar participação na consulta ou autorização administrativa específica; não permitir acesso a caminho arbitrário, IDOR, traversal ou exposição do caminho real.
- Mensagens de carta: snapshots históricos consistentes após edição/inativação do catálogo, nenhuma imagem ou metadado crítico confiado ao cliente.
- Logs sem senhas, tokens, payloads financeiros sensíveis ou conteúdo de conversa desnecessário.
- Permissões de administração e operações de ajuste de crédito/comissão.
- Formulário Trabalhe conosco: validação server-side, rate limiting, proteção contra spam, consentimento informado, acesso somente administrativo e minimização de dados pessoais.
- Transições e revisão de candidaturas: somente administradores podem revisar; registrar quem alterou o status e quando; notas internas nunca aparecem na página pública.
- Percentuais de comissão: validar intervalo de 0 a 100, guardar snapshot por consulta, impedir edição retroativa e proteger lançamentos contra duplicidade/concorrrência.
- Webhooks autenticados, idempotência e proteção contra repetição.
- Configuração segura de cookies/sessões em produção.
- Política de retenção de mensagens e dados pessoais documentada para decisão do responsável.
- Termos de uso e política de privacidade marcados como pendentes de revisão jurídica caso sejam apenas modelos.
- Backups e instruções de restauração.
- Dependências desatualizadas ou vulnerabilidades detectáveis pelas ferramentas disponíveis.

Não afirme conformidade jurídica garantida. Documente riscos restantes e medidas de mitigação. Não leia nem exponha segredos reais. Execute testes de segurança automatizáveis disponíveis.
