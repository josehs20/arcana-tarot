# Monitoramento e proteção de dados no chat

Este documento descreve o bloqueio de dado pessoal e contato externo no chat, o
registro das tentativas recusadas e a tela administrativa que acompanha esses
casos. Presença, inatividade e encerramento continuam em
[SEGURANCA_E_USABILIDADE_CHAT.md](SEGURANCA_E_USABILIDADE_CHAT.md); a revisão
geral de segurança está em [SECURITY_PRIVACY.md](SECURITY_PRIVACY.md).

## 1. Princípios

- **A decisão é do servidor.** O aviso no navegador é conveniência; o bloqueio
  acontece em `ConsultationPrivacyGuard::enforce()`, chamado pelo manager de
  mensagens antes de qualquer persistência. Formulário HTML, chat em tempo real
  e requisição direta à rota passam pelo mesmo caminho.
- **A mensagem recusada não existe em `consultation_messages`.** O que fica é a
  ocorrência: metadados, trecho mascarado e o texto original cifrado.
- **A ocorrência é gravada fora da transação da mensagem.** O 422 dispara
  rollback; se o registro da tentativa estivesse dentro da mesma transação, o
  histórico de tentativas desapareceria justamente quando é mais necessário.
- **Falha ao registrar nunca libera o envio.** Se a gravação da ocorrência
  falhar, o erro é reportado e a mensagem continua bloqueada.
- **A administração lê mascarado por padrão.** Mensagens antigas, gravadas antes
  do bloqueio, também são analisadas na leitura: o painel exibe o texto com os
  dados sensíveis substituídos e informa o que foi mascarado.

## 2. O que é bloqueado

As categorias vêm de `App\Enums\SensitiveDataCategory` e alimentam a detecção, o
filtro do painel e os rótulos exibidos.

| Categoria | Valor | Exemplo bloqueado |
|---|---|---|
| CPF | `cpf` | `529.982.247-25`, `52998224725`, máscara `123.456.789-00` |
| CNPJ | `cnpj` | `11.222.333/0001-81`, `11222333000181` |
| E-mail | `email` | `maria.silva@gmail.com`, `maria [at] gmail [dot] com`, `maria arroba gmail ponto com` |
| Telefone | `phone` | `11 3456-7890`, `(11) 3456-7890` |
| WhatsApp | `whatsapp` | `11 98765-4321`, `+55 (11) 9 8765-4321`, `wa.me/5511999999999` |
| Link externo | `external_link` | `https://minha-loja.com.br`, `www.meusite.com` |
| Contato disfarçado | `hidden_contact` | `um um nove nove nove oito sete seis cinco quatro três` |

**O que não é bloqueado** (para não inviabilizar a conversa): valores monetários
(`R$ 50,00`), horários (`14:30`), datas (`12/03/1990`), durações (`30 minutos`),
listas curtas de números (`1, 2 e 3`) e códigos de até quatro dígitos.

## 3. Como a detecção funciona

`SensitiveDataDetector` roda sobre uma versão normalizada do texto:

1. **Normalização** — espaços repetidos são reduzidos; `[at]`, `(at)`, `{at}` e
   `arroba` viram `@`; `[dot]`, `(dot)`, `{dot}` e `ponto` entre letras viram
   `.`; espaços em volta de `@` e de pontos entre rótulos de domínio são
   removidos.
2. **Dígitos por extenso** — palavras de zero a nove intercaladas com números
   reais (`11 nove 8765 4321`) são convertidas preservando o tamanho em bytes,
   para não deslocar os intervalos usados no mascaramento.
3. **Grupos numéricos** — cada grupo de 10 a 14 dígitos é classificado:
   14 dígitos = CNPJ; 11 dígitos com máscara `000.000.000-00` ou dígitos
   verificadores válidos = CPF; caso contrário, o número é normalizado por
   `PhoneNumber::normalize()` (remove máscara e DDI `+55`) e validado por
   `PhoneNumber::isValid()`. Grupos de 10 a 14 dígitos que não passam na
   validação são ignorados — é o que evita bloquear preços, minutos e datas.
   Listas de números curtos separados por espaço (`10 20 30 40 50`) também são
   ignoradas, a menos que o grupo tenha máscara ou um bloco de três dígitos.
4. **Links e e-mails** — padrões explícitos para `http(s)://`, `www.`,
   domínios conhecidos (`wa.me`, `t.me`, `bit.ly`, redes sociais) e e-mails,
   aplicados depois da normalização.
5. **Contexto de contato** — termos como `whats`, `zap`, `telegram`, `me chama`,
   `meu número`, `telefone`, `link` e `site` só bloqueiam quando acompanhados de
   dígitos (ou seja, sozinhos não geram ocorrência).

O resultado é um `SensitiveDataDetection` com os intervalos encontrados, o texto
mascarado (`maskedText`), o trecho mascarado para o painel (`maskedExcerpt`), os
rótulos detectados (`labels()`) e a categoria principal (`primaryCategory()`).

O mascaramento mantém o formato e esconde o conteúdo: `(11) 98765-4321` aparece
como `(11) *****-4321`, `529.982.247-25` como `529.***.***-25` e
`maria.silva@gmail.com` como `m***@g***.com`.

## 4. Fluxo do bloqueio

1. O navegador envia `POST /consultas/{id}/mensagens` (`consultations.messages.store`)
   com apenas o campo `body`.
2. `StoreConsultationMessageRequest` valida presença, tamanho e tipo do texto. O
   remetente nunca vem do formulário: é sempre `$request->user()`.
3. `ConsultationMessageManager::sendText()` chama
   `ConsultationPrivacyGuard::enforce()` **antes** de abrir a transação da
   mensagem.
4. O guard decide:
   - sem detecção → o texto segue para a transação e é persistido normalmente;
   - detecção + remetente que não participa da consulta ou está inativo → 403,
     sem registrar ocorrência (não há tentativa legítima a auditar);
   - detecção + participante ativo → grava a ocorrência em
     `consultation_privacy_incidents` e lança `ValidationException` no campo
     `body`.
5. A resposta é 422 para requisições JSON e redirecionamento com erro de
   validação para o formulário HTML. A mensagem não é criada; a ocorrência
   permanece.
6. Depois do bloqueio a consulta continua utilizável: o participante pode
   enviar outro texto imediatamente.

Cartas de tarô seguem outro caminho (`POST /consultas/{id}/cartas`,
`consultations.cards.store`): só o atendente envia, o corpo é apenas o
`tarot_card_id` e a imagem é copiada para um diretório privado pelo servidor.
Não existe texto livre nessa rota, então não há como gravar contato por ela.

## 5. Tabela `consultation_privacy_incidents`

| Coluna | Uso |
|---|---|
| `consultation_id` | Consulta em que a tentativa aconteceu (`cascadeOnDelete`). |
| `user_id` | Remetente da tentativa (`nullOnDelete`, preserva o histórico). |
| `category` | Categoria principal detectada (`SensitiveDataCategory`). |
| `status` | `blocked` (inicial) ou `reviewed`. |
| `detected_types` | JSON com todas as categorias, rótulos e valores mascarados. |
| `masked_excerpt` | Trecho do texto com os dados substituídos (limite de 500). |
| `body_original` | Texto completo, **cifrado** pelo cast `encrypted` (APP_KEY). |
| `reviewed_by` / `reviewed_at` / `review_notes` | Revisão administrativa. |
| `ip_address` | IP da requisição que tentou o envio. |

Índices: `(consultation_id, status, created_at)`, `(consultation_id, id)` e
`category` — os dois primeiros cobrem a linha do tempo e os contadores do
painel; o terceiro cobre o filtro por tipo de dado.

O texto original existe para eventual necessidade de análise e não é exibido em
nenhuma tela. Sem a `APP_KEY` correta ele é ilegível; ao trocar a chave, os
registros antigos deixam de ser decifráveis (mesmo comportamento das demais
colunas cifradas da aplicação).

## 6. Painel administrativo

### 6.1 Lista de consultas (`GET /admin/consultas`)

- Filtros: `q` (nome/e-mail do cliente ou atendente), `status`, `modality`,
  `incidents` (`with`/`without`) e `incident_type` (`SensitiveDataCategory`).
  Valores fora do enum voltam como erro de validação, sem consulta ao banco.
- A coluna **Ocorrências** mostra o total e, quando houver, quantas continuam em
  aberto (`status = blocked`), em tom de alerta.
- Cada linha tem a ação **Visualizar**, que abre o detalhe administrativo.
- A paginação preserva os filtros (`withQueryString()`).

### 6.2 Detalhe da consulta (`GET /admin/consultas/{id}`)

A tela é exclusiva de administrador ativo (`viewAdminHistory`) e mostra:

- cartões de duração, total cobrado, comissão líquida e ocorrências;
- participantes com status da conta e do atendimento;
- tabela **Ocorrências** (`#ocorrencias`) com data, autor, categoria, tipos
  detectados, situação e trecho mascarado;
- linha do tempo do histórico, do mais recente para o mais antigo, misturando
  mensagens e ocorrências.

Cada abertura da tela grava `audit_logs.action = consultation_history.viewed`
com `is_live`, total de mensagens, total de ocorrências e IP. A leitura da
imagem da carta (`GET /admin/consultas/{id}/mensagens/{message}/imagem`) é uma
rota própria, autorizada pela mesma política e auditada como
`consultation_history.card_image_viewed`.

As mensagens da linha do tempo passam pelo detector na leitura: textos gravados
antes do bloqueio aparecem mascarados e com a indicação dos tipos encontrados.
O trecho mascarado da ocorrência é exibido como está salvo — o painel nunca
recebe o texto original.

### 6.3 Atualização automática

`GET /admin/consultas/{id}/historico` (`admin.consultations.entries`) devolve
somente o que veio depois dos cursores:

| Parâmetro | Uso |
|---|---|
| `after_message_id` | Maior id de mensagem já exibido. |
| `after_incident_id` | Maior id de ocorrência já exibido. |

A resposta traz `html` (renderizado pelo mesmo partial da página), os novos
cursores (`last_message_id`, `last_incident_id`), `has_more` (quando algum dos
lados atingiu o limite de 100 itens) e o estado atual da consulta.

- Limite por requisição: 100 mensagens e 100 ocorrências.
- Throttle `admin-consultation-history`: `panel_feed_rate_per_minute` (60/min)
  por administrador.
- `resources/js/admin-consultation-history.js` só roda quando a consulta está
  ativa (`data-history-live="1"`), respeita o intervalo configurado, aumenta o
  intervalo com a aba oculta e aplica backoff em caso de erro.
- 401, 403 e 404 interrompem o polling e mostram **Atualização interrompida**:
  sessão expirada ou acesso revogado não devem gerar requisições em série.
- WebSocket não é usado aqui de propósito. A leitura administrativa precisa
  passar pela política e por requisições HTTP autenticadas; o polling mantém o
  caminho de autorização igual ao da abertura da página.

O polling registra apenas os itens novos: a auditoria da sessão de leitura fica
na abertura da tela, e não em cada requisição automática, para não encher
`audit_logs` com um registro a cada 3 segundos.

### 6.4 Revisão da ocorrência

`PATCH /admin/consultas/{id}/ocorrencias/{incident}`
(`admin.consultations.incidents.review`) grava `status = reviewed`,
`reviewed_by`, `reviewed_at` e `review_notes` (até 500 caracteres) e audita
`consultation_privacy_incident.reviewed` com `incident_id`, `category` e IP. A
ocorrência precisa pertencer à consulta da URL; caso contrário a resposta é 404.

O formulário fica na própria linha do tempo e só aparece enquanto a ocorrência
está em aberto. Reenviar a revisão de uma ocorrência já revisada atualiza a
observação e o revisor — o histórico de auditoria guarda cada envio.

## 7. Auditoria

| Ação | Quando é gravada | Metadados |
|---|---|---|
| `consultation_history.viewed` | Abertura do detalhe administrativo. | `is_live`, `messages`, `privacy_incidents`, `ip` |
| `consultation_history.card_image_viewed` | Leitura da imagem de uma carta no painel. | `message_id`, `ip` |
| `consultation_privacy_incident.reviewed` | Revisão de uma ocorrência. | `incident_id`, `category`, `ip` |

A auditoria de leitura usa `subject_type`/`subject_id` da consulta, então é
possível listar tudo o que foi acessado de uma consulta específica. Nenhuma das
ações grava conteúdo de conversa: o texto original só existe cifrado na
ocorrência.

## 8. Configuração

Nenhuma variável nova é obrigatória. Os limites reaproveitam chaves existentes:

| Chave | Padrão | Efeito |
|---|---|---|
| `consultation.message_page_size` (`CONSULTATION_MESSAGE_PAGE_SIZE`) | 25 | Itens por página no histórico. |
| `consultation.message_rate_per_minute` (`CONSULTATION_MESSAGE_RATE_PER_MINUTE`) | 30 | Envios de texto por minuto por participante. |
| `consultation.card_rate_per_minute` (`CONSULTATION_CARD_RATE_PER_MINUTE`) | 15 | Envios de carta por minuto por atendente. |
| `consultation.realtime.panel_feed_rate_per_minute` (`CONSULTATION_PANEL_FEED_RATE_PER_MINUTE`) | 60 | Leituras automáticas do histórico por administrador. |
| `consultation.realtime.poll.panel_interval_ms` (`CONSULTATION_POLL_PANEL_MS`) | 3000 | Intervalo do polling com a aba visível. |
| `consultation.realtime.poll.panel_hidden_interval_ms` (`CONSULTATION_POLL_PANEL_HIDDEN_MS`) | 15000 | Intervalo com a aba oculta. |
| `consultation.realtime.poll.max_backoff_ms` (`CONSULTATION_POLL_MAX_BACKOFF_MS`) | 30000 | Backoff máximo em caso de erro. |

Se `panel_feed_rate_per_minute` for menor que 20, o polling de 3 segundos passa a
receber 429: ajuste o intervalo junto com o limite. Os valores são travados por
piso (`max()`): o limite de feed nunca fica abaixo de 10 por minuto, o intervalo
visível abaixo de 1 s, o da aba oculta abaixo de 3 s e o backoff abaixo de 5 s.

## 9. Cobertura de testes

| Arquivo | Cobre |
|---|---|
| `tests/Feature/ChatPrivacyDetectionTest.php` | CPF/CNPJ válidos e mascarados, e-mail (inclusive disfarces), telefone/WhatsApp com DDI, links, dígitos por extenso, ausência de falso positivo em preço/horário/data/lista e o formato do texto mascarado. |
| `tests/Feature/ChatPrivacyEnforcementTest.php` | Bloqueio no formulário HTML e em requisição JSON, tentativa registrada com texto cifrado, impossibilidade de burlar enviando `sender_id`/`type`/`created_at`, mensagem limpa persistida, cartas intactas, terceiros sem acesso e conversa funcionando após o bloqueio. |
| `tests/Feature/AdminConsultationPrivacyTest.php` | Filtros `incidents`/`incident_type`, histórico mascarado, auditoria da abertura e da imagem, cursores do endpoint de histórico, revisão auditada, 404 entre consultas e bloqueio de cliente/atendente/administrador inativo. |

Execução:

```bash
docker compose exec app php artisan test --compact \
  tests/Feature/ChatPrivacyDetectionTest.php \
  tests/Feature/ChatPrivacyEnforcementTest.php \
  tests/Feature/AdminConsultationPrivacyTest.php
```

## 10. Operação

Ocorrências em aberto de uma consulta:

```sql
select id, category, status, masked_excerpt, created_at
from consultation_privacy_incidents
where consultation_id = ? and status = 'blocked'
order by id;
```

Ranking de tentativas por categoria nos últimos 30 dias:

```sql
select category, count(*) as total
from consultation_privacy_incidents
where created_at >= now() - interval 30 day
group by category
order by total desc;
```

Acessos administrativos ao histórico de uma consulta:

```sql
select created_at, actor_id, action, metadata
from audit_logs
where subject_type = 'App\\Models\\Consultation'
  and subject_id = ?
  and action like 'consultation_history.%'
order by id desc;
```

Consulta com muitas ocorrências abertas costuma indicar insistência em levar a
conversa para fora da plataforma. O painel mostra o contador na lista e o total
em aberto no detalhe.

## 11. Limitações conhecidas

- **A detecção é heurística.** Ela cobre formatos comuns, não todos: números
  separados com outras palavras no meio, contatos em imagem ou áudio e domínios
  fora das listas podem passar. O bloqueio reduz o risco; não substitui a
  orientação de uso e a revisão humana.
- **Não há alerta em tempo real para a administração.** A tela é atualizada por
  polling apenas enquanto estiver aberta; não existe notificação, e-mail ou push
  quando uma tentativa é bloqueada.
- **A ocorrência guarda o texto original cifrado.** É necessário para auditoria,
  mas significa que o dado pessoal recusado ainda existe no banco até a consulta
  ser excluída — a remoção da consulta apaga as ocorrências por cascata.
- **O polling depende da consulta ativa.** Consulta encerrada abre em modo
  somente leitura; novas linhas só aparecem ao recarregar a página (não há mais
  envios depois do estado final).
- **O painel não revela o texto original.** Não existe botão de "ver original";
  a análise detalhada depende do banco e da `APP_KEY`.
- **Rate limit compartilhado.** Todas as abas do mesmo administrador dividem a
  cota de 60 leituras por minuto do histórico.

