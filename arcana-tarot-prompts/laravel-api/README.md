# arcana-tarot — preparação do Laravel para API + Flutter

Pacote complementar ao projeto Laravel existente. **Não recria o sistema e não implementa Flutter.** Objetivo: manter o site Blade, permitir AJAX no site e expor uma API REST versionada para futuros apps Flutter de clientes e atendentes.

## Decisões
- Site e painel admin continuam web/Blade; admin não terá app nesta etapa.
- Cliente e atendente poderão usar Flutter.
- Web mantém autenticação por sessão; app usa Laravel Sanctum/Bearer Token.
- Google Login deve atender web e mobile com fluxos seguros próprios.
- Web/AJAX e API compartilham regras de negócio via Services/Actions; AJAX **não precisa chamar a API REST**.
- API REST `/api/v1` funciona na Hostoo sem Docker. Docker não é requisito para HTTP/API.
- Reverb é opcional por ambiente: quando disponível, web e Flutter usam eventos/canais privados em tempo real; sem Reverb, site e API continuam operacionais. Não simular tempo real com polling infinito.
- MySQL e configuração atual da Hostoo devem ser preservados. Redis, filas e containers não podem ser requisitos para o funcionamento básico.

## Execução econômica de tokens
1. Abra a raiz do Laravel no Cursor.
2. Copie `.cursor/rules/*.mdc` para a pasta correspondente do projeto (mescle sem apagar regras existentes).
3. Execute um único prompt por vez, em ordem. Não envie todos juntos.
4. O prompt 00 é obrigatório: ele inspeciona o repositório e produz um plano curto, sem implementar.
5. Em cada etapa, o Cursor deve ler apenas arquivos diretamente relacionados; não despejar arquivos enormes nem reanalisar o projeto inteiro. Reutilize `docs/api-mobile/ANALISE.md` e `docs/api-mobile/STATUS.md` como contexto persistente.
6. Faça commit após cada etapa aprovada. Se um teste falhar, corrija antes de avançar.

## Ordem
| Prompt | Entrega |
|---|---|
| 00 | Auditoria objetiva e plano baseado no código real |
| 01 | Camada de aplicação compartilhada (Services/Actions), sem regressão |
| 02 | API v1, Sanctum, respostas/erros padronizados |
| 03 | Login, cadastro, tokens, Google web/mobile |
| 04 | APIs de leitura: perfil, atendentes, modalidades, baralhos/cartas, consultas e créditos |
| 05 | APIs de escrita: consultas, mensagens, cartas e operações financeiras existentes |
| 06 | Reverb, canais privados e autenticação para app; fallback sem Reverb |
| 07 | AJAX do site integrado à mesma camada de aplicação |
| 08 | Testes, documentação de endpoints e checklist Hostoo/produção |

## Regras de escopo
- Não alterar layout/identidade visual nem reimplementar funcionalidades existentes.
- Não renomear tabelas, reescrever autenticação ou trocar stack sem evidência e aprovação.
- Antes de criar classe, rota, migration ou dependência, procurar equivalente existente.
- Respeitar versões reais de PHP/Laravel do repositório; não assumir versão do pacote anterior.
- Não exigir Docker, Redis, Horizon, worker, Reverb ou serviços externos para API REST básica.
- Reverb pode depender de processo/serviço persistente; documentar a configuração de cada ambiente e nunca quebrar a Hostoo quando ausente.
- Nunca confiar no app/navegador para preço, saldo, comissão, duração, status, papel ou propriedade de consulta.
- Operações financeiras devem reutilizar o mecanismo atual, com transação, concorrência e idempotência adequadas. Não criar segundo ledger.
- Não expor dados privados, mensagens ou documentos fora das regras de autorização existentes.
- Não aceitar token Google/email informado livremente como prova de identidade. Validar credencial com provedor e vincular conta com segurança.
- Não adicionar CORS amplo (`*`) por conveniência. App nativo não exige CORS como navegador; configurar apenas origens web necessárias.
- Não implementar compras in-app, Firebase ou código Flutter nesta etapa. Registrar decisões/pendências para etapa mobile.
- Não fazer alterações destrutivas nem migrations irreversíveis sem explicar e pedir autorização.

## Saída obrigatória por etapa
Resumo curto; arquivos alterados; comandos/testes realmente executados; resultado; riscos/bloqueios; próximo prompt recomendado. Atualizar `docs/api-mobile/STATUS.md`.
