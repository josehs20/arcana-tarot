# Segurança, privacidade e auditoria — revisão do MVP

> Revisão executada em **2026-10-21** sobre os módulos dos Prompts 01–17.
> Este documento registra controles implementados, riscos residuais e decisões
> que ainda dependem do responsável legal/operacional. **Não é uma declaração de
> conformidade jurídica.**

## 1. Escopo e método

Foram revisados:

- rotas web, middlewares, controllers, policies e canais Reverb;
- consultas ao banco e isolamento entre clientes/atendentes;
- uploads e entrega de imagens de cartas;
- snapshots de mensagens de carta;
- login, criação de consultas, mensagens, cartas, recargas e candidaturas;
- webhook PIX, idempotência e segredos;
- operações administrativas de crédito, comissão, catálogo e candidaturas;
- logs, cookies/sessão e configuração do Nginx;
- dependências PHP e JavaScript disponíveis;
- documentação de retenção, backups e resposta a incidentes.

Foram executados a suíte automatizada, build do frontend e auditorias de
dependências. Não foram usados dados reais, segredos reais ou ambiente de
produção. A auditoria não substitui pentest independente.

## 2. Controles implementados

### 2.1 Autorização no servidor

- Todas as áreas internas estão sob `auth`; `admin`, `attendant` e `client`
  exigem o middleware `role` correspondente.
- Controllers repetem a autorização com `Gate::authorize` e policies por modelo.
- `ConsultationPolicy` permite metadados de consulta a administrador ativo, mas
  `viewMessages` é exclusivo do cliente e do atendente participantes.
- `TarotCatalogPolicy::viewImage` exige administrador ativo ou participante de
  consulta ativa, além de carta e baralho ativos.
- `CommissionLedgerEntryPolicy` restringe o razão global e estornos a admin.
- `PaymentPolicy::viewAnyAsAdmin` protege a listagem global de pagamentos;
  clientes continuam vendo apenas as próprias recargas.
- `AuditLogPolicy` permite apenas leitura por administrador ativo.
- O canal `consultation.{id}` valida conta ativa e participação antes de
  autorizar a inscrição no Reverb. Administrador e terceiros recebem 403.

### 2.2 IDOR e isolamento de dados

- Históricos de cliente e atendente filtram por `client_id`/`attendant_id` da
  sessão, sem aceitar ID de terceiros como parâmetro de consulta.
- Recarga e consulta verificam o dono no controller/policy e nas queries.
- A rota de imagem valida se a mensagem pertence à consulta da URL, além de
  revalidar a participação.
- Testes cobrem acesso cruzado a consultas, mensagens, imagens, pagamentos,
  candidaturas, comissões e relatórios.
- A tela administrativa de consulta mostra apenas metadados; a conversa não é
  renderizada.

### 2.3 CSRF, XSS e entradas

- Formulários web usam token CSRF padrão do Laravel.
- O webhook PIX é a única rota que dispensa CSRF, por ser server-to-server, e
  exige assinatura HMAC + janela de timestamp.
- Textos são renderizados com escaping do Blade. Teste cobre `<script>` em
  mensagem e metadados de auditoria.
- Campos monetários são inteiros em centavos; o navegador não envia saldo, taxa,
  comissão, duração ou valor cobrado.
- Filtros têm validação de tipo, enum, data e tamanho; paginação é server-side.

### 2.4 Rate limiting

| Fluxo | Limite | Chave |
|---|---|---|
| Login | 5 tentativas; lockout temporário | e-mail normalizado + IP |
| Mensagens de texto | 30/min (configurável) | usuário + consulta |
| Cartas no chat | 15/min (configurável) | usuário + consulta |
| Criação de consulta | 10/min | usuário autenticado |
| Criação de recarga | 10/min | usuário autenticado |
| Trabalhe conosco | 5 requisições/10 min | IP (middleware de rota) |
| Webhook PIX | 120/min | IP (proteção volumétrica) |

O formulário público também usa honeypot, validação server-side e bloqueio de
duplicidade por e-mail/status.

### 2.5 Uploads e imagens

- Aceitos apenas JPEG, PNG e WebP, com validação de arquivo, extensão, MIME real
  e limite configurável (`TAROT_IMAGE_MAX_KB`, padrão 5 MB).
- SVG, executáveis e arquivos cujo conteúdo não seja raster são rejeitados.
- O navegador nunca escolhe caminho: o servidor gera nome aleatório de 40
  caracteres e extensão a partir do MIME detectado.
- O disco é privado (`tarot_private`), sem `serve` público nem symlink em
  `public/`.
- A entrega passa por rota autorizada, com `X-Content-Type-Options: nosniff` e
  `Content-Disposition: inline`.
- Defesa em profundidade adicional: a rota recusa caminho nulo, `..` ou fora do
  diretório permitido, mesmo que o banco contenha um caminho inválido.
- Imagens de cartas enviadas no chat usam snapshot por consulta e
  `Cache-Control: private, no-store`; inativar/editar a carta original não
  altera o histórico.

### 2.6 Logs e segredos

- `.env` está no `.gitignore` e no `.dockerignore`; o Nginx nega dotfiles.
- Nenhum segredo é enviado em `VITE_*`; apenas a chave pública do Reverb chega ao
  navegador.
- Logs de webhook registram provedor e motivo, sem payload financeiro, PIX,
  assinatura, token ou PII.
- Auditoria registra IDs, estados, valores e motivos operacionais; não grava
  senha, token, conteúdo de conversa ou payload bruto de pagamento.
- O conteúdo PIX usa cast `encrypted` e nunca aparece na listagem administrativa.

### 2.7 Operações administrativas

- Ajuste de carteira exige admin ativo, tipo válido, valor positivo, motivo,
  UUID de operação e idempotência; gera lançamento imutável + auditoria.
- Taxa de comissão aceita somente 0–100%, com até duas casas, convertida para
  basis points por aritmética inteira; alteração gera auditoria.
- Comissão usa snapshot do aceite, é única por consulta e não pode ser editada
  ou apagada; estorno é lançamento separado com motivo e autor.
- Candidaturas só podem ser revisadas por admin; a aprovação **não** cria usuário,
  papel, perfil ou acesso de atendente.
- Catálogo e preços são exclusivos de admin e auditados.

### 2.8 Webhook, cookies e headers

- Webhook PIX exige HMAC SHA-256, timestamp dentro da tolerância e ID de evento
  único; status e valor são comparados com o pedido persistido.
- `payments` e `credit_ledger_entries` mudam na mesma transação; retries não
  duplicam crédito.
- Sessão usa `HttpOnly`, `SameSite=Lax` e serialização JSON. Em produção com
  HTTPS, configurar `SESSION_SECURE_COOKIE=true` e `SESSION_ENCRYPT=true`.
- Nginx envia `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`,
  `Permissions-Policy`, `Cross-Origin-Opener-Policy` e desabilita `server_tokens`.
- CSP estrita não foi adicionada nesta etapa porque a aplicação usa Blade/Vite e
  precisa de validação de compatibilidade antes de produção.


## 3. Acesso administrativo ao histórico de mensagens

O ambiente de atendimento continua fechado aos participantes: `viewMessages` e
`workspace` negam o acesso do administrador ao chat ao vivo. O painel ganhou uma
leitura administrativa separada, a habilidade `viewAdminHistory`, com estas
salvaguardas:

1. exclusiva de administrador ativo, em rota própria (`/admin/consultas/{id}`),
   sem reutilizar a rota dos participantes;
2. dado pessoal mascarado na exibição (CPF, CNPJ, e-mail, telefone, link e
   contato disfarçado), inclusive em mensagens gravadas antes do bloqueio;
3. auditoria de cada abertura (`consultation_history.viewed`), de cada imagem de
   carta lida (`consultation_history.card_image_viewed`) e de cada revisão de
   ocorrência (`consultation_privacy_incident.reviewed`), com IP;
4. somente leitura: não existe exportação em massa nem download do histórico;
5. o texto original de uma tentativa bloqueada fica cifrado no banco e não é
   exibido em nenhuma tela.

O bloqueio de contato externo no envio, o registro das tentativas e a tela de
monitoramento estão em
[MONITORAMENTO_E_PROTECAO_CHAT.md](MONITORAMENTO_E_PROTECAO_CHAT.md).

Ainda pendente de decisão do responsável de dados: base legal e finalidade
documentadas, justificativa por acesso e dupla autorização para casos
excepcionais. Não implementar esse fluxo como um simples `if admin` em rota
existente.

## 4. Proposta de retenção e minimização

A tabela abaixo é uma **proposta para decisão do responsável**, não uma política
jurídica validada. O MVP não executa expurgo automático. Qualquer rotina futura
deve ser testada, auditada e respeitar obrigações legais aplicáveis.

| Dados | Finalidade | Proposta inicial | Situação |
|---|---|---|---|
| Conta, nome, e-mail, papel e status | Autenticação e operação | Enquanto a conta existir + prazo legal/contratual a definir | Decisão pendente |
| Candidaturas e consentimento | Avaliação de profissional | 12 meses após decisão; rejeitadas podem ser anonimizadas antes | Decisão pendente |
| Consultas (participantes, status, horários, valores) | Prestação de contas e histórico | Prazo contratual/fiscal a definir com contabilidade | Decisão pendente |
| Mensagens de texto e snapshots de cartas | Execução da consulta e histórico do participante | 12 meses após o encerramento, salvo obrigação legal | Decisão pendente |
| Lançamentos de crédito e comissão | Integridade financeira e conciliação | Prazo fiscal/contábil aplicável, sem edição | Decisão pendente |
| Logs de auditoria | Rastreabilidade e prevenção a fraude | 24 meses, com controle de acesso | Decisão pendente |
| Logs técnicos | Segurança e diagnóstico | 30–90 dias | Decisão pendente |
| Backups | Recuperação de desastre | 30 dias, rotação e teste de restauração | Decisão pendente |
| Dados de pagamento | Conciliação | Apenas IDs, valor, status e hash; nunca payload bruto com PII | Implementado |

Regras de minimização já aplicadas:

- não há CPF, endereço, dados bancários ou cartão no fluxo PIX sandbox;
- o conteúdo PIX é criptografado e restrito ao dono;
- candidaturas não criam conta automaticamente;
- a comissão é interna e não gera pagamento;
- o diretório público expõe somente nome de exibição, bio, especialidades, foto
  e disponibilidade; nunca e-mail, comissão ou dados financeiros.

## 5. Direitos do titular e incidentes

- Solicitações de acesso, correção, portabilidade ou eliminação devem ser
  recebidas pelo canal de contato e triadas pelo responsável de dados.
- A exclusão pode ser limitada por obrigações financeiras/legais; nesse caso,
  aplicar minimização e registrar a justificativa.
- Incidentes devem seguir plano formal com contenção, avaliação de risco,
  comunicação e preservação de evidências. Esse plano ainda precisa ser
  aprovado pelo responsável.
- Mensagens e imagens não devem ser copiadas para planilhas, e-mails ou chats
  externos durante atendimento de suporte.

## 6. Dependências e verificações executadas

Em **2026-10-21**:

- `composer audit --locked`: nenhuma vulnerabilidade conhecida reportada.
- `npm audit --omit=dev`: nenhuma vulnerabilidade reportada nas dependências de
  produção.
- suíte completa no container: aprovada (ver seção 8).
- build de frontend: executado na validação final.
- Nenhuma varredura de infraestrutura, pentest, DAST ou teste de carga foi
  executado. Esses passos continuam recomendados antes de produção.

Em **2026-11-04** (revalidado após a exigência de PHP 8.4):

- **PHP**: `composer.json` passou a exigir `^8.4` e a imagem roda **8.4.27**
  (o container antigo estava em 8.4.26). Abaixo de **8.4.1** o
  `vendor/composer/platform_check.php` aborta a requisição — verificado
  executando o mesmo arquivo sob `php:8.3-cli-alpine`
  (`Composer detected issues in your platform ... require a PHP version ">= 8.4.1"`).
- **suíte completa** no container com PHP 8.4.27: **427 testes, 4350 asserções,
  aprovada**.
- **`composer audit` agora reporta 8 advisories em 5 pacotes** do Symfony
  (`http-foundation`, `http-kernel`, `mime`, `mailer`, `routing`, `yaml`), todos
  publicados entre 28/10/2026 e 04/11/2026 e todos **corrigidos nas versões
  8.1.10/8.1.11** — o lock está em 8.1.7–8.1.9. Entre eles: injeção de cabeçalho
  em e-mail (CVE-2026-45070), injeção de host em URLs geradas
  (GHSA-5mx8-mwrp-5693), `X-Forwarded-*` de origem não confiável
  (GHSA-wg5q-m2ww-f3c9), bypass de `security.access_control` por `HEAD`
  (CVE-2026-118514), match de host em rotas compiladas
  (CVE-2026-118516) e complexidade quadrática no parser YAML.
  **Pendência resolvida no mesmo dia**: `composer update "symfony/*"
  --with-dependencies` subiu os 20 pacotes afetados (patch, sem breaking),
  `composer audit` e `composer audit --no-dev` passaram a responder
  `No security vulnerability advisories found`, e a suíte completa continuou
  **427 testes / 4350 asserções** aprovada em PHP 8.4.27.


## 7. Riscos residuais e mitigações

| Risco | Impacto | Mitigação atual | Pendência |
|---|---|---|---|
| PIX real não implementado | Alto | Sandbox isolado, HMAC, valor comparado e idempotência; produção desabilita o mock | Escolher provedor, implementar adaptador e testar sandbox oficial |
| Termos e privacidade são provisórios | Alto | Páginas marcadas como documento provisório; consentimento na candidatura | Revisão jurídica e publicação definitiva |
| Retenção não automatizada | Médio/Alto | Dados minimizados, ledger imutável e acesso restrito | Aprovar prazos e implementar expurgo/anonimização auditada |
| Sem MFA para administradores | Alto | Senhas com hash, lockout de login, papéis e políticas | MFA e revisão periódica de acessos |
| Sem verificação de e-mail obrigatória | Médio | Cadastro público cria apenas cliente; atendente é criado por operador | Definir verificação e recuperação de conta |
| Sem WAF/proteção DDoS dedicada | Médio | Rate limiting, headers e reverse proxy | Camada de borda e monitoramento |
| Backup/restauração não testados nesta etapa | Alto | Runbook em `docs/DEPLOYMENT.md` | Executar teste real em ambiente isolado |
| WebSocket não testado em navegador real | Médio | Testes de canal/evento e fallback HTTP | Teste E2E com navegador e reconexão |
| Logs e auditoria sem SIEM | Médio | Escrita estruturada e tela restrita | Centralização, alertas e retenção |
| Dados de cartão/PIX reais fora do escopo | Alto | Nenhum dado real é tratado no sandbox | Revisão PCI/contrato do provedor antes de produção |
| CSP estrita ausente | Médio | Escaping, headers básicos e ausência de HTML do usuário | Definir CSP compatível com Vite/Blade |
| Acesso excepcional a mensagens inexistente | Médio | Admin não lê chat por padrão | Processo aprovado + auditoria, se necessário |

## 8. Resultado da revisão

Controles de autorização, isolamento, upload, snapshot, ledger, comissão,
webhook e auditoria foram verificados por código e testes. As correções desta
etapa incluíram:

- throttle dedicado para criação de consultas e recargas;
- validação defensiva de caminho das imagens de catálogo e de chat;
- `no-store` nas imagens privadas do chat;
- headers adicionais no Nginx;
- política administrativa explícita para pagamentos e auditoria;
- comando operacional para criação manual de atendente, sem vínculo automático
  com candidatura.

A suíte final foi executada no container e passou com **223 testes e 1.636
asserções**. O `npm run build` concluiu com sucesso. O Pint não reportou
alterações pendentes. A suíte não executa um navegador WebSocket real nem teste
de carga; essas lacunas estão listadas acima.

Esta revisão **não garante conformidade jurídica** e não substitui revisão de
privacidade, pentest ou validação do provedor de pagamento.

**Avaliação:** o MVP está adequado para demonstração controlada e ambiente de
homologação, com ressalvas. Não está pronto para produção enquanto PIX real,
documentos legais, MFA, backups testados e política de retenção não forem
fechados.

